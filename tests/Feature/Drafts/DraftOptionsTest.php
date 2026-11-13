<?php

use App\Models\Draft;
use App\Models\DraftLine;
use App\Models\Product;
use App\Services\Drafts\DraftValidator;

/** BA-AHD-like door: lock (required, standard), gasket (required, standard), material (required, standard). */
function doorWithOptions(array $attributes = []): Product
{
    $door = product(['sku' => 'BA-AHD-12-12', 'price' => 100.00, 'material' => ['Steel']] + $attributes);

    $groups = [
        [5744, 'Choose a Lock / Latch', [
            [1, 'Screwdriver Operated Cam Latch', 0, true], [2, 'Key Operated Cylinder Cam Latch', 1600, false],
            [3, '4 Key Handle Operated Cam Latch', 13800, false], [4, 'Mortise Deadbolt Lock (with cylinder)', 29300, false]]],
        [5745, 'Gasket', [[5, 'AHD Without Gasket', 0, true], [6, 'AHD Neoprene Gasket', 4700, false]]],
        [5746, 'Material', [[7, 'Cold Rolled Steel - White Primer Powder Coat', 0, true], [8, 'Stainless Steel 304 #4 - Brushed finish', 8700, false]]],
    ];

    foreach ($groups as $position => [$storeId, $label, $values]) {
        $option = $door->options()->create(['store_option_id' => $storeId, 'label' => $label, 'display_type' => 'product-list', 'required' => true, 'position' => $position]);
        foreach ($values as $i => [$valueId, $name, $cents, $default]) {
            $option->values()->create(['store_value_id' => $valueId, 'raw_label' => $name, 'name' => $name, 'price_delta_cents' => $cents,
                'lead_time_text' => $cents ? '+ 5 days' : null, 'lead_time_days' => $cents ? 5 : null, 'is_default' => $default, 'position' => $i]);
        }
    }

    return $door;
}

function doorItem(array $overrides = []): array
{
    return item(['quote' => '12x12 panel', 'size' => ['width' => 12, 'height' => 12, 'quote' => '12x12']] + $overrides);
}

function group(array $line, string $label): array
{
    return collect($line['option_groups'])->firstWhere('label', $label);
}

it('picks the standard choices when a product is chosen', function () {
    doorWithOptions();

    $line = draftFor('12x12 panel', [doorItem()])['lines'][0];

    expect($line['status'])->toBe('ready')
        ->and(array_map(fn ($g) => $g['selected']['name'], $line['option_groups']))
        ->toBe(['Screwdriver Operated Cam Latch', 'AHD Without Gasket', 'Cold Rolled Steel - White Primer Powder Coat'])
        ->and($line['options_price_cents'])->toBe(0);
});

it('maps option wishes from the notes onto the product\'s real choices, and flags them to check', function () {
    doorWithOptions();

    $draft = draftFor('12x12 panel with keyed lock and a gasket', [doorItem([
        'quote' => '12x12 panel with keyed lock and a gasket',
        'options_wanted' => [['text' => 'keyed lock', 'quote' => 'keyed lock'], ['text' => 'gasket', 'quote' => 'a gasket']],
    ])]);
    $line = $draft['lines'][0];

    expect(group($line, 'Choose a Lock / Latch')['selected'])->toMatchArray(['name' => 'Key Operated Cylinder Cam Latch', 'source' => 'notes', 'quote' => 'keyed lock'])
        ->and(group($line, 'Gasket')['selected']['name'])->toBe('AHD Neoprene Gasket')
        ->and($line['options_price_cents'])->toBe(1600 + 4700)
        ->and(collect($draft['flags'])->where('code', 'option_from_notes'))->toHaveCount(2)
        ->and($line['options_lead_time'])->toBe('+ 5 days');
});

it('chooses the option that provides a stated detail, like stainless', function () {
    doorWithOptions();

    $line = draftFor('12x12 stainless panel', [doorItem([
        'quote' => '12x12 stainless panel',
        'material' => ['value' => 'Stainless Steel', 'quote' => 'stainless'],
    ])])['lines'][0];

    expect(group($line, 'Material')['selected'])->toMatchArray(['name' => 'Stainless Steel 304 #4 - Brushed finish', 'source' => 'notes'])
        ->and($line['selected']['checks'][0]['verdict'])->toBe('option');
});

