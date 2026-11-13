<?php

namespace App\Services\Drafts;

use App\Models\DraftLine;
use App\Models\DraftLineOption;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use Illuminate\Support\Facades\DB;

/**
 * Chooses options for a line's product, from that product's real choices only:
 *
 *   1. a choice the notes asked for ("keyed lock" → Key Operated Cylinder Cam Latch)
 *   2. a choice that provides a stated detail (material "Stainless Steel" → Stainless Steel 304)
 *   3. the store's standard choice ("… - Standard", or "No Selected" for add-ons)
 *
 * Anything chosen from the notes is flagged for the salesperson to confirm, who can
 * change any choice. Choices are saved by the store's option / choice ids.
 */
class OptionSelector
{
    private const STOPWORDS = ['a', 'an', 'the', 'with', 'and', 'for', 'of', 'to', 'on', 'option', 'options', 'please', 'need', 'needs', 'want', 'wants', 'add', 'type', 'style'];

    private const SYNONYMS = [
        'latch' => 'lock', 'locking' => 'lock', 'keyed' => 'key', 'keys' => 'key', 'ss' => 'stainless', 'inox' => 'stainless',
        'railing' => 'rail', 'opener' => 'open', 'operator' => 'open', 'motorized' => 'electric', 'motor' => 'electric',
        'seal' => 'gasket', 'weatherstrip' => 'gasket', 'depth' => 'deep', 'grey' => 'gray', 'cylinder' => 'cylinder',
    ];

    public function __construct(private ProductFit $fit) {}

    /** (Re)choose every option for the line's current product. */
    public function initialize(DraftLine $line): void
    {
        DB::transaction(function () use ($line) {
            $line->chosenOptions()->delete();
            $product = $line->product()->with('options.values')->first();

            if (! $product || $product->options->isEmpty()) {
                return;
            }

            $fromNotes = $this->fromNotes($line, $product);
            $fromDetails = $this->fromDetails($line, $product);

            foreach ($product->options as $option) {
                [$value, $source, $quote] = match (true) {
                    isset($fromNotes[$option->id]) => [$fromNotes[$option->id]['value'], DraftLineOption::NOTES, $fromNotes[$option->id]['quote']],
                    isset($fromDetails[$option->id]) => [$fromDetails[$option->id]['value'], DraftLineOption::NOTES, $fromDetails[$option->id]['quote']],
                    default => $this->standardChoice($option),
                };

                if ($value) {
                    $this->save($line, $option, $value, $source, $quote);
                }
            }
        });
    }

    /** The salesperson picks a choice (or clears an optional one with null). */
    public function choose(DraftLine $line, int $storeOptionId, ?int $storeValueId): void
    {
        $option = ProductOption::query()->where('product_id', $line->product_id)->where('store_option_id', $storeOptionId)->with('values')->firstOrFail();

        if ($storeValueId === null) {
            $line->chosenOptions()->where('store_option_id', $storeOptionId)->delete();

            return;
        }

        $this->save($line, $option, $option->values->firstWhere('store_value_id', $storeValueId) ?? abort(422, 'Unknown choice.'), DraftLineOption::SALESPERSON, null);
    }

    /**
     * Notes' option wishes that no choice of this product matches. A wish the product's own
     * name already covers ("Heavy Gauge" for "… With Heavy Gauge Steel") isn't missing.
     *
     * @return list<array{text: string, quote: string}>
     */
    public function unmatched(DraftLine $line, Product $product): array
    {
        $product->loadMissing('options.values');
        $name = $this->tokens($product->name);

        return array_values(array_filter($line->option_requests ?? [], fn ($r) => $this->bestChoice($r['text'], $product) === null
            && array_diff($this->tokens($r['text']), $name) !== []));
    }

