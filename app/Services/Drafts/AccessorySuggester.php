<?php

namespace App\Services\Drafts;

use App\Models\Product;
use App\Models\ProductLink;
use Illuminate\Support\Collection;

/**
 * "Usually added with this": products the catalog itself ties to the chosen one.
 *
 *   from notes  an add-on the notes ask for that the product has no option for ("safety rails")
 *   made for    an accessory that names the model ("Flashing Kit Compatible with BA-PDS, BA-PDX")
 *   for type    an accessory that names the product type ("Aluminum Ladder for Roof Hatches")
 *
 * Sized accessories must fit the product's opening: a wrong-size kit is never offered.
 * Nothing is added by itself; the salesperson clicks Add.
 */
class AccessorySuggester
{
    public const LIMIT = 4;

    /** Types sold as add-ons to another type: searched by name when the notes ask for one. */
    private const ADD_ON_TYPES = [
        'roof_hatch' => ['safety_rail', 'ladder'],
        'floor_door' => ['safety_rail', 'ladder'],
        'smoke_vent' => ['safety_rail'],
        'skylight' => ['skylight'], // flashing kits and blinds are typed skylight
        'ladder' => ['ladder'],
    ];

    /** A whole unit that includes the add-on ("Roof Hatch with Safety Railing") isn't an add-on. */
    private const COMPLETE_UNIT = '/\b(hatch|door|vent|skylight)\s+with\b/i';

    /** Typed "ladder" but made for attic ladders: never an add-on for a roof hatch or floor door. */
    private const ATTIC_LADDER_PART = '/\battic\b|\bfor (metal |wood(en)? |folding )*ladders?\b|\bsteps? for\b|\binstallation brackets\b/i';

    /**
     * @param  list<array{text: string, quote: string}>  $wishes  requests from the notes the product has no option for
     * @param  list<int>  $exclude  products already on the draft
     * @return list<array{product: Product, reason: string, evidence: ?string, quote: ?string}>
     */
    public function for(Product $product, array $wishes = [], array $exclude = []): array
    {
        if ($this->isAccessory($product)) {
            return []; // a flashing kit doesn't come with more flashing kits
        }

        $exclude = [...$exclude, $product->id];
        $suggestions = collect();

        foreach ($wishes as $wish) {
            foreach ($this->forWish($product, $wish['text'], $exclude) as $accessory) {
                $suggestions->push(['product' => $accessory, 'reason' => "Notes ask for “{$wish['text']}”", 'evidence' => null, 'quote' => $wish['quote']]);
            }
        }

        foreach ($this->linked($product, $exclude) as $link) {
            $suggestions->push([
                'product' => $link->product,
                'reason' => $link->kind === ProductLink::COMPATIBLE_WITH ? "Made for {$link->related_model}" : 'Accessory for '.mb_strtolower(Product::TYPE_LABELS[$link->related_type]).'s',
                'evidence' => $link->evidence,
                'quote' => null,
            ]);
        }

        // One per accessory model: the fitting size, or the bestseller.
        return $suggestions
            ->unique(fn ($s) => $s['product']->model ?: $s['product']->sku)
            ->take(self::LIMIT)
            ->values()
            ->all();
    }

    /**
     * Every add-on that could go with this product, for the AI to choose from: linked
     * accessories, then add-on types (safety rails, ladders) that fit its size.
     *
     * @param  list<int>  $exclude
     * @return Collection<int, Product>
     */
    public function addOnCandidates(Product $product, array $exclude, int $limit): Collection
    {
        if ($this->isAccessory($product)) {
            return collect();
        }

        $exclude = [...$exclude, $product->id];
        $linked = $this->linked($product, $exclude)->map(fn (ProductLink $l) => $l->product);
        $typed = Product::query()->where('is_available', true)
            ->whereIn('product_type', self::ADD_ON_TYPES[$product->product_type] ?? [])
            ->whereNotIn('id', [...$exclude, ...$linked->pluck('id')])
            ->orderByDesc('popularity')->orderBy('sku')->limit(200)->get()
            ->filter(fn (Product $p) => $this->isAddOnFor($p, $product))
            ->sortBy(fn (Product $p) => $this->sameSize($p, $product) ? 0 : 1);

        // One per accessory model: five ladder lengths are one suggestion.
        return $linked->concat($typed)->unique(fn (Product $p) => $p->model ?: $p->sku)->take($limit)->values();
    }

