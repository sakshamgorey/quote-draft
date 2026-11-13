<?php

use App\Models\Product;
use App\Services\Drafts\ProductFit;
use App\Services\Drafts\ProductMatcher;
use App\Services\Drafts\RequestedItem;

/** A 12x12 access door request with the given stated details. */
function request12(array $specs = []): RequestedItem
{
    $specs = array_map(fn ($v) => ['value' => $v, 'quote' => 'x'], $specs);

    return new RequestedItem('access_door', '12x12 panel', ['size' => ['value' => ['width' => 12, 'height' => 12], 'quote' => '12x12']] + $specs);
}

function withPage(Product $product, array $page): Product
{
    $product->page()->create(['parser_version' => 1] + $page);

    return $product;
}

function stainlessOption(Product $product, int $cents = 8700): void
{
    $option = $product->options()->create(['label' => 'Material', 'display_type' => 'product-list', 'required' => true, 'position' => 0]);
    $option->values()->create(['raw_label' => 'Cold Rolled Steel $0 - Standard', 'name' => 'Cold Rolled Steel', 'price_delta_cents' => 0, 'is_default' => true, 'position' => 0]);
    $option->values()->create(['raw_label' => "Stainless Steel 304 #4 \${$cents}", 'name' => 'Stainless Steel 304 #4', 'price_delta_cents' => $cents, 'position' => 1]);
}

function skusOf($products): array
{
    return $products->pluck('sku')->all();
}

it('treats an unknown detail as possible, ranked below a confirmed one, and a contradicted one as "also fits"', function () {
    $confirmed = withPage(product(['sku' => 'CONFIRMED', 'popularity' => 1]), ['insulated' => true, 'insulated_basis' => 'stated', 'insulation_evidence' => '1" fiberglass insulation']);
    product(['sku' => 'UNKNOWN', 'popularity' => 99]); // nothing known: possible
    withPage(product(['sku' => 'CONTRADICTS', 'popularity' => 50]), ['insulated' => false, 'insulated_basis' => 'stated']);

    $match = app(ProductMatcher::class)->match(request12(['insulated' => true]));

    expect(skusOf($match['best']))->toBe(['CONFIRMED', 'UNKNOWN']) // confirmed beats a bestseller with no data
        ->and(skusOf($match['also']))->toBe(['CONTRADICTS'])
        ->and($match['fits'][$confirmed->id]['checks'][0])->toMatchArray(['verdict' => 'yes', 'evidence' => '1" fiberglass insulation']);
});

it('lets the product page fill in what the store left blank', function () {
    $p = withPage(product(['insulated' => null]), ['insulated' => true, 'insulated_basis' => 'stated', 'flange_types' => ['Drywall Flange']]);

    $fit = app(ProductFit::class)->check(request12(['insulated' => true, 'flange_type' => 'Drywall Flange']), $p->load('page'));

    expect(array_column($fit['checks'], 'verdict'))->toBe(['yes', 'yes']);
});

it('counts an option as meeting a detail and says which option', function () {
    $door = product(['sku' => 'BA-AHD-12-12', 'material' => ['Steel']]);
    stainlessOption($door);
    product(['sku' => 'PLAIN-STEEL', 'material' => ['Steel']]);

    $match = app(ProductMatcher::class)->match(request12(['material' => 'Stainless Steel']));
    $check = $match['fits'][$door->id]['checks'][0];

    expect(skusOf($match['best']))->toBe(['BA-AHD-12-12'])
        ->and(skusOf($match['also']))->toBe(['PLAIN-STEEL'])
        ->and($check['verdict'])->toBe('option')
        ->and($check['evidence'])->toBe('Material: Stainless Steel 304 #4 (+$87.00)')
        ->and($check['option_value_id'])->not->toBeNull();
});

it('reads materials and loads the way a salesperson means them', function () {
    $fit = app(ProductFit::class);
    $galv = product(['material' => ['Galvanized Steel']]);
    $heavy = product(['product_type' => 'floor_door', 'purpose' => ['625 PSF']]);
    $light = product(['product_type' => 'floor_door', 'purpose' => ['150 PSF']]);

    expect($fit->check(request12(['material' => 'Steel']), $galv)['checks'][0]['verdict'])->toBe('yes') // any steel
        ->and($fit->check(request12(['material' => 'Stainless Steel']), $galv)['checks'][0]['verdict'])->toBe('no')
        ->and($fit->check(request12(['load_psf' => 300]), $heavy)['checks'][0]['verdict'])->toBe('yes') // 625 ≥ 300
        ->and($fit->check(request12(['load_psf' => 300]), $light)['checks'][0]['verdict'])->toBe('no');
});

it('still lists the closest products when none matches every detail', function () {
    withPage(product(['sku' => 'NOT-INSULATED']), ['insulated' => false, 'insulated_basis' => 'stated']);

    $match = app(ProductMatcher::class)->match(request12(['insulated' => true]));

    expect($match['best'])->toBeEmpty()
        ->and(skusOf($match['also']))->toBe(['NOT-INSULATED'])
        ->and($match['questions'][0])->toStartWith('No product matches every detail');
});

it('auto-picks only when exactly one product is a best match', function () {
    $only = product(['sku' => 'ONLY-DRYWALL', 'surface_type' => ['Drywall']]);
    product(['sku' => 'MASONRY', 'surface_type' => ['Masonry']]);

    $draft = draftFor('12x12 panel for drywall', [item(['quote' => '12x12 panel for drywall',
        'size' => ['width' => 12, 'height' => 12, 'quote' => '12x12'],
        'surface_type' => ['value' => 'Drywall', 'quote' => 'drywall']])]);

    expect($draft['lines'][0]['status'])->toBe('ready')
        ->and($draft['lines'][0]['selected']['sku'])->toBe('ONLY-DRYWALL')
        ->and(collect($draft['lines'][0]['options'])->firstWhere('sku', 'MASONRY')['group'])->toBe('also')
        ->and(collect($draft['lines'][0]['options'])->firstWhere('sku', 'MASONRY')['checks'][0])
        ->toMatchArray(['label' => 'Surface', 'value' => 'Drywall', 'verdict' => 'no']);
});
