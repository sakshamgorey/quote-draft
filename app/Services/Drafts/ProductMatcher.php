<?php

namespace App\Services\Drafts;

use App\Models\Product;
use App\Models\ProductLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finds catalog products for one validated item, or the questions to ask.
 *
 * Hard filters (SQL): available, product_type, opening size (either orientation),
 * fire rating. Then every other stated detail is judged per product (ProductFit:
 * yes / via option / unknown / no) from store tags, the product page and its options:
 *
 *   best  no detail contradicted (unknown counts as possible), confirmed ones first
 *   also  contradicts a stated detail, including a named SKU outside the hard filters
 */
class ProductMatcher
{
    public const LIMIT = 20;

    /** Safety cap on candidates judged in PHP (the largest type+size bucket is ~200). */
    private const MAX_CANDIDATES = 600;

    /** Types sold by opening size. Ladders are sold by length/ceiling height too. */
    private const UNSIZED = ['ladder'];

    /** Details an option can provide, so options must be loaded to judge them. */
    private const OPTION_SPECS = ['material', 'flange_type', 'insulated'];

    /** A title names a product only with this many words beyond its size and filler words… */
    private const TITLE_MIN_WORDS = 6;

    /** …and when the notes contain at least this share of them. */
    private const TITLE_COVERAGE = 0.9;

    private const TITLE_STOPWORDS = ['x', 'for', 'with', 'and', 'the', 'a', 'an', 'of', 'in', 'to', 'inch', 'inches'];

    /** The flange a panel set into this surface finishes flush with (catalog flange_type). */
    private const SURFACE_FLANGE = ['Drywall' => 'Drywall Flange', 'Plaster' => 'Plaster Flange'];

    public function __construct(private ProductFit $fit) {}

