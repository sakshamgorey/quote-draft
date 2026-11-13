<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductSearchController extends Controller
{
    /** Multi-value facets backed by text[] columns (OR within a facet, AND across facets). */
    private const TAG_FACETS = [
        'application' => 'Application',
        'surface_type' => 'Surface',
        'material' => 'Material',
        'flange_type' => 'Flange',
        'purpose' => 'Purpose & features',
    ];

    /** Price buckets in dollars: [label, min, max]. */
    private const PRICE_RANGES = [
        'under-100' => ['Under $100', null, 100],
        '100-250' => ['$100 – $250', 100, 250],
        '250-500' => ['$250 – $500', 250, 500],
        '500-1000' => ['$500 – $1,000', 500, 1000],
        '1000-2500' => ['$1,000 – $2,500', 1000, 2500],
        '2500-plus' => ['$2,500 and up', 2500, null],
        'on-request' => ['Price on request', null, null],
    ];

    private const SORTS = ['relevance', 'popular', 'price_asc', 'price_desc', 'size'];

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('products/index', [
            'filters' => $filters,
            'products' => fn () => $this->present($this->search($filters)->withQueryString()),
            'facets' => fn () => $this->facets($filters),
        ]);
    }

    /** Small JSON search for "add a product" on the draft page. */
    public function lookup(Request $request): JsonResponse
    {
        // Drafts may only use products the store still sells.
        $filters = ['show_unavailable' => false] + $this->filters($request);

        if ($filters['q'] === '') {
            return response()->json(['data' => []]);
        }

        $page = $this->search($filters);

        return response()->json([
            'data' => collect($page->items())->take(8)->map(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'size' => $p->openingSize(),
                'price_cents' => $p->price === null ? null : (int) round((float) $p->price * 100),
                'image_url' => $p->image_url,
            ])->values(),
        ]);
    }

    /**
     * @return array{q: string, type: string, width: string, height: string, fire_rated: bool, insulated: bool, show_unavailable: bool,
     *     brand: list<string>, application: list<string>, surface_type: list<string>, material: list<string>,
     *     flange_type: list<string>, purpose: list<string>, price: list<string>, sort: string}
     */
    private function filters(Request $request): array
    {
        $type = (string) $request->query('type', '');
        $size = fn (string $key) => is_numeric($v = $request->query($key)) && $v > 0 && $v < 1000 ? (string) (float) $v : '';
        $list = fn (string $key) => collect((array) $request->query($key, []))
            ->filter(fn ($v) => is_string($v) && $v !== '' && mb_strlen($v) <= 100)
            ->unique()->take(20)->values()->all();
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $sort = (string) $request->query('sort', '');

        return [
            'q' => $q,
            'type' => array_key_exists($type, Product::TYPE_LABELS) ? $type : '',
            'width' => $size('width'),
            'height' => $size('height'),
            'fire_rated' => $request->boolean('fire_rated'),
            'insulated' => $request->boolean('insulated'),
            'show_unavailable' => $request->boolean('show_unavailable'),
            'brand' => $list('brand'),
            ...collect(self::TAG_FACETS)->map(fn ($label, $column) => $list($column))->all(),
            'price' => array_values(array_intersect($list('price'), array_keys(self::PRICE_RANGES))),
            'sort' => in_array($sort, self::SORTS, true) ? $sort : ($q !== '' ? 'relevance' : 'popular'),
        ];
    }

    private function search(array $f): LengthAwarePaginator
    {
        // Text: WHERE search_vector @@ websearch_to_tsquery(...) OR name % ... (pg_trgm),
        //       ranked by ts_rank + similarity unless another sort is picked.
        $builder = Product::search($f['q'])
            ->query(fn (Builder $query) => $this->applyFilters($query
                ->select(['id', 'sku', 'model', 'name', 'url', 'image_url', 'product_type', 'nominal_width', 'nominal_height',
                    'price', 'msrp', 'brand', 'rating', 'rating_count', 'fire_rated', 'is_available', 'material', 'flange_type', 'application', 'surface_type']), $f));

        $sort = $f['sort'] === 'relevance' && $f['q'] === '' ? 'popular' : $f['sort'];

        match ($sort) {
            'relevance' => null, // the engine's rank order
            'price_asc' => $builder->orderBy('price')->orderBy('id'),
            'price_desc' => $builder->orderBy('price', 'desc')->orderBy('id'),
            'size' => $builder->orderBy('nominal_width')->orderBy('nominal_height')->orderBy('id'),
            default => $builder->orderBy('popularity', 'desc')->orderBy('model')->orderBy('nominal_width'),
        };

        return $builder->paginate(25)
            // Scout appends ?query=<term> to page links; we already carry ?q=.
            ->appends('query', null);
    }

    /**
     * Every filter except $except (so a facet's own counts ignore its own selection),
     * plus "available only" unless the user ticked "Show unavailable".
     */
    private function applyFilters(Builder $query, array $f, ?string $except = null): Builder
    {
        $query->when(! $f['show_unavailable'], fn ($q) => $q->where('is_available', true))
            ->when($except !== 'type' && $f['type'], fn ($q) => $q->where('product_type', $f['type']))
            ->when($f['fire_rated'], fn ($q) => $q->where('fire_rated', true))
            ->when($f['insulated'], fn ($q) => $q->where('insulated', true))
            ->when($f['width'] !== '' || $f['height'] !== '', fn ($q) => $this->whereOpening($q, $f['width'], $f['height']))
            ->when($except !== 'brand' && $f['brand'], fn ($q) => $q->whereIn('brand', $f['brand']))
            ->when($except !== 'price' && $f['price'], fn ($q) => $this->wherePrice($q, $f['price']));

        foreach (array_keys(self::TAG_FACETS) as $column) {
            if ($except !== $column && $f[$column]) {
                $query->whereRaw("{$column} && ?::text[]", [$this->pgArray($f[$column])]);
            }
        }

        return $query;
    }

    /** Same text match as the pgsql Scout engine, for facet counts (Scout only builds the result query). */
    private function whereText(Builder $query, string $q): Builder
    {
        return $q === '' ? $query : $query->where(fn ($w) => $w
            ->whereRaw("search_vector @@ websearch_to_tsquery('english', ?)", [$q])
            ->orWhereRaw('name % ?', [$q]));
    }

    /**
     * @return list<array{key: string, label: string, multiple: bool, options: list<array{value: string, label: string, count: int}>}>
     */
    private function facets(array $f): array
    {
        $base = fn (string $except) => $this->whereText($this->applyFilters(Product::query(), $f, $except), $f['q']);

        $type = $base('type')->selectRaw('product_type as value, count(*) as n')->groupBy('product_type')->pluck('n', 'value');
        $facets = [[
            'key' => 'type', 'label' => 'Product type', 'multiple' => false,
            'options' => collect(Product::TYPE_LABELS)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label, 'count' => (int) ($type[$value] ?? 0)])
                ->filter(fn ($o) => $o['count'] > 0 || $f['type'] === $o['value'])->values()->all(),
        ]];

        $brands = $base('brand')->whereNotNull('brand')->selectRaw('brand as value, count(*) as n')
            ->groupBy('brand')->orderByDesc('n')->orderBy('brand')->pluck('n', 'value');
        $facets[] = $this->facet('brand', 'Brand', $brands, $f['brand']);

        foreach (self::TAG_FACETS as $column => $label) {
            $counts = DB::query()
                ->fromSub($base($column)->selectRaw("unnest({$column}) as value"), 'tags')
                ->selectRaw('value, count(*) as n')->groupBy('value')->orderByDesc('n')->orderBy('value')->limit(60)
                ->pluck('n', 'value');
            $facets[] = $this->facet($column, $label, $counts, $f[$column]);
        }

        $priceQuery = $base('price');
        $priceCounts = collect(self::PRICE_RANGES)->map(fn ($range, $key) => (clone $priceQuery)
            ->tap(fn ($q) => $this->wherePrice($q, [$key]))->count());
        $facets[] = [
            'key' => 'price', 'label' => 'Price', 'multiple' => true,
            'options' => collect(self::PRICE_RANGES)
                ->map(fn ($range, $key) => ['value' => $key, 'label' => $range[0], 'count' => $priceCounts[$key]])
                ->filter(fn ($o) => $o['count'] > 0 || in_array($o['value'], $f['price'], true))->values()->all(),
        ];

        return array_values(array_filter($facets, fn ($facet) => $facet['options'] !== []));
    }

    /** Options sorted by count, keeping selected values even when their count drops to 0. */
    private function facet(string $key, string $label, $counts, array $selected): array
    {
        $options = collect($counts)->map(fn ($n, $value) => ['value' => (string) $value, 'label' => (string) $value, 'count' => (int) $n]);

        foreach ($selected as $value) {
            if (! $options->has($value)) {
                $options->put($value, ['value' => $value, 'label' => $value, 'count' => 0]);
            }
        }

        return ['key' => $key, 'label' => $label, 'multiple' => true, 'options' => $options->values()->all()];
    }

    /** @param  list<string>  $ranges  PRICE_RANGES keys, OR'd */
    private function wherePrice(Builder $query, array $ranges): void
    {
        $query->where(function ($q) use ($ranges) {
            foreach ($ranges as $key) {
                [, $min, $max] = self::PRICE_RANGES[$key];

                $q->orWhere(fn ($r) => $key === 'on-request'
                    ? $r->whereNull('price')
                    : $r->when($min !== null, fn ($r) => $r->where('price', '>=', $min))
                        ->when($max !== null, fn ($r) => $r->where('price', '<', $max))
                        ->whereNotNull('price'));
            }
        });
    }

    /** Opening size in either orientation: 24 x 36 also finds 36 x 24. */
    private function whereOpening(Builder $query, string $width, string $height): void
    {
        if ($width !== '' && $height !== '') {
            $query->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('nominal_width', $width)->where('nominal_height', $height))
                ->orWhere(fn ($q) => $q->where('nominal_width', $height)->where('nominal_height', $width)));

            return;
        }

        $side = $width !== '' ? $width : $height;
        $query->where(fn ($q) => $q->where('nominal_width', $side)->orWhere('nominal_height', $side));
    }

    /** @param  list<string>  $values */
    private function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(fn ($v) => '"'.addcslashes($v, '"\\').'"', $values)).'}';
    }

    /** Shape the paginator for the React page: only what it renders. */
    private function present(LengthAwarePaginator $page): array
    {
        $current = $page->currentPage();
        $from = max(1, $current - 2);
        $to = min($page->lastPage(), $current + 2);

        return [
            'data' => $page->getCollection()->map(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'model' => $p->model,
                'name' => $p->name,
                'url' => $p->url,
                'image_url' => $p->image_url,
                'product_type' => $p->product_type,
                'type_label' => $p->typeLabel(),
                'brand' => $p->brand,
                'size' => $p->openingSize(),
                'fire_rated' => (bool) $p->fire_rated,
                'is_available' => (bool) $p->is_available,
                'price_cents' => $p->price === null ? null : (int) round((float) $p->price * 100),
                'msrp_cents' => $p->msrp === null ? null : (int) round((float) $p->msrp * 100),
                'rating' => $p->rating === null ? null : (float) $p->rating,
                'rating_count' => (int) $p->rating_count,
                'tags' => array_slice(array_values(array_unique([...$p->surface_type, ...$p->flange_type, ...$p->material])), 0, 4),
            ])->all(),
            'current_page' => $current,
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
            'prev_url' => $page->previousPageUrl(),
            'next_url' => $page->nextPageUrl(),
            'first_url' => $page->url(1),
            'last_url' => $page->url($page->lastPage()),
            'window' => collect($page->getUrlRange($from, $to))
                ->map(fn ($url, $n) => ['page' => $n, 'url' => $url])
                ->values()
                ->all(),
        ];
    }
}