it('blocks a requirement supplied by an option when that option is changed away', function () {
    doorWithOptions();
    $draft = draftFor('12x12 stainless panel', [doorItem([
        'quote' => '12x12 stainless panel',
        'material' => ['value' => 'Stainless Steel', 'quote' => 'stainless'],
    ])]);
    $line = $draft['lines'][0];

    $this->patch("/drafts/{$draft['ulid']}/lines/{$line['id']}", ['option' => ['store_option_id' => 5746, 'store_value_id' => 7]])->assertRedirect();

    $after = showDraft($draft['ulid']);
    expect($after['lines'][0]['status'])->toBe('needs_clarification');
    expect($after['lines'][0]['selected']['sku'])->toBe('BA-AHD-12-12');
    expect($after['can_finalize'])->toBeFalse();

    $this->patch("/drafts/{$draft['ulid']}/lines/{$line['id']}", ['option' => ['store_option_id' => 5746, 'store_value_id' => 8]])->assertRedirect();

    expect(showDraft($draft['ulid'])['lines'][0]['status'])->toBe('ready');
    expect(showDraft($draft['ulid'])['can_finalize'])->toBeTrue();
});

it('preserves chosen options and totals while a SKU conflict is reviewed and corrected', function () {
    doorWithOptions();
    $draft = draftFor('BA-AHD-12-12 fire-rated with keyed lock', [doorItem([
        'quote' => 'BA-AHD-12-12 fire-rated with keyed lock',
        'model' => ['value' => 'BA-AHD-12-12', 'quote' => 'BA-AHD-12-12'],
        'size' => null,
        'fire_rated' => ['value' => true, 'quote' => 'fire-rated'],
        'options_wanted' => [['text' => 'keyed lock', 'quote' => 'keyed lock']],
    ])]);
    $line = $draft['lines'][0];
    $before = DraftLine::find($line['id'])->chosenOptions->toArray();

    expect($line['status'])->toBe('needs_clarification');
    expect($line['unit_price_cents'])->toBe(11600);
    expect($draft['totals']['subtotal_cents'])->toBe(11600);

    $this->patch("/drafts/{$draft['ulid']}/lines/{$line['id']}", ['fire_rated' => 'no'])->assertRedirect();

    $after = showDraft($draft['ulid']);
    expect($after['lines'][0]['status'])->toBe('ready');
    expect($after['lines'][0]['unit_price_cents'])->toBe(11600);
    expect(DraftLine::find($line['id'])->chosenOptions->toArray())->toBe($before);
    expect($after['can_finalize'])->toBeTrue();
});

it('warns when the notes ask for an option the product does not have', function () {
    doorWithOptions();

    $draft = draftFor('12x12 panel in red', [doorItem(['quote' => '12x12 panel in red', 'options_wanted' => [['text' => 'red', 'quote' => 'in red']]])]);

    expect($draft['lines'][0]['unmatched_option_requests'])->toBe([['text' => 'red', 'quote' => 'in red']])
        ->and(collect($draft['flags'])->firstWhere('code', 'option_unmatched')['severity'])->toBe('warning');
});

it('prices options on top of the discounted base, never discounted themselves', function () {
    doorWithOptions();
    $shah = account(['discount_pct' => 10]);

    $draft = draftFor('2 x 12x12 panel stainless', [doorItem([
        'quote' => '2 x 12x12 panel stainless',
        'quantity' => ['value' => 2, 'quote' => '2 x 12x12 panel'],
        'material' => ['value' => 'Stainless Steel', 'quote' => 'stainless'],
    ])], $shah);
    $line = $draft['lines'][0];

    // base 100 − 10% = 90, + 87 stainless = 177 each
    expect($line)->toMatchArray(['base_price_cents' => 9000, 'options_price_cents' => 8700, 'unit_price_cents' => 17700, 'line_total_cents' => 35400])
        ->and($draft['totals']['savings_cents'])->toBe(2000); // only the base was discounted
});

