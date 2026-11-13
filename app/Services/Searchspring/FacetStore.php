<?php

namespace App\Services\Searchspring;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Per-product facet values, learned by asking for each value's product list.
 *
 * Searchspring results never include custom_* attributes; they only exist as
 * filters. So for every value of every configured facet we fetch
 * "filter.{field}={value}" and save the pages under
 * {storage_path}/facets/{field}/{value-slug}/page-N.json. Reads saved files first.
 */
class FacetStore
{
    private bool $requested = false;

    public function __construct(private SearchspringClient $client) {}

    /**
     * @param  Closure(string): void  $log
     * @return array<string, array<string, list<string>>> field => value => saved file paths
     */
    public function ensure(bool $fresh = false, ?Closure $log = null): array
    {
        $log ??= fn () => null;
        $files = [];

        foreach ($this->values() as $field => $values) {
            foreach ($values as $value) {
                $first = $this->page($field, $value, 1, $fresh, $log);
                $files[$field][$value] = [$this->path($field, $value, 1)];

                for ($page = 2; $page <= (int) ($first['pagination']['totalPages'] ?? 1); $page++) {
                    $this->page($field, $value, $page, $fresh, $log);
                    $files[$field][$value][] = $this->path($field, $value, $page);
                }
            }
        }

        return $files;
    }

    /** @return array<string, list<string>> configured field => every value in the whole-catalog facet */
    public function values(): array
    {
        $catalog = config('searchspring.storage_path').'/page-1.json';

        if (! File::exists($catalog)) {
            return [];
        }

        $facets = collect(json_decode(File::get($catalog), true)['facets'] ?? [])->keyBy('field');

        return collect(config('searchspring.facet_fields'))
            ->keys()
            ->mapWithKeys(fn ($field) => [$field => collect($facets[$field]['values'] ?? [])->pluck('value')->filter()->values()->all()])
            ->all();
    }

    public function path(string $field, string $value, int $page): string
    {
        // Hash suffix: "300 psf Load" and "300 PSF Load" slug the same.
        $dir = Str::slug($value).'-'.substr(md5($value), 0, 6);

        return config('searchspring.storage_path')."/facets/{$field}/{$dir}/page-{$page}.json";
    }

    private function page(string $field, string $value, int $page, bool $fresh, Closure $log): array
    {
        $path = $this->path($field, $value, $page);

        if (! $fresh && File::exists($path)) {
            return json_decode(File::get($path), true);
        }

        if ($this->requested) {
            usleep(config('searchspring.sleep_ms') * 1000);
        }

        $this->requested = true;
        $json = $this->client->categoryPage('Products', $page, [$field => $value]);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($json + ['_facet' => ['field' => $field, 'value' => $value]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $log("fetched {$field}={$value} page {$page} ({$json['pagination']['totalResults']} products)");

        return $json;
    }
}