    /**
     * Every option gets a choice: the store's standard one; for an optional add-on with no
     * standard, "No Selected" (or nothing); for a required option with no standard, the
     * lowest-priced choice, marked ASSUMED so the salesperson checks it.
     *
     * @return array{0: ?ProductOptionValue, 1: ?string, 2: null}
     */
    private function standardChoice(ProductOption $option): array
    {
        if ($value = $option->values->firstWhere('is_default', true)) {
            return [$value, DraftLineOption::DEFAULT, null];
        }
        if (! $option->required) {
            return [$option->values->firstWhere('is_none', true), DraftLineOption::DEFAULT, null];
        }

        // Stable sort: listing order breaks price ties; unreadable prices go last.
        $cheapest = $option->values->reject(fn ($v) => $v->is_none)
            ->sortBy(fn ($v) => $v->price_delta_cents ?? PHP_INT_MAX)->first();

        return [$cheapest, DraftLineOption::ASSUMED, null];
    }

    /** @return array<int, array{value: ProductOptionValue, quote: string, score: float}> keyed by product option id */
    private function fromNotes(DraftLine $line, Product $product): array
    {
        $picked = [];

        foreach ($line->option_requests ?? [] as $request) {
            if (! $hit = $this->bestChoice($request['text'], $product)) {
                continue;
            }
            [$option, $value, $score] = $hit;
            if (! isset($picked[$option->id]) || $score > $picked[$option->id]['score']) {
                $picked[$option->id] = ['value' => $value, 'quote' => $request['quote'], 'score' => $score];
            }
        }

        return $picked;
    }

    /** Choices that provide a stated detail ("Stainless Steel" → the stainless material option). */
    private function fromDetails(DraftLine $line, Product $product): array
    {
        $item = $line->requestedItem();
        $picked = [];

        foreach ($this->fit->check($item, $product)['checks'] as $check) {
            if ($check['verdict'] !== ProductFit::OPTION) {
                continue;
            }
            foreach ($product->options as $option) {
                if ($value = $option->values->firstWhere('id', $check['option_value_id'])) {
                    $picked[$option->id] = ['value' => $value, 'quote' => $item->specs[$check['spec']]['quote'] ?? null];
                }
            }
        }

        return $picked;
    }

    /**
     * The choice whose name best covers the wish's words. Ties prefer a non-standard choice
     * (a wish usually means "not the standard one"), then the cheaper one.
     *
     * @return ?array{0: ProductOption, 1: ProductOptionValue, 2: float}
     */
    public function bestChoice(string $text, Product $product): ?array
    {
        $wanted = $this->tokens($text);
        if ($wanted === []) {
            return null;
        }

        $best = null;
        foreach ($product->options as $option) {
            foreach ($option->values as $value) {
                if ($value->is_none) {
                    continue;
                }
                $have = $this->tokens($value->name.' '.$option->label);
                $score = count(array_intersect($wanted, $have)) / count($wanted);
                if ($score < 0.5) {
                    continue;
                }
                $key = [$score, ! $value->is_default, -($value->price_delta_cents ?? PHP_INT_MAX)];
                if (! $best || $key > $best[3]) {
                    $best = [$option, $value, $score, $key];
                }
            }
        }

        return $best ? [$best[0], $best[1], $best[2]] : null;
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        preg_match_all('/[a-z0-9]+/', mb_strtolower($text), $m);
        $tokens = [];

        foreach ($m[0] as $word) {
            if (in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $word = self::SYNONYMS[$word] ?? $word;
            if (strlen($word) > 4 && ! str_ends_with($word, 'ss')) {
                $word = preg_replace('/(ing|ed|s)$/', '', $word);
            }
            $tokens[] = self::SYNONYMS[$word] ?? $word;
        }

        return array_values(array_unique($tokens));
    }

    private function save(DraftLine $line, ProductOption $option, ProductOptionValue $value, string $source, ?string $quote): void
    {
        $line->chosenOptions()->updateOrCreate(['store_option_id' => $option->store_option_id], [
            'store_value_id' => $value->store_value_id,
            'option_label' => $option->label,
            'value_name' => $value->name,
            'price_delta_cents' => $value->price_delta_cents,
            'lead_time_text' => $value->lead_time_text,
            'source' => $source,
            'quote' => $quote,
        ]);
    }
}
