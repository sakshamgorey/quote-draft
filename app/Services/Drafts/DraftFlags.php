<?php

namespace App\Services\Drafts;

use App\Models\Draft;
use App\Models\DraftLine;
use App\Models\DraftLineOption;
use Illuminate\Support\Carbon;

/**
 * Guardrail flags on a draft, computed by code from trusted data and the
 * validated notes. Nothing here is decided by the AI.
 *
 *   blocking — finalize is disabled until it's fixed
 *   warning  — needs a human look, doesn't block
 *   info     — context for the salesperson; never reaches the customer
 */
class DraftFlags
{
    // Instruction-like text in the notes (pasted emails, "ignore…", "approve…").
    private const INJECTION = '/\b(ignore (all|any|the|previous|prior|above)\b|disregard (the|all|previous)|system prompt|you are (now|an? )|new instructions|approve (this|the) quote|mark (it|this|the quote) (as )?(approved|final)|send (the|this) quote|set (the )?price to|override)/i';

    private const APPROXIMATE = '/(\b(around|approx(imately)?|about|roughly|circa|or so)\b|~|\d\s*-?ish\b)/i';

    private const OVERALL = '/\b(overall|outside|o\.?d\.?|frame size|with (the )?flange|w\/\s?flange|incl(\.|uding)? (the )?flange)\b/i';

    private const NAME_STOPWORDS = ['inc', 'llc', 'ltd', 'co', 'corp', 'company', 'the', 'and', 'for', 'group', 'construction', 'builders', 'contractors', 'district', 'school', 'services'];

