<?php

use App\Models\Draft;
use App\Models\Product;
use App\Services\Drafts\DraftValidator;

function panel12(array $overrides = []): array
{
    return item(array_merge(['quote' => '12x12 access panel', 'size' => ['width' => 12, 'height' => 12, 'quote' => '12x12']], $overrides));
}

function colorOption(Product $product, int $storeOptionId, array $choices): void
{
    $option = $product->options()->create(['store_option_id' => $storeOptionId, 'label' => 'Choose a Color', 'display_type' => 'product-list', 'required' => true, 'position' => 0]);
    foreach ($choices as $i => [$valueId, $name, $cents, $default]) {
        $option->values()->create(['store_value_id' => $valueId, 'raw_label' => $name, 'name' => $name, 'price_delta_cents' => $cents, 'is_default' => $default, 'position' => $i]);
    }
}

it('preselects nothing among equal matches and asks the salesperson to pick, bestseller first', function () {
    product(['sku' => 'QUIET', 'popularity' => 1]);
    product(['sku' => 'HOT', 'popularity' => 50]);

    $draft = draftFor('12x12 access panel', [panel12()]);
    $line = $draft['lines'][0];
    $flag = collect($draft['flags'])->firstWhere('code', 'pick_needed');

    expect($line['selected'])->toBeNull()
        ->and($line['status'])->toBe('choose')
        ->and($line['shortlist_count'])->toBe(2)
        ->and(array_column($line['options'], 'sku'))->toBe(['HOT', 'QUIET'])
        ->and($line['options'][0]['tags'])->toBe(['Store bestseller'])
        ->and($flag)->toMatchArray(['severity' => 'warning', 'line_id' => $line['id']])
        ->and($draft['can_finalize'])->toBeFalse();

    $this->patch("/drafts/{$draft['ulid']}/lines/{$line['id']}", ['product_id' => product_id('QUIET')])->assertRedirect();
    $after = showDraft($draft['ulid']);

    expect($after['lines'][0]['selected']['sku'])->toBe('QUIET')
        ->and(collect($after['flags'])->firstWhere('code', 'pick_needed'))->toBeNull();
});

it('preselects only when a stated detail or feature settles it, never on wording alone', function () {
    product(['sku' => 'POPULAR', 'popularity' => 99]);
    product(['sku' => 'DRYWALL', 'surface_type' => ['Drywall']]);

    $byDetails = draftFor('12x12 access panel for drywall', [panel12(['surface_type' => ['value' => 'Drywall', 'quote' => 'drywall']])])['lines'][0];

    Product::query()->delete();
    product(['sku' => 'PLAIN', 'name' => '12" x 12" Access Door', 'popularity' => 99]);
    product(['sku' => 'WORDED', 'name' => '12" x 12" Recessed Access Panel with Piano Hinge']);

    $byWording = draftFor('12x12 recessed access panel with piano hinge', [panel12(['quote' => '12x12 recessed access panel with piano hinge'])])['lines'][0];

    expect($byDetails['selected']['sku'])->toBe('DRYWALL')
        ->and($byDetails['pick'])->toMatchArray(['confidence' => 'best_fit', 'reason' => 'Confirms 1 of 1 details in the notes; the next best confirms 0.'])
        ->and($byWording['selected'])->toBeNull()
        ->and($byWording['shortlist_count'])->toBe(2);
});

it('lists the flange a drywall surface needs first among equals', function () {
    product(['sku' => 'EXPOSED', 'surface_type' => ['Drywall'], 'flange_type' => ['Exposed Flange'], 'popularity' => 99]);
    product(['sku' => 'MUD-IN', 'surface_type' => ['Drywall'], 'flange_type' => ['Drywall Flange'], 'popularity' => 1]);

    $line = draftFor('12x12 panel in the drywall lid', [panel12(['surface_type' => ['value' => 'Drywall', 'quote' => 'drywall']])])['lines'][0];

    expect($line['selected'])->toBeNull()
        ->and(array_column($line['options'], 'sku'))->toBe(['MUD-IN', 'EXPOSED'])
        ->and($line['options'][0]['tags'])->toBe(['Drywall Flange for drywall'])
        ->and($line['options'][1]['tags'])->toBe(['Store bestseller']);
});

it('preselects nothing when every product contradicts the notes', function () {
    product(['sku' => 'STEEL', 'material' => ['Steel']]);

    $line = draftFor('12x12 aluminum panel', [panel12(['material' => ['value' => 'Aluminum', 'quote' => 'aluminum']])])['lines'][0];

    expect($line['status'])->toBe('choose')
        ->and($line['selected'])->toBeNull()
        ->and(array_column($line['options'], 'group'))->toBe(['also']);
});

