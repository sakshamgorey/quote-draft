<?php

use App\Models\DraftLine;
use App\Services\Drafts\DraftValidator;
use App\Services\Drafts\ProductMatcher;
use App\Services\Drafts\RequestedItem;

/** A request with the given details; size 12x12 unless $size is false. */
function named(array $specs = [], array $features = [], bool $size = true, ?string $type = 'access_door'): RequestedItem
{
    $specs = array_map(fn ($v) => ['value' => $v, 'quote' => (string) $v], $specs);
    $sized = $size ? ['size' => ['value' => ['width' => 12, 'height' => 12], 'quote' => '12x12']] : [];
    $features = array_map(fn ($f) => ['text' => $f, 'quote' => $f], $features);

    return new RequestedItem($type, 'the door', $sized + $specs, features: $features);
}

function namedSkus($products): array
{
    return $products->pluck('sku')->all();
}

function verdicts(array $match, string $sku, string $spec): array
{
    $id = $match['products']->firstWhere('sku', $sku)->id;

    return array_column(array_filter($match['fits'][$id]['checks'], fn ($c) => $c['spec'] === $spec), 'verdict');
}

it('keeps a brand, model or feature only when its words are in the notes', function () {
    $notes = 'Need the Acudor BA-PFI 12x12, high security please';

    $items = app(DraftValidator::class)->validate([item([
        'quote' => 'Acudor BA-PFI 12x12',
        'brand' => ['value' => 'Acudor', 'quote' => 'Acudor BA-PFI'],
        'model' => ['value' => 'BA-PFI', 'quote' => 'BA-PFI 12x12'],
        'features' => [['text' => 'high security', 'quote' => 'high security'], ['text' => 'airtight', 'quote' => 'airtight please']],
    ]), item([
        'quote' => '12x12',
        'brand' => ['value' => 'Bilco', 'quote' => 'Acudor'], // value not in its quote
        'model' => ['value' => 'BA-XYZ', 'quote' => 'BA-XYZ'], // quote not in the notes
    ])], $notes);

    expect($items[0]->value('brand'))->toBe('Acudor')
        ->and($items[0]->value('model'))->toBe('BA-PFI')
        ->and($items[0]->features)->toBe([['text' => 'high security', 'quote' => 'high security']])
        ->and($items[1]->has('brand'))->toBeFalse()
        ->and($items[1]->rejected)->toContain('brand', 'model');
});

it('picks the product whose SKU the notes write out, without asking for a size', function () {
    product(['sku' => 'BA-AHD-24-24', 'model' => 'BA-AHD', 'nominal_width' => 24, 'nominal_height' => 24]);
    product(['sku' => 'BA-AHD-12-12', 'model' => 'BA-AHD']);

    $draft = draftFor('2 x BA-AHD 24x24 please', [item([
        'quote' => 'BA-AHD 24x24', 'product_type' => 'unknown',
        'quantity' => ['value' => 2, 'quote' => '2 x BA-AHD'],
        'model' => ['value' => 'BA-AHD 24x24', 'quote' => 'BA-AHD 24x24'],
    ])]);

    expect($draft['lines'][0]['status'])->toBe('ready')
        ->and($draft['lines'][0]['selected']['sku'])->toBe('BA-AHD-24-24')
        ->and(collect($draft['lines'][0]['specs'])->firstWhere('key', 'model')['value'])->toBe('BA-AHD 24x24');
});

it('narrows to the named model, with or without our BA- prefix, and lists its sizes when this one is missing', function () {
    product(['sku' => 'PFI-12', 'model' => 'BA-PFI', 'popularity' => 1]);
    product(['sku' => 'AHD-12', 'model' => 'BA-AHD', 'popularity' => 99]);
    product(['sku' => 'PFI-24', 'model' => 'BA-PFI', 'nominal_width' => 24, 'nominal_height' => 24]);

    $match = app(ProductMatcher::class)->match(named(['model' => 'PFI']));

    expect(namedSkus($match['best']))->toBe(['PFI-12'])
        ->and(namedSkus($match['also']))->toBe(['AHD-12']);

    $missing = app(ProductMatcher::class)->match(new RequestedItem('access_door', 'x', [
        'size' => ['value' => ['width' => 24, 'height' => 24], 'quote' => '24x24'],
        'model' => ['value' => 'BA-AHD', 'quote' => 'BA-AHD'],
    ]));

    expect($missing['best'])->toBeEmpty()
        ->and($missing['questions'][0])->toBe('“BA-AHD” isn\'t listed in this size. It comes in: 12" x 12".');
});

