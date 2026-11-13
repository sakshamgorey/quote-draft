<?php

namespace App\Services\ProductPages;

use App\Models\Product;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Saves product pages to {storage_path}/{sku}.html, politely: robots.txt is read
 * first, disallowed paths are skipped, and requests are spaced by the larger of
 * our minimum pause and the robots Crawl-delay. A 429 or 5xx stops the run.
 */
class PageFetcher
{
    private ?RobotsRules $robots = null;

    private ?float $lastRequestAt = null;

    public function path(Product $product): string
    {
        return config('product_pages.storage_path').'/'.preg_replace('/[^A-Za-z0-9._-]/', '_', $product->sku).'.html';
    }

    public function has(Product $product, ?int $staleAfterDays = null): bool
    {
        $path = $this->path($product);

        return File::exists($path) && ($staleAfterDays === null || File::lastModified($path) >= now()->subDays($staleAfterDays)->getTimestamp());
    }

    public function robots(): RobotsRules
    {
        return $this->robots ??= RobotsRules::parse($this->get('https://www.bestaccessdoors.com/robots.txt')->body(), config('product_pages.user_agent'));
    }

    /** Seconds between page requests. */
    public function pause(): float
    {
        return max(config('product_pages.sleep_ms') / 1000, $this->robots()->crawlDelay ?? 0);
    }

    /**
     * @return 'saved'|'disallowed'|'not_found'
     *
     * @throws StoreRefused on 429 / 5xx / unreachable: stop the run
     */
    public function fetch(Product $product): string
    {
        if (! $this->robots()->allows($product->url)) {
            return 'disallowed';
        }

        $response = $this->get($product->url);

        if ($response->status() === 404 || $response->status() === 410) {
            return 'not_found';
        }

        File::ensureDirectoryExists(dirname($this->path($product)));
        File::put($this->path($product), $response->body());

        return 'saved';
    }

    private function get(string $url): Response
    {
        if ($this->lastRequestAt !== null) {
            $wait = $this->lastRequestAt + $this->pause() - microtime(true);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
        }

        try {
            $response = Http::withUserAgent(config('product_pages.user_agent'))
                ->timeout(config('product_pages.timeout'))
                ->get($url);
        } catch (ConnectionException $e) {
            throw new StoreRefused("Store unreachable: {$e->getMessage()}", previous: $e);
        } finally {
            $this->lastRequestAt = microtime(true);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new StoreRefused("Store returned HTTP {$response->status()} for {$url}");
        }

        if ($response->failed() && ! in_array($response->status(), [404, 410], true)) {
            throw new RuntimeException("Store returned HTTP {$response->status()} for {$url}");
        }

        return $response;
    }
}
