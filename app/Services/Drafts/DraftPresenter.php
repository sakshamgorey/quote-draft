<?php

namespace App\Services\Drafts;

use App\Models\Draft;
use App\Models\DraftLine;
use App\Models\DraftLineOption;
use App\Models\Product;
use App\Services\Pricing\PriceCalculator;
use App\Services\Zoho\ZohoQuoteRequest;
use Illuminate\Support\Collection;

/**
 * Shapes a saved draft for the review page (resources/js/types Draft*).
 *
 * Open drafts are re-matched and re-priced on every view (Postgres + PHP,
 * milliseconds), so options, prices, questions and flags always reflect the
 * current specs, account and rules. Finalized drafts show their frozen snapshot.
 */
class DraftPresenter
{
    private const SPEC_LABELS = [
        'quantity' => 'Qty',
        'size' => 'Opening',
        'fire_rated' => 'Fire-rated',
        'insulated' => 'Insulated',
        'application' => 'Application',
        'surface_type' => 'Surface',
        'material' => 'Material',
        'flange_type' => 'Flange',
        'load_psf' => 'Load',
        'load_lbs' => 'Capacity',
        'brand' => 'Brand',
        'model' => 'Model',
    ];

    public function __construct(
        private ProductMatcher $matcher,
        private DraftFlags $flags,
        private ProductFit $fit,
        private ZohoQuoteRequest $zoho,
        private OptionSelector $options,
        private AccessorySuggester $accessories,
    ) {}

    public function show(Draft $draft): array
    {
        $draft->loadMissing('lines.product.options.values', 'lines.product.page', 'lines.chosenOptions', 'account', 'zohoSubmission');
        $prices = PriceCalculator::for($draft->account, $draft->discount_pct);
        $models = $draft->lines->keyBy('id')->all();
        $onDraft = $draft->lines->pluck('product_id')->filter()->values()->all();
        // Products the AI suggested, still on sale (open drafts only).
        $aiProducts = $draft->isFinalized() ? collect() : Product::query()->with('page')->where('is_available', true)
            ->whereIn('id', $draft->lines->flatMap(fn (DraftLine $l) => array_column($l->ai_suggestions ?? [], 'product_id')))
            ->get()->keyBy('id');
        // Line numbers as the page shows them (positions keep gaps after a line is removed).
        $numbers = $draft->lines->values()->mapWithKeys(fn (DraftLine $l, int $i) => [$l->id => $i + 1])->all();
        $colors = $this->chosenColors($draft, $numbers);
        $lines = $draft->lines->map(fn (DraftLine $line) => $this->line($draft, $line, $prices, $onDraft, $aiProducts, $numbers, $colors))->all();

        // Keep selected products priced while contradictory requirements are resolved.
        $ready = array_filter($lines, fn ($l) => $l['selected'] !== null);
        $unresolved = array_filter($lines, fn ($l) => $l['status'] !== 'ready');
        $subtotal = array_sum(array_map(fn ($l) => $l['line_total_cents'] ?? 0, $ready));
        // Discounts apply to the base product only, so savings are list − base.
        $savings = array_sum(array_map(fn ($l) => $l['list_price_cents'] !== null && $l['base_price_cents'] !== null
            ? ($l['list_price_cents'] - $l['base_price_cents']) * $l['quantity'] : 0, $ready));
        $pricedAt = $draft->isFinalized() ? $draft->finalized_at : Product::max('priced_at');
        $flags = $this->flags->for($draft, $lines, $models, $subtotal);
        $blocking = array_filter($flags, fn ($f) => $f['severity'] === 'blocking');

        $presented = [
            'ulid' => $draft->ulid,
            'status' => $draft->status,
            'message' => $draft->message,
            'redacted_message' => $draft->redacted_message,
            'model' => $draft->model,
            'created_at' => $draft->created_at->toIso8601String(),
            'finalized_at' => $draft->finalized_at?->toIso8601String(),
            'account' => $draft->isFinalized() ? $draft->account_snapshot : $draft->account?->summary(),
            'discount_pct' => $draft->discount_pct,
            'max_discount_pct' => $draft->maxDiscountPct(),
            'lines' => $lines,
            'flags' => $flags,
            'totals' => [
                'subtotal_cents' => $subtotal,
                'savings_cents' => $savings,
                'ready' => count($ready),
                'lines' => count($lines),
                'unresolved' => count($unresolved),
                'unpriced' => count(array_filter($ready, fn ($l) => $l['unit_price_cents'] === null)),
                'blocking' => count($blocking),
            ],
            'prices_as_of' => $pricedAt ? date('F j, Y', strtotime((string) $pricedAt)) : null,
            'can_finalize' => ! $draft->isFinalized() && count($lines) > 0 && count($unresolved) === 0 && $blocking === [],
            'ai_suggestions' => [
                'enabled' => AiSuggester::enabled(),
                'status' => $draft->ai_suggestions_status,
                'model' => $draft->ai_suggestions_model,
                'error' => $draft->ai_suggestions_error,
                'at' => $draft->ai_suggested_at?->toIso8601String(),
            ],
        ];

        // Exactly what goes to Zoho: the recorded request once sent, otherwise built live.
        $sent = $draft->zohoSubmission;
        $presented['zoho'] = [
            'mode' => $sent?->mode ?? config('zoho.mode'),
            'request' => $sent?->request ?? $this->zoho->build($presented),
            'response' => $sent?->response,
            'record_id' => $sent?->zoho_record_id,
            'sent_at' => $sent?->sent_at?->toIso8601String(),
            'unverified_fields' => config('zoho.unverified_fields'),
        ];
        $presented['can_send'] = ! $sent && ($presented['can_finalize'] || ($draft->isFinalized() && $unresolved === []));

        return $presented;
    }

