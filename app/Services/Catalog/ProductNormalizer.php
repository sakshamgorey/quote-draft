<?php

namespace App\Services\Catalog;

use App\Casts\PostgresArray;
use Illuminate\Support\Carbon;

/**
 * Turns raw Searchspring results into rows for the products upsert.
 *
 * Per-product attributes aren't in the result (no custom_* fields), so they
 * come from categories_hierarchy paths and the product name. Categories
 * overlap: floor doors are also listed under Access Doors, attic ladders
 * under Skylights, so product_type uses priority rules, not "first category".
 */
class ProductNormalizer
{
    private const FRACTIONS = ['½' => '.5', '¼' => '.25', '¾' => '.75', '⅛' => '.125', '⅜' => '.375', '⅝' => '.625', '⅞' => '.875'];

    /** One dimension: 12 | 22.5 | 22½ | 22 1/2 */
    private const DIM = '(\d+(?:\.\d+)?(?:[½¼¾⅛⅜⅝⅞]| \d\/\d{1,2})?)';

    /** @return array<string, array> keyed by uid (dedupes across pages and categories) */
    public function normalize(array $rawProducts): array
    {
        $now = now();
        $rows = [];

        foreach ($rawProducts as $raw) {
            $row = $this->row($raw);

            if ($row !== null) {
                $rows[$row['uid']] = $row + ['created_at' => $now, 'updated_at' => $now];
            }
        }

        return $rows;
    }

    public function row(array $raw): ?array
    {
        $uid = $this->clean($raw['uid'] ?? null);
        $sku = $this->clean($raw['sku'] ?? null);
        $name = $this->clean($raw['name'] ?? null);
        $url = $this->clean($raw['url'] ?? null);

        if ($uid === null || $sku === null || $name === null || $url === null) {
            return null;
        }

        $paths = array_values(array_filter(array_map(fn ($p) => $this->clean($p), (array) ($raw['categories_hierarchy'] ?? []))));
        $tags = $this->tags($paths, $name);
        [$width, $height] = $this->openingSize($name, $paths, $sku);

        return [
            'uid' => $uid,
            'sku' => $sku,
            'model' => $this->model($sku, $paths),
            'name' => $name,
            'url' => $url,
            'image_url' => $this->image($raw['imageUrl'] ?? $raw['thumbnailImageUrl'] ?? null),
            'product_type' => $this->productType($paths, $name),
            'nominal_width' => $width,
            'nominal_height' => $height,
            'overall_width' => $this->number($raw['width'] ?? null),
            'overall_height' => $this->number($raw['height'] ?? null),
            'price' => $this->number($raw['sale_price'] ?? $raw['price'] ?? null),
            'msrp' => $this->number($raw['msrp'] ?? null),
            'brand' => $this->clean($raw['brand'] ?? null),
            'is_available' => ($raw['availability'] ?? 'available') !== 'disabled',
            'popularity' => (int) ($raw['popularity'] ?? 0),
            'purchases' => (int) ($raw['ga_unique_purchases'] ?? 0),
            'rating' => is_numeric($raw['rating'] ?? null) ? round((float) $raw['rating'], 2) : null,
            'rating_count' => (int) ($raw['ratingCount'] ?? 0),
            'priced_at' => isset($raw['_fetched_at']) ? Carbon::createFromTimestamp($raw['_fetched_at']) : null,
            'fire_rated' => $this->fireRated($paths, $name),
            'insulated' => $this->insulated($paths, $name),
            'application' => PostgresArray::literal($tags['application']),
            'surface_type' => PostgresArray::literal($tags['surface_type']),
            'material' => PostgresArray::literal($tags['material']),
            'flange_type' => PostgresArray::literal($tags['flange_type']),
            'purpose' => PostgresArray::literal($tags['purpose']),
            'category_paths' => PostgresArray::literal($paths),
            'type_of_access' => PostgresArray::literal([]), // filled by FacetTagger
        ];
    }

