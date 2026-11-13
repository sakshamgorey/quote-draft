<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductPages\PageFetcher;
use App\Services\ProductPages\StoreRefused;
use Illuminate\Console\Command;

class FetchProductPages extends Command
{
    protected $signature = 'catalog:fetch-pages
        {--sku=* : Only these SKUs}
        {--type= : Only this product_type}
        {--per-type= : Sample: the N most popular products of each type}
        {--limit= : Stop after this many fetched pages}
        {--fresh : Re-fetch even if the page is saved}
        {--stale-after= : Re-fetch saved pages older than this many days}
        {--include-unavailable : Also products disabled on the store}';

    protected $description = 'Save product pages from bestaccessdoors.com (robots.txt aware, rate limited, resumable)';

    public function handle(PageFetcher $fetcher): int
    {
        $products = $this->products();
        $stale = $this->option('stale-after') !== null ? (int) $this->option('stale-after') : null;
        $todo = $products->filter(fn (Product $p) => $this->option('fresh') || ! $fetcher->has($p, $stale))->values();

        if ($limit = (int) $this->option('limit')) {
            $todo = $todo->take($limit);
        }

        if ($todo->isEmpty()) {
            $this->info("Nothing to fetch ({$products->count()} products selected, all saved).");

            return self::SUCCESS;
        }

        try {
            $pause = $fetcher->pause();
        } catch (StoreRefused $e) {
            $this->error("Couldn't read robots.txt: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf('Fetching %d of %d pages, %.1f s apart (about %d min). Saved pages are skipped next time.',
            $todo->count(), $products->count(), $pause, (int) ceil($todo->count() * $pause / 60)));

        $counts = ['saved' => 0, 'disallowed' => 0, 'not_found' => 0];

        foreach ($todo as $i => $product) {
            try {
                $result = $fetcher->fetch($product);
            } catch (StoreRefused $e) {
                $this->newLine();
                $this->error($e->getMessage());
                $this->warn("Stopped after {$i} pages. Re-run later to continue where it stopped.");

                return self::FAILURE;
            }

            $counts[$result]++;
            $this->line(sprintf('  [%d/%d] %-24s %s', $i + 1, $todo->count(), $product->sku, $result));
        }

        $this->info(sprintf('Done: %d saved, %d disallowed by robots.txt, %d not found.', ...array_values($counts)));
        $this->line('Next: php artisan catalog:parse-pages');

        return self::SUCCESS;
    }

    private function products()
    {
        $query = Product::query()
            ->when(! $this->option('include-unavailable'), fn ($q) => $q->where('is_available', true))
            ->when($this->option('sku'), fn ($q, $skus) => $q->whereIn('sku', $skus))
            ->when($this->option('type'), fn ($q, $type) => $q->where('product_type', $type))
            ->orderByDesc('popularity')
            ->orderBy('id');

        if ($perType = (int) $this->option('per-type')) {
            return $query->get()->groupBy('product_type')->flatMap(fn ($group) => $group->take($perType))->values();
        }

        return $query->get();
    }
}