it('preselects the cheapest choice when the store marks none standard, and blocks sending while a required option is empty', function () {
    $door = doorWithOptions();
    $vent = product(['sku' => 'VENT-1', 'product_type' => 'vent_cover', 'nominal_width' => 6, 'nominal_height' => 10, 'price' => 50]);
    $app = $vent->options()->create(['store_option_id' => 9001, 'label' => 'Application', 'display_type' => 'product-list', 'required' => true, 'position' => 0]);
    $app->values()->create(['store_value_id' => 91, 'raw_label' => 'Wall', 'name' => 'Wall', 'price_delta_cents' => 0, 'position' => 0]);
    $app->values()->create(['store_value_id' => 92, 'raw_label' => 'Ceiling', 'name' => 'Ceiling', 'price_delta_cents' => 0, 'position' => 1]);

    $draft = draftFor('12x12 panel and a 6x10 vent', [
        doorItem(),
        item(['product_type' => 'vent_cover', 'quote' => 'a 6x10 vent', 'size' => ['width' => 6, 'height' => 10, 'quote' => '6x10']]),
    ]);
    [$doorLine, $ventLine] = Draft::first()->lines;

    // No standard choice on a required option: the lowest-priced one, flagged for a check.
    expect(group($draft['lines'][1], 'Application')['selected'])->toMatchArray(['name' => 'Wall', 'source' => 'assumed'])
        ->and(collect($draft['flags'])->firstWhere('code', 'option_assumed')['severity'])->toBe('warning');

    // Cleared by hand, a required option blocks sending.
    $this->patch("/drafts/{$draft['ulid']}/lines/{$ventLine->id}", ['option' => ['store_option_id' => 9001, 'store_value_id' => null]])->assertRedirect();
    $cleared = showDraft($draft['ulid']);
    expect(collect($cleared['flags'])->firstWhere('code', 'option_missing')['severity'])->toBe('blocking')
        ->and($cleared['can_send'])->toBeFalse();

    $this->patch("/drafts/{$draft['ulid']}/lines/{$doorLine->id}", ['option' => ['store_option_id' => 5744, 'store_value_id' => 4]])->assertRedirect();
    $this->patch("/drafts/{$draft['ulid']}/lines/{$ventLine->id}", ['option' => ['store_option_id' => 9001, 'store_value_id' => 92]])->assertRedirect();

    $after = showDraft($draft['ulid']);
    expect(group($after['lines'][0], 'Choose a Lock / Latch')['selected'])->toMatchArray(['name' => 'Mortise Deadbolt Lock (with cylinder)', 'source' => 'salesperson'])
        ->and(group($after['lines'][1], 'Application')['selected']['name'])->toBe('Ceiling')
        ->and($after['can_send'])->toBeTrue();
});

it('freezes options at finalize and sends them to Zoho folded into the list price', function () {
    $door = doorWithOptions();
    $draft = draftFor('12x12 panel with keyed lock', [doorItem([
        'quote' => '12x12 panel with keyed lock',
        'options_wanted' => [['text' => 'keyed lock', 'quote' => 'keyed lock']],
    ])]);

    $this->post("/drafts/{$draft['ulid']}/zoho")->assertSessionHas('status');
    $door->options()->first()->values()->where('store_value_id', 2)->update(['price_delta_cents' => 99900]); // later store change

    $final = showDraft($draft['ulid']);
    $item = $final['zoho']['request']['body']['data'][0]['Quoted_Items'][0];

    expect($final['lines'][0]['options_price_cents'])->toBe(1600)
        ->and($item['List_Price'])->toEqual(116) // 100 + 16 (read back from the stored JSON request)
        ->and($item['Description'])->toContain('Choose a Lock / Latch: Key Operated Cylinder Cam Latch (+$16.00)');
});

it('keeps only option wishes whose words are in the notes', function () {
    [$item] = (new DraftValidator)->validate([doorItem([
        'quote' => '12x12 panel with keyed lock',
        'options_wanted' => [['text' => 'keyed lock', 'quote' => 'keyed lock'], ['text' => 'electric opener', 'quote' => 'electric opener']],
    ])], 'Need a 12x12 panel with keyed lock');

    expect($item->optionRequests)->toBe([['text' => 'keyed lock', 'quote' => 'keyed lock']]);
});