    public function productType(array $paths, string $name): string
    {
        $tops = [];
        foreach ($paths as $path) {
            $parts = explode('>', $path);
            if ($parts[0] === 'Products' && isset($parts[1])) {
                $tops[$parts[1]] = true;
            }
        }

        $n = mb_strtolower($name);
        $mentionsLadder = str_contains($n, 'ladder') && ! str_contains($n, 'hatch');

        return match (true) {
            isset($tops['Safety Rails']) => 'safety_rail',
            isset($tops['Smoke Vents']) => 'smoke_vent',
            isset($tops['Valve Boxes']) => 'valve_box',
            isset($tops['Vent Covers']) => 'vent_cover',
            isset($tops['Door Louver & Window Kits']) => 'door_louver_window_kit',
            isset($tops['Ladders']) && ($mentionsLadder || ! isset($tops['Skylights'])) => 'ladder',
            isset($tops['Skylights']) => 'skylight',
            isset($tops['Roof & Floor Hatches']) && $mentionsLadder => 'ladder',
            // "Deck-Mount Skylight" is listed under hatches; "Roof Access Skylight" really is a hatch.
            isset($tops['Roof & Floor Hatches']) && str_contains($n, 'skylight') && ! preg_match('/hatch|access/', $n) => 'skylight',
            isset($tops['Roof & Floor Hatches']) => $this->isFloor($paths, $n) ? 'floor_door' : 'roof_hatch',
            default => 'access_door',
        };
    }

    private function isFloor(array $paths, string $lowerName): bool
    {
        if (str_contains($lowerName, 'floor')) {
            return true;
        }
        if (str_contains($lowerName, 'roof')) {
            return false;
        }

        return (bool) preg_grep('/^Products>(Roof & Floor Hatches>Application>Floor|Popular Picks>Floor Doors)(>|$)/', $paths);
    }

    /**
     * Opening size, in order: name prefix ('12" x 24" …'), "Shop By Size" path, SKU suffix (BA-AHD-12-24).
     *
     * @return array{0: ?float, 1: ?float}
     */
    public function openingSize(string $name, array $paths, string $sku): array
    {
        // Adjustable ranges ('6.5" to 8" EZMAG …'): no single opening size.
        if (preg_match('/^\s*'.self::DIM.'\s*"\s*to\s/u', $name)) {
            return [null, null];
        }

        if (preg_match('/^\s*'.self::DIM.'\s*"\s*x\s*'.self::DIM.'\s*"/u', $name, $m)) {
            return [$this->dimension($m[1]), $this->dimension($m[2])];
        }

        foreach ($paths as $path) {
            if (preg_match('/>Shop By Size>'.self::DIM.'"\s*x\s*'.self::DIM.'"$/u', $path, $m)) {
                return [$this->dimension($m[1]), $this->dimension($m[2])];
            }
        }

        if (preg_match('/-(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)(?:-[A-Z]+)?$/', $sku, $m)) {
            return [(float) $m[1], (float) $m[2]];
        }

        return [null, null];
    }

    public function model(string $sku, array $paths): string
    {
        foreach ($paths as $path) {
            if (preg_match('/^(?:Products>[^>]+>)?Model Number>([^>]+)$/', $path, $m)) {
                return $m[1];
            }
        }

        return preg_replace('/(-\d+(?:\.\d+)?){1,3}(-[A-Z]+)?$/', '', $sku) ?: $sku;
    }

