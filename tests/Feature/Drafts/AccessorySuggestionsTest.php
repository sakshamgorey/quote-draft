<?php

use App\Models\Product;
use App\Models\ProductLink;
use App\Services\Drafts\AccessorySuggester;

function accessoryFor(Product $accessory, string $kind, string $related, string $evidence = 'from the name'): Product
{
    $accessory->links()->create(['kind' => $kind, $kind === ProductLink::COMPATIBLE_WITH ? 'related_model' : 'related_type' => $related, 'evidence' => $evidence]);

    return $accessory;
}

function suggestedSkus(Product $product, array $wishes = []): array
{
    return array_map(fn ($s) => $s['product']->sku, app(AccessorySuggester::class)->for($product, $wishes));
}

it('suggests the flashing kit made for this skylight model in its size only', function () {
    $skylight = product(['sku' => 'BA-PDS-21-45.75', 'model' => 'BA-PDS', 'product_type' => 'skylight', 'name' => 'Vented Deck Mounted Skylight', 'nominal_width' => 21, 'nominal_height' => 45.75]);
    accessoryFor(product(['sku' => 'KIT-21', 'model' => 'BA-EH-D', 'product_type' => 'skylight', 'nominal_width' => 21, 'nominal_height' => 45.75]), ProductLink::COMPATIBLE_WITH, 'BA-PDS');
    accessoryFor(product(['sku' => 'KIT-44', 'model' => 'BA-EH-D', 'product_type' => 'skylight', 'nominal_width' => 44.25, 'nominal_height' => 45.75]), ProductLink::COMPATIBLE_WITH, 'BA-PDS');
    accessoryFor(product(['sku' => 'KIT-OTHER', 'model' => 'BA-EH-X', 'product_type' => 'skylight', 'nominal_width' => 21, 'nominal_height' => 45.75]), ProductLink::COMPATIBLE_WITH, 'BA-PDX');
    // "for Curb Mounted Skylight" doesn't go on a deck-mounted one.
    accessoryFor(product(['sku' => 'CURB-KIT', 'model' => 'BA-CK', 'product_type' => 'skylight', 'name' => 'Flashing Kit for Curb Mounted Skylight', 'nominal_width' => null, 'nominal_height' => null]), ProductLink::ACCESSORY_FOR, 'skylight');

    $suggestions = app(AccessorySuggester::class)->for($skylight);

    expect(array_map(fn ($s) => $s['product']->sku, $suggestions))->toBe(['KIT-21'])
        ->and($suggestions[0]['reason'])->toBe('Made for BA-PDS');
});

it('suggests unsized accessories for the product type, one per accessory model', function () {
    $hatch = product(['product_type' => 'roof_hatch', 'nominal_width' => 30, 'nominal_height' => 36]);
    accessoryFor(product(['sku' => 'LADDER-8', 'model' => 'BA-LAD', 'product_type' => 'ladder', 'nominal_width' => null, 'nominal_height' => null, 'popularity' => 5]), ProductLink::ACCESSORY_FOR, 'roof_hatch');
    accessoryFor(product(['sku' => 'LADDER-10', 'model' => 'BA-LAD', 'product_type' => 'ladder', 'nominal_width' => null, 'nominal_height' => null, 'popularity' => 1]), ProductLink::ACCESSORY_FOR, 'roof_hatch');
    accessoryFor(product(['sku' => 'FLOOR-THING', 'product_type' => 'ladder', 'nominal_width' => null, 'nominal_height' => null]), ProductLink::ACCESSORY_FOR, 'floor_door');

    expect(suggestedSkus($hatch))->toBe(['LADDER-8']);
});

