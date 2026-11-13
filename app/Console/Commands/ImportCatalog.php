<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Catalog\FacetTagger;
use App\Services\Catalog\ProductNormalizer;
use App\Services\Searchspring\FacetStore;
use App\Services\Searchspring\InvalidResponse;
use App\Services\Searchspring\PageStore;
use App\Services\Searchspring\RateLimitedByRemote;
use App\Services\Searchspring\RemoteUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportCatalog extends Command
{
    protected $signature = 'catalog:import
        {--pages= : Pages per category (default searchspring.max_pages)}
        {--fresh : Re-fetch pages even if saved files exist}';

    protected $description = 'Import Best Access Doors products from saved Searchspring pages (fetching only missing ones)';

    public function handle(PageStore $store, FacetStore $facets, FacetTagger $tagger, ProductNormalizer $normalizer): int
    {
        $pages = (int) ($this->option('pages') ?: config('searchspring.max_pages'));
        $log = fn (string $m) => $this->line("  {$m}");

        try {
            $files = $store->ensure($pages, (bool) $this->option('fresh'), $log);
            $tagger->load($facets->ensure((bool) $this->option('fresh'), $log));
        } catch (RateLimitedByRemote|RemoteUnavailable|InvalidResponse $e) {
            $this->error($e->getMessage());
            $this->warn('Stopped. Saved pages are kept; re-run later to fetch the rest.');

            return self::FAILURE;
        }

        // Facet-page results first: products outside the 9 categories still get in,
        // and the category pages (read after) win for everything else.
        $raw = $tagger->rawResults();
        foreach ($files as $paths) {
            foreach ($paths as $path) {
                // Prices are only as fresh as the saved page they came from.
                $fetchedAt = File::lastModified($path);

                foreach (json_decode(File::get($path), true)['results'] ?? [] as $result) {
                    $raw[] = $result + ['_fetched_at' => $fetchedAt];
                }
            }
        }

        $rows = $tagger->apply($normalizer->normalize($raw));

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                Product::upsert($chunk, ['uid'], array_diff(array_keys(reset($chunk)), ['uid', 'created_at']));
            }
        });

        $this->summary(count($raw), count($rows));

        return self::SUCCESS;
    }

    private function summary(int $seen, int $imported): void
    {
        $this->info("Imported {$imported} products ({$seen} results read, duplicates across categories merged).");

        $this->table(['product_type', 'products'], Product::query()
            ->selectRaw('product_type, count(*) as n')
            ->groupBy('product_type')->orderByDesc('n')
            ->get()->map(fn ($r) => [$r->product_type, $r->n])->all());

        $total = max(1, Product::count());
        $checks = [
            'nominal size' => 'nominal_width is not null',
            'overall size' => 'overall_width is not null',
            'image_url' => 'image_url is not null',
            'price' => 'price is not null',
            'available' => 'is_available',
            'brand' => 'brand is not null',
            'type_of_access' => "type_of_access <> '{}'",
            'application' => "application <> '{}'",
            'surface_type' => "surface_type <> '{}'",
            'material' => "material <> '{}'",
            'flange_type' => "flange_type <> '{}'",
            'purpose' => "purpose <> '{}'",
            'fire_rated = true' => 'fire_rated',
            'insulated known' => 'insulated is not null',
        ];

        $this->table(['field', 'coverage'], collect($checks)->map(function ($where, $field) use ($total) {
            $n = Product::whereRaw($where)->count();

            return [$field, sprintf('%d (%d%%)', $n, round($n * 100 / $total))];
        })->values()->all());
    }
}
