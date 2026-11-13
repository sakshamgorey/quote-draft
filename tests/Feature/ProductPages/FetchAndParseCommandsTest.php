<?php

use App\Models\ProductLink;
use App\Services\ProductPages\RobotsRules;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

const ROBOTS = <<<'TXT'
User-agent: *
Disallow: /search.php
Disallow: /brands/*
Disallow: /*sort=

User-agent: ClaudeBot
User-agent: GPTBot
Crawl-delay: 10
Disallow: /checkout
TXT;

beforeEach(function () {
    $this->dir = storage_path('framework/testing/product-pages');
    File::deleteDirectory($this->dir);
    config(['product_pages.storage_path' => $this->dir, 'product_pages.sleep_ms' => 0]);
    Http::preventStrayRequests();
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('follows the robots.txt group for our agent', function () {
    $ours = RobotsRules::parse(ROBOTS, 'BestQuoteDraft/0.1 (catalog import)');
    $claude = RobotsRules::parse(ROBOTS, 'ClaudeBot/1.0');

    expect($ours->crawlDelay)->toBeNull()
        ->and($ours->allows('https://x.test/30-x-36-roof-hatch/'))->toBeTrue()
        ->and($ours->allows('https://x.test/search.php?q=a'))->toBeFalse()
        ->and($ours->allows('https://x.test/brands/acudor'))->toBeFalse()
        ->and($ours->allows('https://x.test/doors/?sort=price'))->toBeFalse()
        ->and($claude->crawlDelay)->toBe(10.0);
});

it('saves pages, skips saved ones and robots-disallowed ones', function () {
    $hatch = product(['sku' => 'BA-RH-1', 'url' => 'https://www.bestaccessdoors.com/roof-hatch-1/']);
    product(['sku' => 'BA-BR-1', 'url' => 'https://www.bestaccessdoors.com/brands/acudor']);
    Http::fake([
        '*/robots.txt' => Http::response(ROBOTS),
        '*/roof-hatch-1/' => Http::response('<html>hatch</html>'),
    ]);

    $this->artisan('catalog:fetch-pages')->expectsOutputToContain('1 saved, 1 disallowed by robots.txt')->assertSuccessful();
    // Second run: the saved page is skipped; the disallowed one is re-checked against robots.txt only.
    $this->artisan('catalog:fetch-pages')->expectsOutputToContain('0 saved, 1 disallowed by robots.txt')->assertSuccessful();

    expect(File::get("{$this->dir}/BA-RH-1.html"))->toBe('<html>hatch</html>')
        ->and(File::exists("{$this->dir}/BA-BR-1.html"))->toBeFalse();
    Http::assertSentCount(3); // robots.txt twice + the hatch page once; the disallowed page never
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/brands/'));
});

it('stops at the first 429 and resumes later from where it stopped', function () {
    product(['sku' => 'BA-A-1', 'url' => 'https://www.bestaccessdoors.com/a/', 'popularity' => 3]);
    product(['sku' => 'BA-B-1', 'url' => 'https://www.bestaccessdoors.com/b/', 'popularity' => 2]);
    Http::fake([
        '*/robots.txt' => Http::response(ROBOTS),
        '*/a/' => Http::response('<html>a</html>'),
        '*/b/' => Http::response('slow down', 429),
    ]);

    $this->artisan('catalog:fetch-pages')->expectsOutputToContain('HTTP 429')->assertFailed();

    expect(File::exists("{$this->dir}/BA-A-1.html"))->toBeTrue()
        ->and(File::exists("{$this->dir}/BA-B-1.html"))->toBeFalse();
});

it('skips products disabled on the store unless asked', function () {
    product(['sku' => 'BA-OFF-1', 'url' => 'https://www.bestaccessdoors.com/off/', 'is_available' => false]);
    Http::fake(['*/robots.txt' => Http::response(ROBOTS), '*/off/' => Http::response('<html>off</html>')]);

    $this->artisan('catalog:fetch-pages')->expectsOutputToContain('Nothing to fetch');
    $this->artisan('catalog:fetch-pages --include-unavailable')->assertSuccessful();

    expect(File::exists("{$this->dir}/BA-OFF-1.html"))->toBeTrue();
});

it('imports options, specs and size siblings, and re-parsing replaces rather than duplicates', function () {
    File::ensureDirectoryExists($this->dir);
    File::copy(base_path('tests/Fixtures/product-pages/BA-RAH-30-36.html'), "{$this->dir}/BA-RAH-30-36.html");

    $rah = product(['sku' => 'BA-RAH-30-36', 'model' => 'BA-RAH', 'product_type' => 'roof_hatch', 'nominal_width' => 30, 'nominal_height' => 36,
        'price' => 1887.26, 'url' => 'https://www.bestaccessdoors.com/30-x-36-roof-access-hatch-with-woodfesco-curb/']);
    // A sibling size under a different slug than the size picker uses.
    $sibling = product(['sku' => 'BA-RAH-24-24', 'model' => 'BA-RAH', 'product_type' => 'roof_hatch', 'nominal_width' => 24, 'nominal_height' => 24,
        'url' => 'https://www.bestaccessdoors.com/24-x-24-roof-access-hatch-with-woodfesco-curb/']);

    $this->artisan('catalog:parse-pages')->assertSuccessful();
    $this->artisan('catalog:parse-pages')->assertSuccessful();

    $rah->refresh();
    $color = $rah->options->firstWhere('label', 'Choose a Color');

    expect($rah->options)->toHaveCount(2)
        ->and($color->values->pluck('price_delta_cents', 'name')->all())->toBe(['Gray' => 0, 'Red' => 12195])
        ->and($rah->page->gauge)->toBe(14)
        ->and($rah->page->insulated_basis)->toBe('not_mentioned')
        ->and($rah->page->warnings)->toBe([]) // page price matches the feed
        ->and($rah->links()->where('kind', ProductLink::SIZE_SIBLING)->where('related_product_id', $sibling->id)->exists())->toBeTrue();
});