it('does not call words from the chosen product name a missing add-on', function () {
    product(['sku' => 'BA-FD-HG-12-12', 'name' => '12" x 12" Fire-Rated Flush Access Panel for Walls - With Heavy Gauge Steel']);

    $draft = draftFor('12x12 panel with heavy gauge steel', [panel12([
        'options_wanted' => [['text' => 'Heavy Gauge', 'quote' => 'heavy gauge']],
    ])]);

    expect(collect($draft['flags'])->firstWhere('code', 'option_unmatched'))->toBeNull();
});

it('finds a bare quantity on the item line, but not a gauge or a size', function () {
    $notes = "I need 5 24\" x 24\" panels\nand a 16 gauge 12x12 door";

    $items = app(DraftValidator::class)->validate([
        item(['quote' => '24" x 24" panels', 'quantity' => ['value' => 5, 'quote' => '5']]),
        item(['quote' => '12x12 door', 'quantity' => ['value' => 16, 'quote' => '16']]),
    ], $notes);

    expect($items[0]->value('quantity'))->toBe(5)
        ->and($items[0]->specs['quantity']['quote'])->toBe('I need 5 24" x 24" panels')
        ->and($items[1]->has('quantity'))->toBeFalse();
});

it('tags the color chosen on another line and offers it in one click', function () {
    $hatch = product(['sku' => 'HATCH', 'product_type' => 'roof_hatch', 'nominal_width' => 30, 'nominal_height' => 36]);
    colorOption($hatch, 700, [[1, 'Gray', 0, true], [2, 'Red', 12195, false]]);
    $door = product(['sku' => 'DOOR']);
    colorOption($door, 800, [[3, 'White', 0, true], [4, 'Safety Red', 5000, false]]);

    $draft = draftFor('30x36 roof hatch and a 12x12 panel', [
        item(['product_type' => 'roof_hatch', 'quote' => '30x36 roof hatch', 'size' => ['width' => 30, 'height' => 36, 'quote' => '30x36']]),
        panel12(),
    ]);
    [$hatchLine, $doorLine] = $draft['lines'];
    $this->patch("/drafts/{$draft['ulid']}/lines/{$hatchLine['id']}", ['option' => ['store_option_id' => 700, 'store_value_id' => 2]]);

    $shown = showDraft($draft['ulid']);
    $colors = $shown['lines'][1]['option_groups'][0];
    $flag = collect($shown['flags'])->firstWhere('code', 'color_match');

    expect(collect($colors['choices'])->firstWhere('name', 'Safety Red')['same_as'])->toBe([1])
        ->and(collect($colors['choices'])->firstWhere('name', 'White')['same_as'])->toBe([])
        ->and($flag)->toMatchArray(['line_id' => $doorLine['id'], 'action' => ['type' => 'choose_option', 'value' => ['store_option_id' => 800, 'store_value_id' => 4]]])
        ->and($flag['message'])->toContain('matches the color chosen on line 1');

    $this->patch("/drafts/{$draft['ulid']}/lines/{$doorLine['id']}", ['option' => ['store_option_id' => 800, 'store_value_id' => 4]]);

    expect(collect(showDraft($draft['ulid'])['flags'])->firstWhere('code', 'color_match'))->toBeNull();
});

it('redoes automatic preselections on open drafts without touching chosen lines', function () {
    product(['sku' => 'ONLY']);
    $draft = draftFor('12x12 access panel', [panel12(), panel12(['quote' => 'another 12x12']), panel12(['quote' => 'a third 12x12'])]);
    [$empty, $chosen, $stale] = Draft::first()->lines;
    $empty->update(['product_id' => null, 'product_picked_by' => null]);
    $chosen->update(['product_picked_by' => 'salesperson']);
    product(['sku' => 'TWIN']); // now ONLY has an equal: an automatic pick no longer stands

    $this->artisan('drafts:preselect')->assertSuccessful();

    expect($empty->fresh()->product_id)->toBeNull()
        ->and($stale->fresh())->product_id->toBeNull()->product_picked_by->toBeNull()
        ->and($chosen->fresh())->product_id->toBe(product_id('ONLY'))->product_picked_by->toBe('salesperson');
});

it('marks a line added from a suggestion', function () {
    product(['sku' => 'ONLY']);
    $rail = product(['sku' => 'RAIL', 'product_type' => 'safety_rail']);
    $draft = draftFor('12x12 access panel', [panel12()]);

    $this->post("/drafts/{$draft['ulid']}/lines", ['product_id' => $rail->id, 'quantity' => 1, 'from_suggestion' => true])->assertRedirect();

    expect(showDraft($draft['ulid'])['lines'][1])->toMatchArray(['source' => 'suggestion', 'number' => 2]);
});
