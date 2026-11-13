<?php

namespace App\Services\Searchspring;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class SearchspringClient
{
    /**
     * GET /api/search/category.json for one page of one category.
     *
     * No retries: on 429/5xx we stop and tell the user rather than hammer.
     *
     * @throws RateLimitedByRemote on 429
     * @throws RemoteUnavailable on 5xx / timeouts / non-JSON body
     * @throws InvalidResponse on other 4xx
     */
    public function categoryPage(string $category, int $page, array $filters = []): array
    {
        // Facet filters ("filter.custom_surface_type" => "Masonry") are how per-product
        // attribute values are learned: results never carry the custom_* fields themselves.
        $filterParams = [];
        foreach ($filters as $field => $value) {
            $filterParams["filter.{$field}"] = $value;
        }

        try {
            $response = $this->http()->get('/api/search/category.json', [
                'siteId' => config('searchspring.site_id'),
                'resultsFormat' => 'native',
                'bgfilter.categories_hierarchy' => $category,
                ...$filterParams,
                'resultsPerPage' => config('searchspring.per_page'),
                'page' => $page,
            ]);
        } catch (ConnectionException $e) {
            throw new RemoteUnavailable('Searchspring unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($response->status() === 429) {
            throw new RateLimitedByRemote((int) ($response->header('Retry-After') ?: 60));
        }

        if ($response->serverError()) {
            throw new RemoteUnavailable("Searchspring returned HTTP {$response->status()}");
        }

        if ($response->failed()) {
            throw new InvalidResponse("Searchspring returned HTTP {$response->status()}");
        }

        $json = $response->json();

        if (! is_array($json) || ! is_array($json['results'] ?? null) || ! is_array($json['pagination'] ?? null)) {
            throw new RemoteUnavailable('Searchspring returned a non-JSON or unexpected body');
        }

        return $json;
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl(config('searchspring.base_url'))
            ->withUserAgent(config('searchspring.user_agent'))
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(config('searchspring.timeout'));
    }
}