    /**
     * @return array{status: 'matched'|'needs_info'|'no_match', products: Collection<int, Product>, best: Collection<int, Product>,
     *               also: Collection<int, Product>, fits: array<int, array>, questions: list<string>, unknown_names: list<array>, pick: ?array{id: int, confidence: string, reason: string},
     *               shortlist: ?array{count: int, tags: array<int, list<string>>}}
     */
    public function match(RequestedItem $item): array
    {
        $unknown = $this->fit->unknownNames($item);
        $size = $item->value('size');
        // A full SKU in the notes ("BA-AHD-12-12") names the product, and so its type and size.
        $named = $this->namedProduct($item);

        if ($item->type === null && ! $named) {
            $what = $item->quote ? "“{$item->quote}”" : 'this item';

            return $this->result('needs_info', $unknown, [
                "Which product is {$what} for: an access door/panel, floor door, roof hatch, smoke vent, skylight, ladder or something else?",
            ]);
        }

        $label = mb_strtolower(Product::TYPE_LABELS[$item->type ?? $named->product_type]);

        if ($size === null && ! $named && ! in_array($item->type, self::UNSIZED, true)) {
            $question = in_array('size', $item->rejected, true)
                ? "Can you confirm the opening size of the {$label} (width x height, in inches)?"
                : "What opening size do you need for the {$label} (width x height, in inches)?";

            return $this->result('needs_info', $unknown, [$question, ...$this->optionalQuestions($item, collect())]);
        }

        $searchItem = $item;
        if ($named) {
            $specs = $item->specs;
            if ($size === null && $named->nominal_width !== null && $named->nominal_height !== null) {
                $specs['size'] = ['value' => ['width' => $named->nominal_width, 'height' => $named->nominal_height], 'quote' => null];
            }
            $searchItem = new RequestedItem($item->type ?? $named->product_type, $item->quote, $specs);
        }
        $candidates = $this->query($searchItem)->limit(self::MAX_CANDIDATES)->get();
        if ($named && ! $candidates->contains('id', $named->id)) {
            $candidates->prepend($named);
        }

        if ($candidates->isEmpty()) {
            return $this->result('no_match', $unknown, [$this->noMatchQuestion($item, $label)]);
        }

        $fits = $candidates->mapWithKeys(fn (Product $p) => [$p->id => $this->fit->check($item, $p)])->all();
        $rank = $candidates->map(fn (Product $p, int $i) => $this->rankRow($item, $p, $fits[$p->id], $i));

        // Best matches, one of them named by the notes if they quote its SKU, store title or model.
        // Accessories (a flashing kit typed "skylight") only count as best when the notes name them.
        $accessories = $this->accessoryIds($candidates);
        $rank = $rank->map(fn ($r) => $r + ['accessory' => isset($accessories[$r['product']->id]) && $r['model'] === 0]);
        $best = $rank->filter(fn ($r) => $r['fit']['no'] === 0 && ! $r['accessory'])->values();
        [$namedId, $namedBy] = $this->namedAmong($item, $best);
        if ($namedBy === 'title') {
            $fits[$namedId]['checks'][] = ['spec' => 'title', 'label' => 'Name', 'value' => 'as written in the notes', 'verdict' => ProductFit::YES,
                'evidence' => $best->firstWhere('product.id', $namedId)['product']->name, 'option_value_id' => null];
        }
        // Within equals, the shortlist order only: the flange the surface needs, bestseller, wording.
        $best = $best->sort(fn ($a, $b) => [$b['product']->id === $namedId, ...$this->strength($b), $b['flange'], -$b['position'], $b['wording']]
            <=> [$a['product']->id === $namedId, ...$this->strength($a), $a['flange'], -$a['position'], $a['wording']])->values();

        // The named model outranks everything else stated: "Acudor BA-PFI" when BA-PFI is a Cendrex
        // door still lists BA-PFI first. Candidates arrive in bestseller order; sorts are stable.
        $also = $rank->filter(fn ($r) => $r['fit']['no'] > 0 || $r['accessory'])
            ->sort(fn ($a, $b) => [$b['model'], $a['fit']['no'], $b['fit']['yes'], $a['position']] <=> [$a['model'], $b['fit']['no'], $a['fit']['yes'], $b['position']])
            ->take(self::LIMIT)->pluck('product')->values();

        $questions = $this->optionalQuestions($item, $candidates);
        if ($named) {
            $conflicts = collect($fits[$named->id]['checks'])->where('verdict', ProductFit::NO)
                ->map(fn ($check) => $this->fit->conflictMessage($named, $check))->all();
            $questions = [...$conflicts, ...$questions];
        }
        if ($best->isEmpty()) {
            if (! $named) {
                array_unshift($questions, 'No product matches every detail in the notes. The closest ones are listed: check which detail can change.');
            }

            if (! $rank->contains(fn ($r) => $r['model'] === 1) && $sizes = $this->modelSizes($item)) {
                array_unshift($questions, "“{$item->value('model')}” isn't listed in this size. It comes in: {$sizes}.");
            }
        }

        $pick = $this->pick($best, $namedId, $namedBy, $item);

        // Keep the named SKU for review; the presenter blocks unresolved contradictions.
        if (($pick['confidence'] ?? null) !== 'named' && ($this->fit->namedModel($item)[0] ?? null) === 'sku'
            && ($skuRow = $rank->where('model', 1))->count() === 1) {
            $row = $skuRow->first();
            $differs = collect($row['fit']['checks'])->where('verdict', ProductFit::NO)->map(fn ($c) => "{$c['label']} {$c['value']}")->implode(', ');
            $pick = ['id' => $row['product']->id, 'confidence' => 'named',
                'reason' => 'The notes give its SKU.'.($differs !== '' ? " Check: the catalog differs on {$differs}." : '')];
        }

        $shortlist = $pick === null && $best->isNotEmpty() ? $this->shortlist($best) : null;
        $best = $best->take(self::LIMIT)->pluck('product')->values();

        return [
            'status' => 'matched',
            'products' => $best->concat($also)->values(),
            'best' => $best,
            'also' => $also,
            'fits' => array_intersect_key($fits, $best->concat($also)->keyBy('id')->all()),
            'questions' => $questions,
            'unknown_names' => $unknown,
            'pick' => $pick,
            'shortlist' => $shortlist,
        ];
    }

