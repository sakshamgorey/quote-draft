<?php

use App\Services\Drafts\DraftValidator;

const PATEL_NOTES = "Garage attic at the Patel house: opening is about 22 1/2 by 54, ceiling's a little under 10 ft. Want an insulated ladder, they'll be up there a lot.needs to support 400lbs";

function atticLadder(string $sku, int $lbs): void
{
    product(['sku' => $sku, 'name' => "22.5\" x 54\" Insulated Attic Ladder - {$lbs} lbs. Load Capacity", 'product_type' => 'ladder',
        'nominal_width' => 22.5, 'nominal_height' => 54, 'application' => ['Attic'], 'insulated' => true]);
}

function patelLadder(array $overrides = []): array
{
    return item(array_merge([
        'product_type' => 'ladder', 'quote' => 'insulated ladder',
        'size' => ['width' => 22.5, 'height' => 54, 'quote' => '22 1/2 by 54'],
        'insulated' => ['value' => true, 'quote' => 'insulated'],
        'application' => ['value' => 'Ceiling', 'quote' => 'ceiling'],
    ], $overrides));
}

it('preselects the only attic ladder rated for the weight, and a ceiling never rules out an attic ladder', function () {
    atticLadder('LIGHT', 300);
    atticLadder('STRONG', 400);
    atticLadder('MEDIUM', 350);

    $line = draftFor(PATEL_NOTES, [patelLadder(['load_lbs' => ['value' => 400, 'quote' => 'support 400lbs']])])['lines'][0];
    $capacity = fn (string $sku) => collect(collect($line['options'])->firstWhere('sku', $sku)['checks'])->firstWhere('spec', 'load_lbs');

    expect($line['selected']['sku'])->toBe('STRONG')
        ->and($line['pick']['confidence'])->toBe('only')
        ->and(collect($line['selected']['checks'])->firstWhere('spec', 'application')['verdict'])->toBe('yes')
        ->and($capacity('STRONG'))->toMatchArray(['verdict' => 'yes', 'value' => '400 lbs'])
        ->and($capacity('MEDIUM'))->toMatchArray(['verdict' => 'no', 'evidence' => '350 lbs'])
        ->and(collect($line['options'])->firstWhere('sku', 'LIGHT')['group'])->toBe('also');
});

it('reads a weight in pounds as a capacity, wherever the model filed it', function (array $filed) {
    $item = app(DraftValidator::class)->validate([patelLadder($filed)], PATEL_NOTES)[0];

    expect($item->value('load_lbs'))->toBe(400.0)
        ->and($item->has('load_psf'))->toBeFalse()
        ->and($item->optionRequests)->toBe([]);
})->with([
    'as PSF' => [['load_psf' => ['value' => 400, 'quote' => 'support 400lbs']]],
    'as an add-on' => [['options_wanted' => [['text' => 'support 400lbs', 'quote' => 'support 400lbs']]]],
]);

it('keeps a load per square foot as PSF', function () {
    $item = app(DraftValidator::class)->validate([item([
        'product_type' => 'floor_door', 'quote' => 'floor hatch', 'load_psf' => ['value' => 300, 'quote' => '300 psf'],
    ])], 'floor hatch, needs to take foot traffic (300 psf)')[0];

    expect($item->value('load_psf'))->toBe(300.0)
        ->and($item->has('load_lbs'))->toBeFalse();
});