    /**
     * @param  array<int, array>  $lines  presented lines (DraftPresenter::line)
     * @param  array<int, DraftLine>  $models  keyed by line id
     * @return list<array{code: string, severity: string, message: string, line_id: ?int, quote: ?string, action: ?array}>
     */
    public function for(Draft $draft, array $lines, array $models, int $subtotalCents): array
    {
        $open = ! $draft->isFinalized();
        $notes = $draft->notes ?? [];
        $flags = [];

        if ($open) {
            array_push($flags, ...$this->account($draft, $notes));
            array_push($flags, ...$this->discount($draft, $notes));
        }

        if (preg_match(self::INJECTION, $draft->message, $m)) {
            $flags[] = $this->flag('instructions_in_notes', 'warning',
                'The notes contain instruction-like text. The AI treats notes as data only; double-check products, prices and the discount.', quote: trim($m[0]));
        }

        if ($open) {
            foreach ($lines as $line) {
                array_push($flags, ...$this->line($line, $models[$line['id']] ?? null));
            }

            array_push($flags, ...$this->prices($models));

            if ($subtotalCents > config('pricing.large_total_cents')) {
                $flags[] = $this->flag('large_total', 'warning', 'Large quote total. Double-check quantities and products before sending.');
            }
        }

        // Context that must stay with the salesperson, never in the customer quote.
        if (($notes['delivery_request'] ?? null) !== null) {
            $flags[] = $this->flag('delivery_request', 'info', 'Delivery request: not checked against lead times and not promised in the quote.', quote: $notes['delivery_request']);
        }
        foreach ($notes['internal_notes'] ?? [] as $note) {
            $flags[] = $this->flag('internal_note', 'info', 'Internal note: kept out of the customer quote.', quote: $note);
        }
        foreach ($notes['follow_ups'] ?? [] as $todo) {
            $flags[] = $this->flag('follow_up', 'info', 'Follow up before sending.', quote: $todo);
        }

        // Most urgent first.
        $rank = ['blocking' => 0, 'warning' => 1, 'info' => 2];
        usort($flags, fn ($a, $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);

        return $flags;
    }

    private function account(Draft $draft, array $notes): array
    {
        if (! $draft->account) {
            return [$this->flag('no_account', 'warning', 'No customer account: list prices only, no contract discount or ship-to address.')];
        }

        $reference = $notes['customer_reference'] ?? null;
        if ($reference === null || str_contains($reference, '[CONTACT_')) {
            return []; // a masked contact of this account, or nothing to compare
        }

        // Ship-to counts too: notes often name the job site ("Maple St MOB" → "Maple Street Medical Office").
        $known = $this->words(implode(' ', [
            $draft->account->name, $draft->account->ship_to ?? '', ...array_column($draft->account->contacts ?? [], 'name'),
        ]));
        $mentioned = $this->words($reference);

        if ($mentioned !== [] && array_intersect($mentioned, $known) === []) {
            return [$this->flag('customer_mismatch', 'warning',
                "The notes seem to be for someone else than {$draft->account->name}. Check the account.", quote: $reference)];
        }

        return [];
    }

    private function discount(Draft $draft, array $notes): array
    {
        $flags = [];
        $max = $draft->maxDiscountPct();
        $current = (float) ($draft->discount_pct ?? 0);
        $contract = (float) ($draft->account?->discount_pct ?? 0);
        $whose = $draft->account ? "for {$draft->account->name}" : 'without an account';

        if ($current > $max) {
            $flags[] = $this->flag('discount_over_max', 'blocking',
                "Quote discount {$this->pct($current)} is above the {$this->pct($max)} maximum {$whose}. Lower it to finalize.");
        }

        if ($requested = $notes['requested_discount'] ?? null) {
            $pct = (float) $requested['value_pct'];

            $flags[] = match (true) {
                $pct > $max => $this->flag('discount_request_over_max', 'warning',
                    "Notes ask for {$this->pct($pct)}, above the {$this->pct($max)} maximum {$whose}. Not applied.", quote: $requested['quote']),
                $pct <= $contract => $this->flag('discount_request_covered', 'info',
                    "Notes ask for {$this->pct($pct)}: covered by the {$this->pct($contract)} contract discount.", quote: $requested['quote']),
                abs($current - $pct) < 0.001 => $this->flag('discount_request_applied', 'info',
                    "Requested {$this->pct($pct)} is applied as the quote discount.", quote: $requested['quote']),
                default => $this->flag('discount_requested', 'warning',
                    "Notes ask for {$this->pct($pct)}. Not applied yet (allowed up to {$this->pct($max)}).",
                    quote: $requested['quote'], action: ['type' => 'apply_discount', 'value' => $pct]),
            };
        }

        return $flags;
    }

    private function line(array $line, ?DraftLine $model): array
    {
        $flags = [];
        $n = "Line {$line['number']}";

        if ($line['selected'] && $model?->product) {
            foreach ($line['selected']['checks'] as $check) {
                if ($check['verdict'] === ProductFit::NO) {
                    $flags[] = $this->flag('product_conflict', 'blocking',
                        "{$n}: {$model->product->sku} conflicts with {$check['label']} {$check['value']}. Catalog: "
                        .($check['evidence'] ?: 'a different specification').'. Correct the requirement or choose another product.',
                        $line['id'], $model->specs[$check['spec']]['quote'] ?? null);
                }
            }
        }

        // Several products fit the notes equally: none is preselected, the salesperson picks.
        if (($line['shortlist_count'] ?? 0) > 1) {
            $flags[] = $this->flag('pick_needed', 'warning',
                "{$n}: {$line['shortlist_count']} products fit the notes equally well, so none is preselected. Pick one.", $line['id']);
        }

        if ($line['selected'] && $model?->product && ! $model->product->is_available) {
            $flags[] = $this->flag('unavailable', 'blocking', "{$n}: this product is no longer available on the store. Pick another.", $line['id']);
        }

        if ($line['status'] === 'ready' && $line['unit_price_cents'] === null) {
            $flags[] = $this->flag('price_on_request', 'warning', "{$n}: no catalog price (price on request). Get a price before sending.", $line['id']);
        }

        array_push($flags, ...$this->options($line, $n));

        foreach ($line['unknown_names'] as $name) {
            $flags[] = $this->flag('name_not_in_catalog', 'warning',
                "{$n}: the notes name {$name['label']} “{$name['value']}”, which we don't carry. Matched on the other details; check an equivalent is OK.", $line['id'], $name['quote']);
        }

        if ($line['quantity'] > config('pricing.large_quantity')) {
            $flags[] = $this->flag('large_quantity', 'warning', "{$n}: unusually large quantity ({$line['quantity']}). Confirm it.", $line['id']);
        }

        // Only sizes that came from the notes: a size the salesperson typed is already confirmed.
        $sizeQuote = collect($line['specs'])->firstWhere('key', 'size')['quote'] ?? null;
        if ($sizeQuote !== null) {
            $context = $sizeQuote.' '.$line['quote'];

            if (preg_match(self::APPROXIMATE, $context)) {
                $flags[] = $this->flag('approximate_size', 'warning', "{$n}: the size was approximate. Confirm the exact opening.", $line['id'], $line['quote']);
            }
            if (preg_match(self::OVERALL, $context)) {
                $flags[] = $this->flag('overall_size', 'warning',
                    "{$n}: the notes may give the overall size including the flange. Products are matched on the opening size; confirm.", $line['id'], $line['quote']);
            }
        }

        return $flags;
    }

    /** Options on the chosen product: required ones answered, prices known, notes' wishes accounted for. */
    private function options(array $line, string $n): array
    {
        if (! $line['selected']) {
            return [];
        }

        $flags = [];
        $money = fn (?int $c) => $c ? sprintf(' (+$%s)', number_format($c / 100, 2)) : '';

        foreach ($line['option_groups'] as $group) {
            $pick = $group['selected'];

            // The color chosen on another line is offered here too: one click to match it.
            if ($match = $group['color_match'] ?? null) {
                $flags[] = $this->flag('color_match', 'info',
                    "{$n}: “{$match['name']}”{$money($match['price_delta_cents'])} matches the color chosen on line ".implode(', ', $match['same_as'])." ({$group['label']}).",
                    $line['id'], action: ['type' => 'choose_option', 'value' => ['store_option_id' => $group['store_option_id'], 'store_value_id' => $match['store_value_id']]]);
            }

            if ($pick === null && $group['required']) {
                $flags[] = $this->flag('option_missing', 'blocking', "{$n}: pick a choice for “{$group['label']}”.", $line['id']);

                continue;
            }
            if ($pick === null) {
                continue;
            }
            if ($pick['gone']) {
                $flags[] = $this->flag('option_gone', 'blocking', "{$n}: “{$pick['name']}” is no longer offered for “{$group['label']}”. Pick again.", $line['id']);
            } elseif ($pick['price_delta_cents'] === null) {
                $flags[] = $this->flag('option_price_unknown', 'blocking', "{$n}: the store's price for “{$pick['name']}” couldn't be read. Pick another choice or check the store.", $line['id']);
            } elseif ($pick['needs_review']) {
                $flags[] = $this->flag('option_needs_review', 'warning', "{$n}: the store lists “{$pick['name']}” at more than one price. Check it on the product page.", $line['id']);
            }
            if ($pick['source'] === 'notes') {
                $flags[] = $this->flag('option_from_notes', 'info',
                    "{$n}: {$group['label']} set from the notes to “{$pick['name']}”{$money($pick['price_delta_cents'])}. Check it.", $line['id'], $pick['quote']);
            } elseif ($pick['source'] === DraftLineOption::ASSUMED) {
                $flags[] = $this->flag('option_assumed', 'warning',
                    "{$n}: the store marks no standard choice for “{$group['label']}”, so the lowest-priced one, “{$pick['name']}”, is preselected. Check it.", $line['id']);
            }
        }

        foreach ($line['unmatched_option_requests'] as $request) {
            $suggested = collect($line['suggestions'])->contains('quote', $request['quote']);
            $flags[] = $this->flag('option_unmatched', 'warning', "{$n}: the notes ask for “{$request['text']}”, but this product has no such option. "
                .($suggested ? 'A matching add-on is suggested below.' : 'Pick another product or note it separately.'), $line['id'], $request['quote']);
        }

        if ($line['options_lead_time']) {
            $flags[] = $this->flag('option_lead_time', 'info', "{$n}: the chosen options add lead time ({$line['options_lead_time']}). Not promised in the quote.", $line['id']);
        }

        return $flags;
    }

    /** @param  array<int, DraftLine>  $models */
    private function prices(array $models): array
    {
        $cutoff = now()->subDays((int) config('pricing.stale_after_days'));
        $stale = collect($models)->map(fn ($m) => $m->product?->priced_at)->filter(fn (?Carbon $at) => $at && $at->lt($cutoff));

        if ($stale->isEmpty()) {
            return [];
        }

        return [$this->flag('stale_prices', 'warning', sprintf(
            '%d %s older than %d days (oldest %s). Refresh with `php artisan catalog:import --fresh` before sending.',
            $stale->count(), $stale->count() === 1 ? 'price is' : 'prices are', config('pricing.stale_after_days'), $stale->min()->format('M j'),
        ))];
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        preg_match_all('/\p{L}{3,}/u', mb_strtolower($text), $m);

        return array_values(array_diff(array_unique($m[0]), self::NAME_STOPWORDS));
    }

    private function pct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').'%';
    }

    private function flag(string $code, string $severity, string $message, ?int $lineId = null, ?string $quote = null, ?array $action = null): array
    {
        return ['code' => $code, 'severity' => $severity, 'message' => $message, 'line_id' => $lineId, 'quote' => $quote, 'action' => $action];
    }
}