    /**
     * When nothing is preselected: how many best matches are equally good, and what sets
     * the first few apart (the flange the surface needs, the store bestseller).
     *
     * @param  Collection<int, array>  $best  rank rows, sorted
     * @return array{count: int, tags: array<int, list<string>>} tags keyed by product id
     */
    private function shortlist(Collection $best): array
    {
        $equals = $best->filter(fn ($r) => $this->strength($r) === $this->strength($best->first()))->values();
        $bestseller = $equals->count() > 1 ? $equals->sortBy('position')->first()['product']->id : null;

        $tags = [];
        foreach ($equals as $row) {
            $id = $row['product']->id;
            if ($row['flange']) {
                $tags[$id][] = self::SURFACE_FLANGE[$row['surface']].' for '.mb_strtolower($row['surface']);
            }
            if ($id === $bestseller) {
                $tags[$id][] = 'Store bestseller';
            }
        }

        return ['count' => $equals->count(), 'tags' => $tags];
    }

    /**
     * Candidates that are accessories of other products: linked as made-for / accessory-for,
     * or a flashing kit or blind by name.
     *
     * @param  Collection<int, Product>  $candidates
     * @return array<int, true> keyed by product id
     */
    private function accessoryIds(Collection $candidates): array
    {
        $linked = ProductLink::query()->whereIn('product_id', $candidates->pluck('id'))
            ->whereIn('kind', [ProductLink::COMPATIBLE_WITH, ProductLink::ACCESSORY_FOR])->pluck('product_id');
        $named = $candidates->filter(fn (Product $p) => preg_match('/\b(flashing|blinds?)\b/i', $p->name))->pluck('id');

        return $linked->concat($named)->mapWithKeys(fn ($id) => [$id => true])->all();
    }

    /**
     * What the ranking needs to know about one candidate, beyond its fit.
     *
     * @return array{product: Product, fit: array, position: int, model: int, detail_yes: int, feature_yes: int, flange: int, surface: ?string, wording: int}
     */
    private function rankRow(RequestedItem $item, Product $product, array $fit, int $position): array
    {
        $count = fn (callable $test) => count(array_filter($fit['checks'], $test));
        // A panel set into drywall or plaster usually wants that flange, unless the notes say which.
        $surface = $item->has('flange_type') ? null : $item->value('surface_type');
        $flange = isset(self::SURFACE_FLANGE[$surface]) && in_array(self::SURFACE_FLANGE[$surface], $product->flange_type ?? [], true);

        return [
            'product' => $product,
            'fit' => $fit,
            'position' => $position, // bestseller order
            'model' => $count(fn ($c) => $c['spec'] === 'model' && $c['verdict'] === ProductFit::YES) > 0 ? 1 : 0,
            'detail_yes' => $count(fn ($c) => $c['spec'] !== 'feature' && in_array($c['verdict'], [ProductFit::YES, ProductFit::OPTION], true)),
            'feature_yes' => $count(fn ($c) => $c['spec'] === 'feature' && $c['verdict'] === ProductFit::YES),
            'flange' => $flange ? 1 : 0,
            'surface' => $surface,
            'wording' => $this->wording($item, $product),
        ];
    }

    /**
     * What can decide a preselection: details the notes state that the catalog confirms,
     * then features the notes ask for. Everything else only orders the shortlist.
     *
     * @return array{0: int, 1: int}
     */
    private function strength(array $row): array
    {
        return [$row['detail_yes'], $row['feature_yes']];
    }

    /**
     * The best match the notes name, most specific first: the SKU, the store title, then a
     * model only one best match has.
     *
     * @param  Collection<int, array>  $best  rank rows
     * @return array{0: ?int, 1: ?string} product id and how it was named
     */
    private function namedAmong(RequestedItem $item, Collection $best): array
    {
        $mode = $this->fit->namedModel($item)[0] ?? null;
        $byModel = $best->where('model', 1);

        if ($mode === 'sku' && $byModel->count() === 1) {
            return [$byModel->first()['product']->id, 'sku'];
        }
        if ($titled = $this->namedByTitle($item, $best->pluck('product'))) {
            return [$titled->id, 'title'];
        }
        if ($mode !== null && $byModel->count() === 1) {
            return [$byModel->first()['product']->id, 'model'];
        }

        return [null, null];
    }

