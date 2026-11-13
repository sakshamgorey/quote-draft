<?php

use App\Services\ProductPages\PageParser;

function fixturePage(string $sku): array
{
    return (new PageParser)->parse(file_get_contents(base_path("tests/Fixtures/product-pages/{$sku}.html")));
}

it('reads choice labels typed on the store', function (string $raw, string $name, ?int $cents, ?int $days, bool $default) {
    $choice = (new PageParser)->choice($raw);

    expect($choice)->toMatchArray(['name' => $name, 'price_delta_cents' => $cents, 'lead_time_days' => $days, 'is_default' => $default]);
})->with([
    ['Screwdriver Operated Cam Latch $0.00 - Standard', 'Screwdriver Operated Cam Latch', 0, null, true],
    ['Key Operated Cylinder Cam Latch $16 + 5 days', 'Key Operated Cylinder Cam Latch', 1600, 5, false],
    ['Allen Head Cam Latch $24+5 days', 'Allen Head Cam Latch', 2400, 5, false],
    ['Red + 121.95', 'Red', 12195, null, false],
    ['Safety Rails - 360 degree protection, $1700.81', 'Safety Rails - 360 degree protection', 170081, null, false],
    ['Electric 12 / 24 v DC Remote Opener $1495 + 4 weeks', 'Electric 12 / 24 v DC Remote Opener', 149500, 28, false],
    ['Fesco Fiberboard $0 - Standard', 'Fesco Fiberboard', 0, null, true],
    ['Gray - Standard', 'Gray', 0, null, true],
]);

it('never guesses a price when a label has two amounts', function () {
    $choice = (new PageParser)->choice('Upgrade $40 or $55 with install');

    expect($choice['price_delta_cents'])->toBeNull()->and($choice['needs_review'])->toBeTrue();
});

it('parses an access door page: options, specs, price and size picker', function () {
    $page = fixturePage('BA-AHD-12-12');

    expect($page['store_product_id'])->toBe(258)
        ->and($page['page_price'])->toBe(54.25)
        ->and($page['gauge'])->toBe(16)
        ->and($page['flange_types'])->toBe(['Exposed Flange'])
        ->and($page['material'])->toBe('Cold rolled steel')
        ->and($page['insulated'])->toBeFalse()
        ->and($page['insulated_basis'])->toBe('not_mentioned')
        ->and($page['spec_sheet_url'])->toBe('https://www.bestaccessdoors.com/content/BA-AHD.pdf')
        ->and(array_column($page['spec_lines'], 'label'))->toContain('Material', 'Flange')
        ->and(array_column($page['spec_lines'], 'label'))->not->toContain('Easy to Install') // marketing, not a spec
        ->and($page['size_links'])->toHaveCount(24)
        ->and(array_column($page['options'], 'label'))->toBe(['Choose a Lock / Latch', 'Gasket', 'Material']);

    $lock = $page['options'][0];
    expect($lock['required'])->toBeTrue()
        ->and($lock['store_option_id'])->toBe(5744)
        ->and(count($lock['values']))->toBe(count(array_unique(array_column($lock['values'], 'store_value_id')))) // no duplicates
        ->and($lock['values'][0])->toMatchArray(['name' => 'Screwdriver Operated Cam Latch', 'price_delta_cents' => 0, 'is_default' => true])
        ->and($lock['values'][1])->toMatchArray(['name' => 'Key Operated Cylinder Cam Latch', 'price_delta_cents' => 1600, 'lead_time_days' => 5]);
});

it('finds insulation the store left blank, with the words it came from', function () {
    $hatch = fixturePage('BA-TRH-36-36');
    $ladderHatch = fixturePage('BA-RHG-LA-30-30');

    expect($hatch['insulated'])->toBeTrue()
        ->and($hatch['insulated_basis'])->toBe('stated')
        ->and($hatch['insulation_evidence'])->toContain('insulation')
        ->and($hatch['options'])->toBe([])
        ->and($ladderHatch['insulated'])->toBeTrue()
        ->and(array_column($ladderHatch['spec_lines'], 'label'))->toContain('Cover'); // "• Cover: …" bullets
});