    /**
     * @param  list<int>  $onDraft  products already on the draft: never suggested again
     * @param  Collection<int, Product>  $aiProducts  keyed by id
     * @param  array<int, int>  $numbers  line id => number shown
     * @param  list<array{line_id: int, number: int, name: string}>  $colors  colors chosen on purpose, per line
     */
    private function line(Draft $draft, DraftLine $line, PriceCalculator $prices, array $onDraft, Collection $aiProducts, array $numbers, array $colors): array
    {
        $item = $line->requestedItem();
        $finalized = $draft->isFinalized();
        // Finalized lines are frozen; no re-matching.
        $match = $finalized ? null : $this->matcher->match($item);

        $options = $match && ! $finalized ? $match['products'] : collect();
        if (! $finalized && $line->product && ! $options->contains('id', $line->product_id)) {
            $options->prepend($line->product); // chosen via search / manual line
        }

        if (! $finalized && $line->product) {
            $selectedValueIds = $line->product->options->flatMap(fn ($option) => $option->values->filter(
                fn ($value) => $line->chosenOptions->contains(fn ($chosen) => $chosen->store_option_id === $option->store_option_id
                    && $chosen->store_value_id === $value->store_value_id)
            )->pluck('id'))->all();
            $titleChecks = array_filter($match['fits'][$line->product_id]['checks'] ?? [], fn ($check) => $check['spec'] === 'title');
            $match['fits'][$line->product_id] = $this->fit->check($item, $line->product, $selectedValueIds);
            array_push($match['fits'][$line->product_id]['checks'], ...$titleChecks);
        }
        $conflicts = $line->product && ! $finalized
            ? collect($match['fits'][$line->product_id]['checks'])->where('verdict', ProductFit::NO)->values()
            : collect();

        // Which group each option is in, and how it measures up to each stated detail.
        $bestIds = $match ? $match['best']->pluck('id')->flip() : collect();
        $fitFor = fn (Product $p) => [
            'group' => ($match['fits'][$p->id]['no'] ?? 0) > 0 ? 'also' : ($bestIds->has($p->id) || ! isset($match['fits'][$p->id]) ? 'best' : 'also'),
            'checks' => $match['fits'][$p->id]['checks'] ?? [],
            // What sets equal matches apart ("Drywall Flange for drywall", "Store bestseller").
            'tags' => $match['shortlist']['tags'][$p->id] ?? [],
        ];

        $status = match (true) {
            $conflicts->isNotEmpty() => 'needs_clarification',
            $line->product_id !== null || ($finalized && $line->product_sku) => 'ready',
            $finalized => 'no_match', // finalized before every line needed a product
            $match['status'] === 'matched' => 'choose',
            $match['status'] === 'needs_info' => 'needs_details',
            default => 'no_match',
        };

        $selected = $finalized
            ? ($line->product_sku ? [
                'id' => null, 'sku' => $line->product_sku, 'name' => $line->product_name, 'url' => $line->product_url,
                // Drafts finalized before images were snapshotted fall back to the live product.
                'image_url' => $line->product_image_url ?? $line->product?->image_url, 'size' => $line->product_size,
                'price_cents' => $this->cents($line->unit_price), 'list_price_cents' => $this->cents($line->list_price ?? $line->unit_price),
                'discount_pct' => $line->discount_pct, 'discount_label' => $line->discount_label,
            ] : null)
            : ($line->product ? $this->option($line->product, $line->quantity, $prices) + ['description' => $line->product->page?->description] : null);

        // Price = (base − discount) + chosen options. Options are never discounted.
        $groups = $this->optionGroups($line, $finalized, $colors);
        $optionsCents = $finalized
            ? ($this->cents($line->options_price) ?? 0)
            : array_sum(array_map(fn ($g) => $g['selected']['price_delta_cents'] ?? 0, $groups));
        $base = $selected['price_cents'] ?? null;
        $unit = $base === null ? null : $base + $optionsCents;
        $leadDays = max([0, ...array_map(fn ($g) => $g['selected']['lead_time_days'] ?? 0, $groups)]);
        $unmatched = ! $finalized && $line->product ? $this->options->unmatched($line, $line->product) : [];
        $suggestions = ! $finalized && $line->product ? $this->accessories->for($line->product, $unmatched, $onDraft) : [];

        return [
            'id' => $line->id,
            'position' => $line->position,
            'number' => $numbers[$line->id],
            'source' => $line->source,
            'product_type' => $line->product_type,
            'label' => $line->product_type ? Product::TYPE_LABELS[$line->product_type] : 'Unclear item',
            'quote' => $line->quote,
            'quantity' => $line->quantity,
            'status' => $status,
            'specs' => $this->specs($line),
            'size' => $item->value('size'),
            'fire_rated' => $item->value('fire_rated'),
            'options' => $finalized ? [] : $options->map(fn (Product $p) => $this->option($p, $line->quantity, $prices) + $fitFor($p))->values()->all(),
            'selected' => $selected ? $selected + ($line->product && ! $finalized ? $fitFor($line->product) : ['group' => 'best', 'checks' => []]) : null,
            // Why the product was preselected, until the salesperson confirms or changes it.
            'pick' => $finalized ? null : $this->pickInfo($line, $match),
            // Nothing preselected: how many best matches are equals, until one is chosen.
            'shortlist_count' => $status === 'choose' ? ($match['shortlist']['count'] ?? null) : null,
            // Hints for narrowing the choice, until the line has a product.
            'questions' => $status === 'ready' || ! $match ? [] : $match['questions'],
            'list_price_cents' => $selected['list_price_cents'] ?? null,
            'base_price_cents' => $base,
            'options_price_cents' => $optionsCents,
            'unit_price_cents' => $unit,
            'discount_pct' => $selected['discount_pct'] ?? null,
            'discount_label' => $selected['discount_label'] ?? null,
            'line_total_cents' => $unit !== null ? $unit * $line->quantity : null,
            'option_groups' => $groups,
            'option_requests' => $line->option_requests ?? [],
            'features' => $line->features ?? [],
            // Model / brand names from the notes that we don't carry.
            'unknown_names' => $match['unknown_names'] ?? [],
            'unmatched_option_requests' => $unmatched,
            // "Usually added with this" from the catalog, then the AI's picks; priced for this line's quantity.
            'suggestions' => $this->suggestions($line, $suggestions, $onDraft, $aiProducts, $prices),
            'options_lead_time' => $leadDays > 0 ? $this->leadTimeText($leadDays) : null,
        ];
    }