it('lists the named model first when the notes get its brand wrong', function () {
    product(['sku' => 'PFI-12', 'model' => 'BA-PFI', 'brand' => 'Cendrex', 'popularity' => 1]);
    product(['sku' => 'ACUDOR-12', 'model' => 'BA-AHD', 'brand' => 'Acudor USA', 'popularity' => 99]);

    $match = app(ProductMatcher::class)->match(named(['brand' => 'Acudor', 'model' => 'BA-PFI']));

    expect($match['best'])->toBeEmpty()
        ->and(namedSkus($match['also']))->toBe(['PFI-12', 'ACUDOR-12'])
        ->and($match['questions'][0])->toStartWith('No product matches every detail'); // BA-PFI does come in 12x12
});

it('treats a model or brand we do not carry as unknown and flags it', function () {
    product(['sku' => 'ONE', 'brand' => 'Acudor USA']);

    $match = app(ProductMatcher::class)->match(named(['model' => 'Milcor DW-5040', 'brand' => 'Nystrom']));

    expect(namedSkus($match['best']))->toBe(['ONE'])
        ->and(verdicts($match, 'ONE', 'model'))->toBe(['unknown'])
        ->and(array_column($match['unknown_names'], 'value'))->toBe(['Milcor DW-5040', 'Nystrom']);

    $draft = draftFor('12x12 panel, Nystrom', [item(['brand' => ['value' => 'Nystrom', 'quote' => 'Nystrom']])]);

    expect(collect($draft['flags'])->firstWhere('code', 'name_not_in_catalog'))->toMatchArray(['severity' => 'warning', 'quote' => 'Nystrom']);
});

it('matches a brand by its name words and ignores the store name', function () {
    product(['sku' => 'ACUDOR', 'brand' => 'Acudor USA', 'popularity' => 1]);
    product(['sku' => 'CENDREX', 'brand' => 'Cendrex', 'popularity' => 99]);

    $acudor = app(ProductMatcher::class)->match(named(['brand' => 'acudor']));
    $store = app(ProductMatcher::class)->match(named(['brand' => 'Best Access']));

    expect(namedSkus($acudor['best']))->toBe(['ACUDOR'])
        ->and(namedSkus($acudor['also']))->toBe(['CENDREX'])
        ->and(namedSkus($store['best']))->toBe(['CENDREX', 'ACUDOR'])
        ->and($store['unknown_names'])->toBe([]);
});

it('ranks by features from store tags and the product page but never rules a product out', function () {
    product(['sku' => 'POPULAR', 'popularity' => 99]);
    product(['sku' => 'TAGGED', 'purpose' => ['Security'], 'popularity' => 3]);
    product(['sku' => 'PAGE', 'popularity' => 2])->page()->create(['parser_version' => 1, 'description' => 'A steel door.', 'spec_lines' => [['label' => 'Seal', 'value' => 'Air-tight gasket']]]);

    $match = app(ProductMatcher::class)->match(named(features: ['high security', 'airtight']));

    expect(namedSkus($match['best']))->toBe(['TAGGED', 'PAGE', 'POPULAR'])
        ->and($match['also'])->toBeEmpty()
        ->and(verdicts($match, 'PAGE', 'feature'))->toBe(['unknown', 'yes'])
        ->and(collect($match['fits'][$match['products']->firstWhere('sku', 'PAGE')->id]['checks'])->last()['evidence'])->toBe('Product page');
});

it('lets the salesperson drop a detail from the notes', function () {
    product(['sku' => 'ACUDOR', 'brand' => 'Acudor USA']);
    product(['sku' => 'CENDREX', 'brand' => 'Cendrex']);

    $draft = draftFor('12x12 panel, Acudor', [item(['size' => ['width' => 12, 'height' => 12, 'quote' => '12x12'], 'brand' => ['value' => 'Acudor', 'quote' => 'Acudor']])]);
    $line = $draft['lines'][0];

    expect($line['selected']['sku'])->toBe('ACUDOR');

    $this->patch("/drafts/{$draft['ulid']}/lines/{$line['id']}", ['clear_spec' => 'brand'])->assertRedirect();

    expect(DraftLine::find($line['id'])->specs)->not->toHaveKey('brand')
        ->and(collect(showDraft($draft['ulid'])['lines'][0]['options'])->pluck('group')->unique()->all())->toBe(['best']);
});

