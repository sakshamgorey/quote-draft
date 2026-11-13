<?php

namespace App\Services\Searchspring;

use Closure;
use Illuminate\Support\Facades\File;

/**
 * Raw Searchspring responses on disk: {storage_path}/{category-slug}/page-N.json.
 * Reads saved files first and fetches only what's missing (or everything with $fresh).
 */
class PageStore
{
    private bool $requested = false;

    public function __construct(private SearchspringClient $client) {}

    /**
     * Make sure pages 1..min(totalPages, $maxPages) of every category are on disk.
     *
     * @param  Closure(string): void  $log
     * @return array<string, list<string>> slug => saved file paths
     */
    public function ensure(int $maxPages, bool $fresh = false, ?Closure $log = null): array
    {
        $log ??= fn () => null;
        $files = [];

        foreach (config('searchspring.categories') as $slug => $category) {
            $first = $this->page($slug, $category, 1, $fresh, $log);
            $last = min((int) ($first['pagination']['totalPages'] ?? 1), $maxPages);
            $files[$slug] = [$this->path($slug, 1)];

            for ($page = 2; $page <= $last; $page++) {
                $this->page($slug, $category, $page, $fresh, $log);
                $files[$slug][] = $this->path($slug, $page);
            }
        }

        return $files;
    }

    public function path(string $slug, int $page): string
    {
        return config('searchspring.storage_path')."/{$slug}/page-{$page}.json";
    }

    private function page(string $slug, string $category, int $page, bool $fresh, Closure $log): array
    {
        $path = $this->path($slug, $page);

        if (! $fresh && File::exists($path)) {
            return json_decode(File::get($path), true);
        }

        if ($this->requested) {
            usleep(config('searchspring.sleep_ms') * 1000);
        }

        $this->requested = true;
        $json = $this->client->categoryPage($category, $page);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $log("fetched {$slug} page {$page} ({$json['pagination']['totalResults']} in category)");

        return $json;
    }
}