    /**
     * Why the matcher preselected the line's product. Null once the salesperson has chosen
     * or confirmed a product.
     *
     * @return ?array{confidence: string, reason: string}
     */
    private function pickInfo(DraftLine $line, ?array $match): ?array
    {
        $pick = $match['pick'] ?? null;
        if (! $line->product_id || $line->product_picked_by !== DraftLine::PICKED_AUTO || ($pick['id'] ?? null) !== $line->product_id) {
            return null;
        }

        return ['confidence' => $pick['confidence'], 'reason' => $pick['reason']];
    }

    /**
     * Colors / finishes the salesperson or the notes chose on each line (not store defaults),
     * so another line's product can offer the same one.
     *
     * @param  array<int, int>  $numbers
     * @return list<array{line_id: int, number: int, name: string}>
     */
    private function chosenColors(Draft $draft, array $numbers): array
    {
        $colors = [];

        foreach ($draft->lines as $line) {
            foreach ($line->chosenOptions as $chosen) {
                if (in_array($chosen->source, [DraftLineOption::NOTES, DraftLineOption::SALESPERSON], true) && $this->isColorOption($chosen->option_label)) {
                    $colors[] = ['line_id' => $line->id, 'number' => $numbers[$line->id], 'name' => $chosen->value_name];
                }
            }
        }

        return $colors;
    }