    /**
     * The one product to preselect, only when the notes settle it:
     *
     *   named     the notes name it (SKU, store title, model)
     *   only      the only product that fits every detail
     *   best_fit  alone in confirming a stated detail, or a feature the notes ask for
     *
     * Nothing is preselected when no product fits every detail, or when the best matches
     * differ only in wording, flange or store popularity: the salesperson picks from the
     * shortlist.
     *
     * @param  Collection<int, array>  $best  rank rows, sorted
     * @return ?array{id: int, confidence: string, reason: string}
     */
    private function pick(Collection $best, ?int $namedId, ?string $namedBy, RequestedItem $item): ?array
    {
        if ($best->isEmpty()) {
            return null;
        }

        $top = $best->first();
        $pick = fn (string $confidence, string $reason) => ['id' => $top['product']->id, 'confidence' => $confidence, 'reason' => $reason];

        if ($namedId !== null) {
            return $pick('named', match ($namedBy) {
                'sku' => 'The notes give its SKU.',
                'title' => 'The notes quote its store title.',
                default => "The notes name the model {$item->value('model')}.",
            });
        }
        if ($best->count() === 1) {
            return $pick('only', 'The only product that fits every detail in the notes.');
        }

        $second = $best[1];
        $details = count(array_filter($top['fit']['checks'], fn ($c) => $c['spec'] !== 'feature'));

        return match (true) {
            $top['detail_yes'] > $second['detail_yes'] => $pick('best_fit', "Confirms {$top['detail_yes']} of {$details} details in the notes; the next best confirms {$second['detail_yes']}."),
            $top['feature_yes'] > $second['feature_yes'] => $pick('best_fit', 'Has more of the features the notes ask for.'),
            default => null,
        };
    }

    /**
     * How much the product's name shares the notes' wording, in tenths (Jaccard of the
     * distinctive words). Tenths, so a one-word difference in long titles doesn't decide.
     */
    private function wording(RequestedItem $item, Product $product): int
    {
        if (! $item->quote) {
            return 0;
        }

        $sizeNumbers = array_map(fn ($n) => (string) (float) $n, array_filter([$product->nominal_width, $product->nominal_height]));
        $name = $this->stems(array_diff($this->titleWords($product->name), self::TITLE_STOPWORDS, $sizeNumbers));
        $quote = $this->stems(array_diff($this->titleWords($item->quote), self::TITLE_STOPWORDS, $sizeNumbers));
        $union = count(array_unique([...$name, ...$quote]));

        return $union === 0 ? 0 : (int) floor(10 * count(array_intersect($name, $quote)) / $union);
    }

    /**
     * "Hours" and "hour", "panels" and "panel" read the same.
     *
     * @param  array<string>  $words
     * @return list<string>
     */
    private function stems(array $words): array
    {
        return array_values(array_unique(array_map(fn ($w) => strlen($w) > 3 && ! is_numeric($w) ? preg_replace('/(es|s)$/', '', $w) : $w, $words)));
    }

    /**
     * The one best match whose store title the notes quote almost word for word
     * ("5 24" x 24" 2 Hours Fire-Rated Insulated Flush Access Panel for Walls - With Heavy
     * Gauge Steel" → BA-FD-HG-24-24). Short, generic titles never count, and neither do
     * two products with the same title.
     *
     * @param  Collection<int, Product>  $best
     */
    private function namedByTitle(RequestedItem $item, Collection $best): ?Product
    {
        if (! $item->quote) {
            return null;
        }

        $quoted = $this->titleWords($item->quote);
        $hits = $best->filter(function (Product $p) use ($quoted) {
            $sizeNumbers = array_map(fn ($n) => (string) (float) $n, array_filter([$p->nominal_width, $p->nominal_height]));
            $distinctive = array_diff($this->titleWords($p->name), self::TITLE_STOPWORDS, $sizeNumbers);

            return count($distinctive) >= self::TITLE_MIN_WORDS
                && count(array_intersect($distinctive, $quoted)) / count($distinctive) >= self::TITLE_COVERAGE;
        });

        return $hits->count() === 1 ? $hits->first() : null;
    }