it('offers an add-on the notes ask for when the product has no such option', function () {
    product(['sku' => 'HATCH', 'product_type' => 'roof_hatch', 'nominal_width' => 36, 'nominal_height' => 36]);
    product(['sku' => 'RAIL-36', 'model' => 'BA-CORHSR', 'product_type' => 'safety_rail', 'name' => '36" x 36" Compression Roof Hatch Safety Railing', 'nominal_width' => 36, 'nominal_height' => 36]);
    product(['sku' => 'RAIL-48', 'model' => 'BA-CORHSR2', 'product_type' => 'safety_rail', 'name' => '48" x 48" Compression Roof Hatch Safety Railing', 'nominal_width' => 48, 'nominal_height' => 48]);
    product(['sku' => 'COMBO', 'model' => 'BA-STRHSR', 'product_type' => 'safety_rail', 'name' => '36" x 36" Saf-T-Hatch Roof Hatch with Safety Railing', 'nominal_width' => 36, 'nominal_height' => 36]);

    $draft = draftFor('36x36 roof hatch with safety rails', [item([
        'product_type' => 'roof_hatch', 'quote' => '36x36 roof hatch',
        'size' => ['width' => 36, 'height' => 36, 'quote' => '36x36'],
        'options_wanted' => [['text' => 'safety rails', 'quote' => 'with safety rails']],
    ])]);
    $line = $draft['lines'][0];

    expect($line['selected']['sku'])->toBe('HATCH')
        ->and(array_column($line['suggestions'], 'sku'))->toBe(['RAIL-36'])
        ->and($line['suggestions'][0])->toMatchArray(['reason' => 'Notes ask for “safety rails”', 'quote' => 'with safety rails'])
        ->and(collect($draft['flags'])->firstWhere('code', 'option_unmatched')['message'])->toContain('A matching add-on is suggested below.');

    // Adding it puts it on the draft, so it isn't suggested again.
    $this->post("/drafts/{$draft['ulid']}/lines", ['product_id' => product_id('RAIL-36'), 'quantity' => $line['quantity']])->assertRedirect();

    $after = showDraft($draft['ulid']);
    expect(array_column($after['lines'], 'selected'))->toHaveCount(2)
        ->and($after['lines'][0]['suggestions'])->toBe([]);
});

it('suggests nothing for a product that is itself an accessory', function () {
    product(['sku' => 'BA-PDS-1', 'model' => 'BA-PDS', 'product_type' => 'skylight']);
    $kit = accessoryFor(product(['sku' => 'KIT', 'model' => 'BA-EH-D', 'product_type' => 'skylight']), ProductLink::COMPATIBLE_WITH, 'BA-PDS');
    accessoryFor(product(['sku' => 'BLIND', 'product_type' => 'skylight', 'nominal_width' => null, 'nominal_height' => null]), ProductLink::ACCESSORY_FOR, 'skylight');

    expect(suggestedSkus($kit))->toBe([]);
});

it('offers the AI roof-hatch add-ons, not attic-ladder parts, one per model', function () {
    $hatch = product(['product_type' => 'roof_hatch', 'nominal_width' => 30, 'nominal_height' => 36]);
    product(['sku' => 'BA-LDR-08', 'model' => 'BA-LDR', 'product_type' => 'ladder', 'name' => "8'0\" Aluminum Ladder for Roof Hatches", 'nominal_width' => null, 'nominal_height' => null, 'popularity' => 5]);
    product(['sku' => 'BA-LDR-10', 'model' => 'BA-LDR', 'product_type' => 'ladder', 'name' => "10'0\" Aluminum Ladder for Roof Hatches", 'nominal_width' => null, 'nominal_height' => null]);
    product(['sku' => 'BA-LXH', 'model' => 'BA-LXH', 'product_type' => 'ladder', 'name' => 'Metal Handrail One Piece For Ladders', 'nominal_width' => null, 'nominal_height' => null]);
    product(['sku' => 'BA-LST', 'model' => 'BA-LST', 'product_type' => 'ladder', 'name' => '25" x 47" Insulated Scissor Attic Ladder', 'nominal_width' => 25, 'nominal_height' => 47]);
    product(['sku' => 'RAIL', 'model' => 'BA-CORHSR', 'product_type' => 'safety_rail', 'name' => '36" x 30" Compression Roof Hatch Safety Railing', 'nominal_width' => 36, 'nominal_height' => 30]);

    $skus = app(AccessorySuggester::class)->addOnCandidates($hatch, [], 10)->pluck('sku')->all();

    expect($skus)->toBe(['RAIL', 'BA-LDR-08']);
});
