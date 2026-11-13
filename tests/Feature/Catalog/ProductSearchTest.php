<?php

use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    product(['sku' => 'BA-PFI-24-24', 'name' => '24" x 24" Fire-Rated Insulated Access Door', 'nominal_width' => 24, 'nominal_height' => 24,
        'fire_rated' => true, 'brand' => 'Acudor USA', 'surface_type' => ['Drywall'], 'material' => ['Steel'], 'price' => 326.61, 'popularity' => 50]);
    product(['sku' => 'BA-AHD-24-36', 'name' => '24" x 36" Flush Universal Access Door', 'nominal_width' => 24, 'nominal_height' => 36,
        'brand' => 'Cendrex', 'surface_type' => ['Masonry', 'Concrete'], 'material' => ['Steel'], 'price' => 120, 'popularity' => 900]);
    product(['sku' => 'BA-RH-30-36', 'name' => '30" x 36" Aluminum Roof Hatch', 'product_type' => 'roof_hatch', 'nominal_width' => 30, 'nominal_height' => 36,
        'brand' => 'Bilco', 'material' => ['Aluminum'], 'purpose' => ['300 PSF'], 'price' => null, 'popularity' => 10]);
    product(['sku' => 'BA-OLD-24-24', 'name' => '24" x 24" Discontinued Access Door', 'nominal_width' => 24, 'nominal_height' => 24,
        'brand' => 'Cendrex', 'is_available' => false, 'price' => 99]);
});

function skus($response): array
{
    return array_column($response->viewData('page')['props']['products']['data'], 'sku');
}

/** @return array<string, int> option value => count for one facet */
function facetCounts($response, string $key): array
{
    $facet = collect($response->viewData('page')['props']['facets'])->firstWhere('key', $key);

    return collect($facet['options'] ?? [])->pluck('count', 'value')->all();
}

it('lists available products, bestselling first, with facets', function () {
    $res = $this->get('/products')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('products/index')
        ->where('products.total', 3)
        ->has('facets'));

    // Disabled store product never shows; popularity orders the browse list.
    expect(skus($res))->toBe(['BA-AHD-24-36', 'BA-PFI-24-24', 'BA-RH-30-36'])
        ->and(facetCounts($res, 'brand'))->toBe(['Acudor USA' => 1, 'Bilco' => 1, 'Cendrex' => 1]);
});

it('searches by name with Postgres full text', function () {
    expect(skus($this->get('/products?q=roof+hatch')))->toBe(['BA-RH-30-36'])
        ->and(skus($this->get('/products?q=fire+rated')))->toBe(['BA-PFI-24-24']);
});

it('filters by type, fire rating and opening size in either orientation', function () {
    expect(skus($this->get('/products?type=roof_hatch')))->toBe(['BA-RH-30-36'])
        ->and(skus($this->get('/products?fire_rated=1')))->toBe(['BA-PFI-24-24'])
        ->and(skus($this->get('/products?width=36&height=24')))->toBe(['BA-AHD-24-36'])
        ->and(skus($this->get('/products?width=36')))->toEqualCanonicalizing(['BA-AHD-24-36', 'BA-RH-30-36']);
});

it('ORs values within a facet and ANDs across facets', function () {
    expect(skus($this->get('/products?surface_type[]=Masonry&surface_type[]=Drywall')))->toEqualCanonicalizing(['BA-AHD-24-36', 'BA-PFI-24-24'])
        ->and(skus($this->get('/products?surface_type[]=Masonry&brand[]=Acudor+USA')))->toBe([])
        ->and(skus($this->get('/products?material[]=Steel&brand[]=Cendrex')))->toBe(['BA-AHD-24-36']);
});

it('counts each facet with the other filters applied but not its own', function () {
    $res = $this->get('/products?brand[]=Cendrex');

    // Brand counts ignore the brand selection, so other brands stay pickable.
    expect(facetCounts($res, 'brand'))->toBe(['Acudor USA' => 1, 'Bilco' => 1, 'Cendrex' => 1])
        // Surface counts reflect the Cendrex filter.
        ->and(facetCounts($res, 'surface_type'))->toEqualCanonicalizing(['Masonry' => 1, 'Concrete' => 1]);
});

it('filters by price range, including price on request', function () {
    expect(skus($this->get('/products?price[]=100-250')))->toBe(['BA-AHD-24-36'])
        ->and(skus($this->get('/products?price[]=on-request')))->toBe(['BA-RH-30-36'])
        ->and(skus($this->get('/products?sort=price_asc')))->toBe(['BA-AHD-24-36', 'BA-PFI-24-24', 'BA-RH-30-36']);
});

it('shows unavailable products only when asked, marked as unavailable', function () {
    $res = $this->get('/products?show_unavailable=1')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.show_unavailable', true)
        ->where('products.total', 4));

    $old = collect($res->viewData('page')['props']['products']['data'])->firstWhere('sku', 'BA-OLD-24-24');
    expect($old['is_available'])->toBeFalse()
        ->and(facetCounts($res, 'brand')['Cendrex'])->toBe(2);
});

it('keeps the draft lookup to available products', function () {
    $this->getJson('/products/lookup?q=24&show_unavailable=1')->assertOk()
        ->assertJsonMissing(['sku' => 'BA-OLD-24-24']);
});

it('ignores junk filter values', function () {
    $this->get('/products?type=garage&width=abc&price[]=free&sort=random')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.type', '')
        ->where('filters.width', '')
        ->where('filters.price', [])
        ->where('filters.sort', 'popular')
        ->where('products.total', 3));
});