    /** @return Collection<int, ProductLink> made-for links first, size fits only, bestsellers first */
    private function linked(Product $product, array $exclude): Collection
    {
        $model = mb_strtoupper((string) $product->model);

        return ProductLink::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('kind', ProductLink::COMPATIBLE_WITH)->where('related_model', $model))
                ->orWhere(fn ($q) => $q->where('kind', ProductLink::ACCESSORY_FOR)->where('related_type', $product->product_type)))
            ->whereNotIn('product_id', $exclude)
            ->whereHas('product', fn ($q) => $q->where('is_available', true))
            ->with('product')
            ->get()
            ->filter(fn (ProductLink $l) => $this->fitsSize($l->product, $product) && ! $this->mountingClash($l->product, $product))
            ->sortBy(fn (ProductLink $l) => [$l->kind === ProductLink::COMPATIBLE_WITH ? 0 : 1, -$l->product->popularity, $l->product->sku])
            ->values();
    }

    /** @return Collection<int, Product> add-on products named like the wish ("safety rails" → "… Safety Railing") */
    private function forWish(Product $product, string $wish, array $exclude): Collection
    {
        $types = self::ADD_ON_TYPES[$product->product_type] ?? [];
        preg_match_all('/[a-z]{3,}/', mb_strtolower($wish), $m);
        $words = array_map(fn ($w) => preg_replace('/(s|ing)$/', '', $w), $m[0]);

        if ($types === [] || $words === []) {
            return collect();
        }

        $query = Product::query()->where('is_available', true)->whereIn('product_type', $types)->whereNotIn('id', $exclude);
        foreach ($words as $word) {
            $query->where('name', 'ilike', "%{$word}%");
        }

        return $query->orderByDesc('popularity')->orderBy('sku')->limit(50)->get()
            ->filter(fn (Product $p) => $this->isAddOnFor($p, $product))
            ->sortBy(fn (Product $p) => $this->sameSize($p, $product) ? 0 : 1) // stable: popularity order within
            ->values();
    }

    /** An add-on-type product that really goes on this one: not a whole unit, the right size and mounting. */
    private function isAddOnFor(Product $addOn, Product $product): bool
    {
        return ! preg_match(self::COMPLETE_UNIT, $addOn->name)
            && ($product->product_type === 'ladder' || ! preg_match(self::ATTIC_LADDER_PART, $addOn->name))
            && $this->fitsSize($addOn, $product)
            && ! $this->mountingClash($addOn, $product);
    }

    private function isAccessory(Product $product): bool
    {
        return ProductLink::query()->where('product_id', $product->id)
            ->whereIn('kind', [ProductLink::COMPATIBLE_WITH, ProductLink::ACCESSORY_FOR])->exists();
    }

    /** Unsized accessories (ladders, blinds) fit anything; sized ones only the same opening. */
    private function fitsSize(Product $accessory, Product $product): bool
    {
        return $accessory->nominal_width === null || $product->nominal_width === null || $this->sameSize($accessory, $product);
    }

    private function sameSize(Product $a, Product $b): bool
    {
        if ($a->nominal_width === null || $b->nominal_width === null) {
            return false;
        }

        $close = fn ($x, $y) => abs((float) $x - (float) $y) < 0.01;

        return ($close($a->nominal_width, $b->nominal_width) && $close($a->nominal_height, $b->nominal_height))
            || ($close($a->nominal_width, $b->nominal_height) && $close($a->nominal_height, $b->nominal_width));
    }

    /** A curb-mount flashing kit on a deck-mounted skylight (or the other way round). */
    private function mountingClash(Product $accessory, Product $product): bool
    {
        $mount = fn (string $text) => [preg_match('/\bcurb/i', $text) === 1, preg_match('/\bdeck/i', $text) === 1];
        [$accCurb, $accDeck] = $mount($accessory->name);
        [$curb, $deck] = $mount($product->name.' '.implode(' ', $product->purpose));

        return ($accCurb && ! $accDeck && $deck && ! $curb) || ($accDeck && ! $accCurb && $curb && ! $deck);
    }
}