    /** @return array{application: list<string>, surface_type: list<string>, material: list<string>, flange_type: list<string>, purpose: list<string>} */
    private function tags(array $paths, string $name): array
    {
        $tags = ['application' => [], 'surface_type' => [], 'material' => [], 'flange_type' => [], 'purpose' => []];
        $map = config('catalog.application_map');

        foreach ($paths as $path) {
            $parts = explode('>', $path);
            if ($parts[0] !== 'Products' || count($parts) < 4) {
                continue;
            }

            [, , $facet, $value] = $parts;

            if ($facet === 'Application') {
                foreach ($map[$value] ?? [] as $column => $values) {
                    array_push($tags[$column], ...$values);
                }
            } elseif ($facet === 'Material') {
                array_push($tags['material'], ...preg_split('/\s+and\s+/', $value));
            } elseif ($facet === 'Type') {
                $tags['purpose'][] = $value;
            }
        }

        $haystack = $name.' '.implode(' ', $paths);

        // "Wood Attic Ladder" / "…>Material>Triple Fiber Reinforced Cement" describe what the
        // product is made of, not the surface it goes into.
        $surfaceHaystack = $name.' '.implode(' ', preg_grep('/>Material>/', $paths, PREG_GREP_INVERT));
        $madeOf = implode(' ', $tags['material']);

        foreach (config('catalog.surface_keywords') as $word) {
            if (stripos($madeOf, $word) === false && preg_match('/\b'.preg_quote($word, '/').'\b/i', $surfaceHaystack)) {
                $tags['surface_type'][] = $word;
            }
        }

        foreach (config('catalog.flange_keywords') as $phrase => $flange) {
            if (stripos($haystack, $phrase) !== false) {
                $tags['flange_type'][] = $flange;
            }
        }

        // Load ratings: "300 PSF", "300 lbs/sqft", "150 lb/sq ft" → "300 PSF".
        if (preg_match_all('/(\d{2,4})\s*(?:psf|lbs?\.?\s*\/\s*sq\.?\s*ft|lb\/sqft)\b/i', $name, $m)) {
            foreach ($m[1] as $load) {
                $tags['purpose'][] = "{$load} PSF";
            }
        }

        $aliases = config('catalog.aliases');
        $drop = array_map('mb_strtolower', config('catalog.drop_surface'));

        foreach ($tags as $column => $values) {
            $values = array_map(fn ($v) => $aliases[$v] ?? $v, array_map('trim', $values));
            if ($column === 'surface_type') {
                $values = array_filter($values, fn ($v) => ! in_array(mb_strtolower($v), $drop, true));
            }
            $tags[$column] = array_values(array_unique(array_filter($values)));
        }

        return $tags;
    }

    private function fireRated(array $paths, string $name): bool
    {
        // The store files airtight/watertight doors under "Application>Plaster>Fire-Rated"
        // although nothing about them is fire-rated. Calling a door fire-rated when it
        // isn't is the costly mistake, so that branch doesn't count.
        $paths = preg_grep('/>Application>Plaster>Fire-Rated/', $paths, PREG_GREP_INVERT);

        return (bool) preg_match('/fire[- ]rated/i', $name.' '.implode(' ', $paths));
    }

    private function insulated(array $paths, string $name): ?bool
    {
        if (preg_match('/\b(un-?insulated|non-?insulated)\b/i', $name)) {
            return false;
        }

        return preg_match('/\binsulated\b/i', $name.' '.implode(' ', $paths)) ? true : null;
    }

    private function dimension(string $value): float
    {
        if (preg_match('/^(\d+(?:\.\d+)?) (\d)\/(\d{1,2})$/', $value, $m)) {
            return (float) $m[1] + (int) $m[2] / (int) $m[3];
        }

        return (float) strtr($value, self::FRACTIONS);
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    /** BigCommerce stencil URLs take any size; the feed's 129x170 thumbnail is too small. */
    private function image(mixed $value): ?string
    {
        $url = $this->clean($value);

        if ($url === null || ! str_starts_with($url, 'https://')) {
            return null;
        }

        return preg_replace('#/stencil/\d+x\d+/#', '/stencil/320w/', $url);
    }

    /** Searchspring HTML-encodes text, sometimes twice (12&amp;quot;): decode twice. */
    private function clean(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = html_entity_decode(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : $value;
    }
}