it('marks "No Selected" as the default for optional add-ons', function () {
    $safety = fixturePage('BA-RHG-LA-30-30')['options'][0];

    expect($safety['label'])->toBe('Safety Options')
        ->and($safety['values'][0])->toMatchArray(['name' => 'No Selected', 'is_none' => true, 'is_default' => true])
        ->and(array_column($safety['values'], 'price_delta_cents'))->toBe([0, 170081, 21275, 44567]);
});

it('flags the same choice listed at two prices instead of guessing', function () {
    $html = '<div data-product-attribute="product-list"><label class="form-label">Lock (*)</label><ul>'
        .'<li data-product-attribute-value="1"><input name="attribute[9]"><label class="form-label">Cam Latch $0 - Standard</label></li>'
        .'<li data-product-attribute-value="2"><label class="form-label">Slam Latch $69 + 5 days</label></li>'
        .'<li data-product-attribute-value="3"><label class="form-label">Slam Latch $0 - Standard</label></li></ul></div>';

    $page = (new PageParser)->parse($html);
    $values = $page['options'][0]['values'];

    expect(array_column($values, 'is_default'))->toBe([true, false, false]) // first Standard wins
        ->and(array_column($values, 'needs_review'))->toBe([false, true, true])
        ->and($page['warnings'])->toContain('Option "Lock" lists "Slam Latch" at different prices.');
});

it('reads compatibility statements', function () {
    expect((new PageParser)->compatibleModels('High-Profile Tile Roof Flashing Kit Compatible with BA-PDS, BA-PDX'))
        ->toBe([
            ['model' => 'BA-PDS', 'evidence' => 'High-Profile Tile Roof Flashing Kit Compatible with BA-PDS, BA-PDX'],
            ['model' => 'BA-PDX', 'evidence' => 'High-Profile Tile Roof Flashing Kit Compatible with BA-PDS, BA-PDX'],
        ]);
});

it('keeps each choice picture: product-list thumbnails and swatch backgrounds', function () {
    $page = (new PageParser)->parse(file_get_contents(base_path('tests/Fixtures/product-pages/BA-RAH-30-36.html')));
    $curb = collect($page['options'])->firstWhere('label', 'Choose a Curb Face');

    expect($curb['values'][0]['image_url'])->toStartWith('https://cdn11.bigcommerce.com/')
        ->and($curb['values'][0]['image_url'])->toContain('wood-standard');

    $swatch = (new PageParser)->parse('<div data-product-attribute="swatch"><label class="form-label">Color</label>'
        .'<label data-product-attribute-value="5"><input name="attribute[3]"><span style="background-image: url(\'https://cdn.example.com/red.png\')"></span><span class="form-option-variant">Red $0 - Standard</span></label>'
        .'<label data-product-attribute-value="6"><img src="data:image/gif;base64,R0lGOD" data-src="https://cdn.example.com/blue.png"><span>Blue $10</span></label></div>');

    expect(array_column($swatch['options'][0]['values'], 'image_url'))->toBe(['https://cdn.example.com/red.png', 'https://cdn.example.com/blue.png']);
});

it('reads the store prices written without a dollar sign, but not lead times or sizes', function () {
    $parser = new PageParser;

    expect($parser->choice('Red + 116.1'))->toMatchArray(['name' => 'Red', 'price_delta_cents' => 11610])
        ->and($parser->choice('Red + 121.95'))->toMatchArray(['name' => 'Red', 'price_delta_cents' => 12195])
        ->and($parser->choice('ULC - 2 hour "B" label + 46'))->toMatchArray(['name' => 'ULC - 2 hour "B" label', 'price_delta_cents' => 4600])
        ->and($parser->choice('Slam Latch + 5 days'))->toMatchArray(['name' => 'Slam Latch', 'price_delta_cents' => 0, 'lead_time_days' => 5])
        ->and($parser->choice('Key Operated Cylinder Cam Latch $16 + 5 days'))->toMatchArray(['price_delta_cents' => 1600, 'lead_time_days' => 5])
        ->and($parser->choice('Recess + 3/4 inch'))->toMatchArray(['price_delta_cents' => 0]);
});