it('picks the product whose store title the notes quote, among other good matches', function () {
    $title = '24" x 24" 2 Hours Fire-Rated Insulated Flush Access Panel for Walls - With Heavy Gauge Steel';
    product(['sku' => 'BA-FD-HG-24-24', 'model' => 'BA-FD-HG', 'fire_rated' => true, 'nominal_width' => 24, 'nominal_height' => 24, 'name' => $title]);
    product(['sku' => 'BA-FD-HG-24-48', 'model' => 'BA-FD-HG', 'fire_rated' => true, 'nominal_width' => 24, 'nominal_height' => 48, 'name' => str_replace('24" x 24"', '24" x 48"', $title)]);
    product(['sku' => 'BA-FD3W-24-24', 'model' => 'BA-FD3W', 'fire_rated' => true, 'nominal_width' => 24, 'nominal_height' => 24, 'name' => '24" x 24" 3 Hour Fire-Rated Access Panels for Walls', 'popularity' => 99]);

    $notes = "5 {$title}";
    $draft = draftFor($notes, [item([
        'quote' => $notes,
        'quantity' => ['value' => 5, 'quote' => '5 24"'],
        'size' => ['width' => 24, 'height' => 24, 'quote' => '24" x 24"'],
        'fire_rated' => ['value' => true, 'quote' => 'Fire-Rated'],
    ])]);
    $line = $draft['lines'][0];

    expect($line['status'])->toBe('ready')
        ->and($line['selected']['sku'])->toBe('BA-FD-HG-24-24')
        ->and($line['quantity'])->toBe(5)
        ->and(array_column($line['options'], 'sku'))->toBe(['BA-FD-HG-24-24', 'BA-FD3W-24-24']) // the other good match stays listed
        ->and(collect($line['options'][0]['checks'])->firstWhere('spec', 'title'))->toMatchArray(['verdict' => 'yes', 'label' => 'Name']);
});

it('never picks by title from generic words or when two products share the title', function () {
    product(['sku' => 'GENERIC-A', 'name' => '12" x 12" Access Panel for Walls']);
    product(['sku' => 'GENERIC-B', 'name' => '12" x 12" Access Door for Walls']);
    $twin = '12" x 12" Recessed Insulated Flush Access Panel for Drywall Ceilings - With Piano Hinge';
    product(['sku' => 'TWIN-A', 'name' => $twin]);
    product(['sku' => 'TWIN-B', 'name' => $twin]);

    $generic = app(ProductMatcher::class)->match(new RequestedItem('access_door', '12x12 access panel for walls', ['size' => ['value' => ['width' => 12, 'height' => 12], 'quote' => '12x12']]));
    $twins = app(ProductMatcher::class)->match(new RequestedItem('access_door', $twin, ['size' => ['value' => ['width' => 12, 'height' => 12], 'quote' => '12" x 12"']]));

    expect($generic['pick']['confidence'] ?? null)->not->toBe('named')
        ->and($twins['pick']['confidence'] ?? null)->not->toBe('named');
});

it('never preselects an accessory over the product itself, and always the SKU the notes give', function () {
    product(['sku' => 'BA-PDS-21-46', 'model' => 'BA-PDS', 'product_type' => 'skylight', 'name' => 'Vented Deck Mounted Skylight', 'nominal_width' => 21, 'nominal_height' => 46]);
    product(['sku' => 'KIT-21-46', 'model' => 'BA-EH-D', 'product_type' => 'skylight', 'name' => 'Flashing Kit Compatible with BA-PDS', 'nominal_width' => 21, 'nominal_height' => 46, 'popularity' => 99]);
    $skylight = app(ProductMatcher::class)->match(new RequestedItem('skylight', 'a skylight', ['size' => ['value' => ['width' => 21, 'height' => 46], 'quote' => '21 x 46']]));

    product(['sku' => 'BA-UAP-SS-12-12', 'model' => 'BA-UAP-SS', 'surface_type' => ['Drywall']]);
    $sku = app(ProductMatcher::class)->match(new RequestedItem('access_door', 'x', [
        'size' => ['value' => ['width' => 12, 'height' => 12], 'quote' => '12x12'],
        'model' => ['value' => 'BA-UAP-SS 12x12', 'quote' => 'BA-UAP-SS 12x12'],
        'surface_type' => ['value' => 'Tile', 'quote' => 'tile'],
    ]));

    expect(namedSkus($skylight['best']))->toBe(['BA-PDS-21-46'])
        ->and(namedSkus($skylight['also']))->toBe(['KIT-21-46'])
        ->and($sku['pick'])->toMatchArray(['id' => product_id('BA-UAP-SS-12-12'), 'confidence' => 'named'])
        ->and($sku['pick']['reason'])->toContain('differs on Surface Tile');
});
