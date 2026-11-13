<?php

use App\Services\Drafts\DraftValidator;
use App\Services\Drafts\InvalidExtraction;

function extracted(array $overrides = []): array
{
    return array_merge([
        'product_type' => 'access_door',
        'quote' => '4 x 12x12 access panels',
        'quantity' => null, 'size' => null, 'fire_rated' => null, 'insulated' => null,
        'application' => null, 'surface_type' => null, 'material' => null, 'flange_type' => null, 'load_psf' => null,
    ], $overrides);
}

const MESSAGE = "Hi,\nPlease quote 4 x 12x12 access panels for a drywall ceiling, fire rated if possible.\nAlso one 2' x 3' roof hatch.";

it('keeps specs whose quotes are verbatim in the message', function () {
    [$item] = (new DraftValidator)->validate([extracted([
        'quantity' => ['value' => 4, 'quote' => '4 x'],
        'size' => ['width' => 12, 'height' => 12, 'quote' => '12x12'],
        'fire_rated' => ['value' => true, 'quote' => 'fire rated if possible'],
        'surface_type' => ['value' => 'Drywall', 'quote' => 'drywall'],
        'application' => ['value' => 'Ceiling', 'quote' => 'ceiling'],
    ])], MESSAGE);

    expect($item->type)->toBe('access_door')
        ->and($item->quote)->toBe('4 x 12x12 access panels')
        ->and($item->value('size'))->toBe(['width' => 12.0, 'height' => 12.0])
        ->and($item->value('fire_rated'))->toBeTrue()
        ->and($item->value('surface_type'))->toBe('Drywall')
        ->and($item->rejected)->toBe([]);
});

it('drops specs the customer never wrote', function () {
    [$item] = (new DraftValidator)->validate([extracted([
        'material' => ['value' => 'Stainless Steel', 'quote' => 'stainless steel'], // not in message
        'flange_type' => ['value' => 'Exposed Flange', 'quote' => 'exposed flange'],
    ])], MESSAGE);

    expect($item->has('material'))->toBeFalse()
        ->and($item->has('flange_type'))->toBeFalse()
        ->and($item->rejected)->toBe(['material', 'flange_type']);
});

it('drops sizes whose numbers are not in the quote, but accepts feet converted to inches', function () {
    $validator = new DraftValidator;

    [$wrong] = $validator->validate([extracted(['size' => ['width' => 24, 'height' => 24, 'quote' => '12x12']])], MESSAGE);
    [$feet] = $validator->validate([extracted([
        'product_type' => 'roof_hatch', 'quote' => "one 2' x 3' roof hatch",
        'size' => ['width' => 24, 'height' => 36, 'quote' => "2' x 3'"],
    ])], MESSAGE);

    expect($wrong->has('size'))->toBeFalse()
        ->and($wrong->rejected)->toBe(['size'])
        ->and($feet->value('size'))->toBe(['width' => 24.0, 'height' => 36.0]);
});

it('checks the quantity against its own quote', function () {
    $message = 'We need two roof hatches and 4 access panels. Budget is $1,200.';
    $validator = new DraftValidator;

    $quantity = fn (array $spec) => $validator->validate([extracted(['quote' => '4 access panels', 'quantity' => $spec])], $message)[0];

    expect($quantity(['value' => 4, 'quote' => '4 access panels'])->value('quantity'))->toBe(4)
        ->and($quantity(['value' => 2, 'quote' => 'two roof hatches'])->value('quantity'))->toBe(2)
        // A bare digit matches "$1,200" too: not evidence.
        ->and($quantity(['value' => 2, 'quote' => '2'])->has('quantity'))->toBeFalse()
        // Hallucinated number with a real quote.
        ->and($quantity(['value' => 40, 'quote' => '4 access panels'])->has('quantity'))->toBeFalse();
});

it('falls back to the item\'s own words when the quantity quote is a bare digit', function () {
    $message = "Please quote:\n- 4 x 12x12 access panels\n- 2 floor doors for the mechanical room";

    [$item] = (new DraftValidator)->validate([extracted([
        'product_type' => 'floor_door', 'quote' => '2 floor doors',
        'quantity' => ['value' => 2, 'quote' => '2'],
    ])], $message);

    expect($item->value('quantity'))->toBe(2)
        ->and($item->specs['quantity']['quote'])->toBe('2 floor doors');
});

it('does not let a spec on one line leak onto another item', function () {
    $message = "Please quote:\n- 4 x 12x12 access panels for a drywall ceiling, fire rated if possible\n- 2 floor doors for the mechanical room\nAll must be galvanized steel.";
    $fire = ['value' => true, 'quote' => 'fire rated if possible'];

    [$panels, $floor] = (new DraftValidator)->validate([
        extracted(['quote' => '12x12 access panels', 'fire_rated' => $fire]),
        extracted(['product_type' => 'floor_door', 'quote' => '2 floor doors', 'fire_rated' => $fire,
            'material' => ['value' => 'Galvanized Steel', 'quote' => 'galvanized steel']]),
    ], $message);

    expect($panels->value('fire_rated'))->toBeTrue()
        ->and($floor->has('fire_rated'))->toBeFalse()
        ->and($floor->rejected)->toBe(['fire_rated'])
        // A line no item claims ("All must be…") applies to everyone.
        ->and($floor->value('material'))->toBe('Galvanized Steel');
});

it('keeps shared specs in a single-paragraph message', function () {
    $message = 'Need 4 12x12 access panels and 2 floor doors, all fire rated.';
    $fire = ['value' => true, 'quote' => 'all fire rated'];

    [, $floor] = (new DraftValidator)->validate([
        extracted(['quote' => '4 12x12 access panels', 'fire_rated' => $fire]),
        extracted(['product_type' => 'floor_door', 'quote' => '2 floor doors', 'fire_rated' => $fire]),
    ], $message);

    expect($floor->value('fire_rated'))->toBeTrue();
});

it('drops values outside the vocabulary and unknown product types', function () {
    [$item] = (new DraftValidator)->validate([extracted([
        'product_type' => 'garage_door',
        'surface_type' => ['value' => 'Gypsum', 'quote' => 'drywall'],
    ])], MESSAGE);

    expect($item->type)->toBeNull()
        ->and($item->has('surface_type'))->toBeFalse();
});

it('ignores differences in case, whitespace and quote marks', function () {
    [$item] = (new DraftValidator)->validate([extracted([
        'quote' => "4  X 12x12\nACCESS panels",
        'size' => ['width' => 24, 'height' => 36, 'quote' => '2’ x 3’'],
    ])], MESSAGE);

    expect($item->quote)->not->toBeNull()
        ->and($item->has('size'))->toBeTrue();
});

it('rejects a payload that is not a list of items', function () {
    (new DraftValidator)->validate(['product_type' => 'access_door'], MESSAGE);
})->throws(InvalidExtraction::class);

it('does not take a flange from a surface word', function () {
    [$item] = (new DraftValidator)->validate([extracted([
        'surface_type' => ['value' => 'Drywall', 'quote' => 'drywall'],
        'flange_type' => ['value' => 'Drywall Flange', 'quote' => 'drywall'],
    ])], MESSAGE);

    expect($item->value('surface_type'))->toBe('Drywall')
        ->and($item->has('flange_type'))->toBeFalse()
        ->and($item->rejected)->toBe(['flange_type']);
});