    private function isColorOption(string $label): bool
    {
        return preg_match('/\b(colou?rs?|finish(es)?|powder[- ]?coat(ing)?)\b/i', $label) === 1;
    }

    /** "Red" matches "Safety Red" and "red"; "Grey" matches "Gray". */
    private function sameColor(string $a, string $b): bool
    {
        $words = function (string $s) {
            preg_match_all('/[a-z]{3,}/', strtr(mb_strtolower($s), ['grey' => 'gray']), $m);

            return array_diff($m[0], ['standard', 'color', 'colour', 'finish']);
        };
        [$x, $y] = [$words($a), $words($b)];

        return $x !== [] && $y !== [] && (array_diff($x, $y) === [] || array_diff($y, $x) === []);
    }

    /**
     * The line product's option groups with the current choice. Open drafts list every
     * choice (for the picker) and check the saved choice still exists; finalized drafts
     * show only the frozen choices. Color choices that match a color chosen on another
     * line say so ("same as line 1"), and the group names the match when the current
     * choice differs.
     *
     * @param  list<array{line_id: int, number: int, name: string}>  $colors
     * @return list<array{store_option_id: ?int, label: string, required: bool, choices: list<array>, selected: ?array, color_match: ?array}>
     */
    private function optionGroups(DraftLine $line, bool $finalized, array $colors = []): array
    {
        $chosen = $line->chosenOptions->keyBy('store_option_id');

        if ($finalized || ! $line->product) {
            return $line->chosenOptions->map(fn ($c) => [
                'store_option_id' => $c->store_option_id, 'label' => $c->option_label, 'required' => false, 'choices' => [],
                'selected' => ['store_value_id' => $c->store_value_id, 'name' => $c->value_name, 'price_delta_cents' => $c->price_delta_cents,
                    'lead_time_text' => $c->lead_time_text, 'lead_time_days' => null, 'source' => $c->source, 'quote' => $c->quote,
                    'gone' => false, 'needs_review' => false],
            ])->values()->all();
        }

        $others = array_values(array_filter($colors, fn ($c) => $c['line_id'] !== $line->id));

        return $line->product->options->map(function ($option) use ($chosen, $others) {
            $pick = $chosen->get($option->store_option_id);
            $value = $pick ? $option->values->firstWhere('store_value_id', $pick->store_value_id) : null;
            $sameAs = fn ($v) => $this->isColorOption($option->label) && ! $v->is_none
                ? array_values(array_unique(array_column(array_filter($others, fn ($c) => $this->sameColor($c['name'], $v->name)), 'number')))
                : [];
            $choices = $option->values->map(fn ($v) => [
                'store_value_id' => $v->store_value_id, 'name' => $v->name, 'price_delta_cents' => $v->price_delta_cents,
                'lead_time_text' => $v->lead_time_text, 'is_default' => $v->is_default, 'is_none' => $v->is_none, 'needs_review' => $v->needs_review,
                'image_url' => $v->image_url,
                'same_as' => $sameAs($v), // line numbers whose chosen color this is
            ])->values()->all();
            $current = collect($choices)->firstWhere('store_value_id', $pick?->store_value_id);
            $match = collect($choices)->first(fn ($c) => $c['same_as'] !== []);

            return [
                'store_option_id' => $option->store_option_id,
                'label' => $option->label,
                'required' => $option->required,
                'choices' => $choices,
                // Another line's color is offered here but not chosen (and the salesperson didn't pick this one).
                'color_match' => $match && ($current['same_as'] ?? []) === [] && $pick?->source !== DraftLineOption::SALESPERSON ? $match : null,
                'selected' => $pick ? [
                    'store_value_id' => $pick->store_value_id,
                    'name' => $value?->name ?? $pick->value_name,
                    // Current store price when the choice still exists; the saved one otherwise.
                    'price_delta_cents' => $value ? $value->price_delta_cents : $pick->price_delta_cents,
                    'lead_time_text' => $value?->lead_time_text ?? $pick->lead_time_text,
                    'lead_time_days' => $value?->lead_time_days,
                    'source' => $pick->source,
                    'quote' => $pick->quote,
                    'gone' => $value === null,
                    'needs_review' => (bool) $value?->needs_review,
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * Catalog suggestions first; then the AI's, only while the product they were made for
     * is still chosen and the suggested product is still on sale and not on the draft.
     */
    private function suggestions(DraftLine $line, array $catalog, array $onDraft, Collection $aiProducts, PriceCalculator $prices): array
    {
        $out = array_map(fn ($s) => $this->option($s['product'], $line->quantity, $prices)
            + ['reason' => $s['reason'], 'evidence' => $s['evidence'], 'quote' => $s['quote'], 'source' => 'catalog', 'kind' => 'add_on'], $catalog);

        foreach ($line->product_id ? ($line->ai_suggestions ?? []) : [] as $s) {
            $product = $aiProducts->get($s['product_id']);

            if (! $product || $s['for_product_id'] !== $line->product_id || in_array($product->id, $onDraft, true)
                || in_array($product->id, array_column($out, 'id'), true)) {
                continue;
            }

            $out[] = $this->option($product, $line->quantity, $prices)
                + ['reason' => $s['reason'], 'evidence' => null, 'quote' => $s['quote'], 'source' => 'ai', 'kind' => $s['kind']];
        }

        return $out;
    }

    private function leadTimeText(int $days): string
    {
        return $days % 7 === 0 ? '+ '.($days / 7).' week'.($days > 7 ? 's' : '') : "+ {$days} days";
    }

    /** A product priced for this line's quantity and the draft's account/discount. */
    private function option(Product $p, int $quantity, PriceCalculator $prices): array
    {
        $price = $prices->price($p, $quantity);
        $page = $p->relationLoaded('page') ? $p->page : null;

        return [
            'id' => $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'url' => $p->url,
            'image_url' => $p->image_url,
            'size' => $p->openingSize(),
            'price_cents' => $price['unit_cents'] ?? null, // net, per unit
            'list_price_cents' => $price['list_cents'] ?? null,
            'discount_pct' => $price['discount_pct'] ?? null,
            'discount_label' => $price['discount_label'] ?? null,
            // From the product page, when it has been fetched.
            'spec_lines' => array_slice($page?->spec_lines ?? [], 0, 8),
            'spec_sheet_url' => $page?->spec_sheet_url,
        ];
    }

    private function specs(DraftLine $line): array
    {
        $out = [];

        foreach (self::SPEC_LABELS as $key => $name) {
            if (! isset($line->specs[$key]) || $key === 'quantity') {
                continue; // quantity has its own editor
            }

            $out[] = [
                'key' => $key,
                'name' => $name,
                'value' => $this->specValue($key, $line->specs[$key]['value']),
                'quote' => $line->specs[$key]['quote'] ?? null, // null = entered by salesperson
            ];
        }

        return $out;
    }

    private function specValue(string $key, mixed $value): string
    {
        $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');

        return match ($key) {
            'size' => $num($value['width']).'" x '.$num($value['height']).'"',
            'fire_rated', 'insulated' => $value ? 'Yes' : 'No',
            'load_psf' => $num($value).' PSF',
            'load_lbs' => $num($value).' lbs',
            default => (string) $value,
        };
    }

    private function cents(mixed $price): ?int
    {
        return $price === null ? null : (int) round((float) $price * 100);
    }
}
