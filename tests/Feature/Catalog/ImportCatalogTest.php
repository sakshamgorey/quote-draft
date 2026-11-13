<?php

use App\Models\Product;
use App\Services\Catalog\ProductNormalizer;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Read the fixture as if it were a saved category page; never touch the network.
    config([
        'searchspring.storage_path' => base_path('tests/Fixtures/searchspring'),
        'searchspring.categories' => ['sample' => 'Products'],
    ]);
    Http::preventStrayRequests();
});

it('imports saved pages without fetching and normalises products', function () {
    $this->artisan('catalog:import')->assertSuccessful();

    expect(Product::count())->toBe(4);

    $door = Product::where('sku', 'BA-AHD-12-12')->first();
    expect($door->name)->toBe('12" x 12" Flush Universal Access Door with Exposed Flange') // decoded twice
        ->and($door->model)->toBe('BA-AHD')
        ->and($door->product_type)->toBe('access_door')
        ->and([$door->nominal_width, $door->nominal_height])->toBe([12.0, 12.0])
        ->and([$door->overall_width, $door->overall_height])->toBe([16.0, 16.0])
        ->and($door->application)->toBe(['Ceiling', 'Attic'])
        ->and($door->surface_type)->toBe(['Drywall'])
        ->and($door->material)->toBe(['Steel'])
        ->and($door->flange_type)->toBe(['Exposed Flange'])
        ->and($door->image_url)->toContain('/stencil/320w/');
});

it('derives product type from overlapping categories by priority', function () {
    $this->artisan('catalog:import')->assertSuccessful();

    // Listed under Access Doors too, but it's a floor door.
    expect(Product::where('sku', 'BA-FD-300-36-36')->value('product_type'))->toBe('floor_door')
        // Listed under Skylights and Ladders: the name says ladder.
        ->and(Product::where('sku', 'BA-LST-22.5-31.5')->value('product_type'))->toBe('ladder');
});

it('keeps skylights listed under hatches out of roof hatches', function () {
    $normalizer = new ProductNormalizer;
    $paths = ['Products>Roof & Floor Hatches', 'Products>Roof & Floor Hatches>Application>Roof'];

    expect($normalizer->productType($paths, '30" x 36" Electric Vented Flat Roof Deck-Mount Skylight'))->toBe('skylight')
        ->and($normalizer->productType($paths, '30" x 36" Thermo Flat Roof Access Skylight'))->toBe('roof_hatch')
        ->and($normalizer->productType($paths, '30" x 36" Aluminum Roof Hatch'))->toBe('roof_hatch');
});

it('parses fraction sizes, splits materials and reads load ratings', function () {
    $this->artisan('catalog:import')->assertSuccessful();

    $ladder = Product::where('sku', 'BA-LST-22.5-31.5')->first();
    expect([$ladder->nominal_width, $ladder->nominal_height])->toBe([22.5, 31.5]);

    expect(Product::where('sku', 'BA-ADWT-PC-12-12')->first()->material)->toBe(['Galvannealed Steel', 'Steel'])
        ->and(Product::where('sku', 'BA-FD-300-36-36')->first()->purpose)->toContain('300 PSF');
});

it('does not trust the store\'s Plaster>Fire-Rated branch', function () {
    $this->artisan('catalog:import')->assertSuccessful();

    expect(Product::where('sku', 'BA-ADWT-PC-12-12')->value('fire_rated'))->toBeFalse();
});

it('stores brand, availability, popularity and rating', function () {
    $this->artisan('catalog:import')->assertSuccessful();

    $door = Product::where('sku', 'BA-AHD-12-12')->first();
    $airtight = Product::where('sku', 'BA-ADWT-PC-12-12')->first();

    expect($door->brand)->toBe('Cendrex')
        ->and((bool) $door->is_available)->toBeTrue()
        ->and($door->popularity)->toBe(26945)
        ->and((float) $door->rating)->toBe(4.88)
        ->and($door->rating_count)->toBe(390)
        ->and((bool) $airtight->is_available)->toBeFalse();
});

it('is idempotent', function () {
    $this->artisan('catalog:import')->assertSuccessful();
    $this->artisan('catalog:import')->assertSuccessful();

    expect(Product::count())->toBe(4);
});