    /** @return list<string> lowercase words; "24x24" and '24" x 24"' read the same */
    private function titleWords(string $text): array
    {
        $text = preg_replace('/(\d)\s*["″”]?\s*[x×]\s*(\d)/iu', '$1 x $2', mb_strtolower($text)) ?? '';
        preg_match_all('/[a-z0-9]+(?:\.\d+)?/', $text, $m);

        return array_values(array_unique(array_map(fn ($w) => is_numeric($w) ? (string) (float) $w : $w, $m[0])));
    }

    /** Resolve SKU identity independently of the requested type, dimensions and rating. */
    private function namedProduct(RequestedItem $item): ?Product
    {
        [$mode, $code] = $this->fit->namedModel($item) ?? [null, null];

        if ($mode !== 'sku') {
            return null;
        }

        return $this->available()
            ->whereRaw("regexp_replace(upper(sku), '[^A-Z0-9]', '', 'g') = ?", [$code])
            ->with('page', 'options.values')
            ->first();
    }

    /** Opening sizes the named model comes in, when the notes' size isn't one of them. */
    private function modelSizes(RequestedItem $item): ?string
    {
        [$mode, $code] = $this->fit->namedModel($item) ?? [null, null];

        if (! in_array($mode, ['sku', 'model'], true)) {
            return null;
        }

        $column = $mode === 'sku' ? 'sku' : 'model';
        $sizes = $this->available()
            ->whereRaw("regexp_replace(upper(coalesce({$column}, '')), '[^A-Z0-9]', '', 'g') = ?", [$code])
            ->whereNotNull('nominal_width')
            ->orderBy('nominal_width')->orderBy('nominal_height')
            ->get(['nominal_width', 'nominal_height'])
            ->map(fn ($p) => $this->formatSize((float) $p->nominal_width, (float) $p->nominal_height))
            ->unique()->values();

        if ($sizes->isEmpty()) {
            return null;
        }

        $size = $item->value('size');
        if ($size === null || $sizes->contains($this->formatSize($size['width'], $size['height']))
            || $sizes->contains($this->formatSize($size['height'], $size['width']))) {
            return null;
        }

        return $sizes->take(12)->implode(', ').($sizes->count() > 12 ? ' and '.($sizes->count() - 12).' more' : '');
    }

    private function query(RequestedItem $item): Builder
    {
        $query = $this->available()->where('product_type', $item->type);

        if ($size = $item->value('size')) {
            $this->whereOpening($query, $size['width'], $size['height']);
        }

        if ($item->has('fire_rated')) {
            $query->where('fire_rated', $item->value('fire_rated'));
        }

        $optionsMatter = collect(self::OPTION_SPECS)->contains(fn ($spec) => $item->has($spec));

        return $query
            ->with('page')
            ->when($optionsMatter, fn ($q) => $q->with('options.values'))
            ->orderByDesc('popularity') // store bestsellers first among equally good matches
            ->orderBy('model')
            ->orderBy('nominal_width')
            ->orderBy('sku'); // stable order for ties: options shouldn't reshuffle between page loads
    }

    /** Disabled on the store (discontinued / hidden): never offered in a draft. */
    private function available(): Builder
    {
        return Product::query()->where('is_available', true);
    }

    /** Same rule as the product search page: 24 x 36 also finds 36 x 24. */
    private function whereOpening(Builder $query, float $w, float $h): void
    {
        $query->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('nominal_width', $w)->where('nominal_height', $h))
            ->orWhere(fn ($q) => $q->where('nominal_width', $h)->where('nominal_height', $w)));
    }

    private function noMatchQuestion(RequestedItem $item, string $label): string
    {
        $size = $item->value('size');
        $sizeText = $size ? $this->formatSize($size['width'], $size['height']).' ' : '';
        $fire = $item->value('fire_rated') === true;

        // Fire-rated filter emptied it, but the size exists without it?
        if ($fire && $size && $this->sizeExists($item->type, $size, fireRated: null)) {
            return "We don't list a fire-rated {$sizeText}{$label}. Would a non-fire-rated one work, or a different size?";
        }

        $nearest = $this->nearestSizes($item->type, $size, $fire ? true : null);

        if ($nearest === []) {
            return "We don't list a {$sizeText}{$label}. Can you share more detail so we can check alternatives?";
        }

        return "We don't list a {$sizeText}{$label}. Closest opening sizes we carry: ".implode(', ', $nearest).'. Would one of those work?';
    }

    private function sizeExists(string $type, array $size, ?bool $fireRated): bool
    {
        $query = $this->available()->where('product_type', $type);
        $this->whereOpening($query, $size['width'], $size['height']);

        return $query->when($fireRated !== null, fn ($q) => $q->where('fire_rated', $fireRated))->exists();
    }

    /** @return list<string> up to 3 sizes, closest by total inch difference (either orientation) */
    private function nearestSizes(string $type, ?array $size, ?bool $fireRated): array
    {
        if ($size === null) {
            return [];
        }

        [$w, $h] = [$size['width'], $size['height']];

        return $this->available()
            ->where('product_type', $type)
            ->whereNotNull('nominal_width')
            ->when($fireRated !== null, fn ($q) => $q->where('fire_rated', $fireRated))
            ->selectRaw('nominal_width, nominal_height, least(abs(nominal_width - ?) + abs(nominal_height - ?), abs(nominal_width - ?) + abs(nominal_height - ?)) as distance', [$w, $h, $h, $w])
            ->groupBy('nominal_width', 'nominal_height')
            ->orderBy('distance')
            ->limit(3)
            ->get()
            ->map(fn ($r) => $this->formatSize((float) $r->nominal_width, (float) $r->nominal_height))
            ->all();
    }

    /**
     * Clarifying questions that would narrow a list of matches. Only asked when the
     * answer would actually change which product is right.
     *
     * @param  Collection<int, Product>  $products
     * @return list<string>
     */
    private function optionalQuestions(RequestedItem $item, Collection $products): array
    {
        $questions = [];
        $known = $products->isNotEmpty();

        if (! $item->has('fire_rated') && (! $known || $products->pluck('fire_rated')->unique()->count() > 1)) {
            $questions[] = 'Does it need to be fire-rated?';
        }

        $placement = $item->has('application') || $item->has('surface_type') || $item->has('flange_type');
        $flanges = $products->flatMap(fn (Product $p) => $p->flange_type)->unique();

        if ($item->type === 'access_door' && ! $placement && (! $known || $flanges->count() > 1)) {
            $questions[] = 'Is it going into a wall or a ceiling, and what surface (drywall, plaster, masonry)? That decides the flange type.';
        }

        if ($item->type === 'floor_door' && ! $item->has('load_psf')) {
            $questions[] = 'What load does the floor door need to carry (foot traffic, e.g. 300 PSF, or vehicles, e.g. H-20)?';
        }

        if (! $item->has('quantity')) {
            $questions[] = 'How many do you need?';
        }

        return $questions;
    }

    private function formatSize(float $w, float $h): string
    {
        $fmt = fn (float $n) => rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.').'"';

        return $fmt($w).' x '.$fmt($h);
    }

    private function result(string $status, array $unknown, array $questions): array
    {
        return ['status' => $status, 'products' => collect(), 'best' => collect(), 'also' => collect(), 'fits' => [], 'questions' => $questions, 'unknown_names' => $unknown, 'pick' => null, 'shortlist' => null];
    }
}
