<?php

use App\Models\PriceRule;
use App\Services\Pricing\PriceCalculator;

beforeEach(function () {
    $this->door = product(['sku' => 'BA-AHD-12-12', 'price' => 100.00, 'brand' => 'Cendrex']);
    $this->hatch = product(['sku' => 'BA-RH-30-36', 'product_type' => 'roof_hatch', 'price' => 800.00, 'brand' => 'Bilco']);
});

it('uses the catalog list price without an account or rules', function () {
    expect(PriceCalculator::for(null, null)->price($this->door, 2))
        ->toBe(['list_cents' => 10000, 'discount_pct' => 0.0, 'discount_label' => null, 'unit_cents' => 10000, 'total_cents' => 20000]);
});

it('returns null for price on request', function () {
    expect(PriceCalculator::for(null, null)->price(product(['price' => null]), 1))->toBeNull();
});

it('applies the account contract discount', function () {
    $price = PriceCalculator::for(account(['discount_pct' => 10]), null)->price($this->door, 1);

    expect($price['unit_cents'])->toBe(9000)->and($price['discount_label'])->toBe('Contract discount');
});

it('applies quantity breaks only from the minimum quantity, for the right type and brand', function () {
    PriceRule::create(['label' => '10+ doors', 'product_type' => 'access_door', 'min_quantity' => 10, 'discount_pct' => 5]);
    PriceRule::create(['label' => 'Bilco promo', 'brand' => 'Bilco', 'discount_pct' => 7]);
    $prices = PriceCalculator::for(null, null);

    expect($prices->price($this->door, 9)['discount_pct'])->toBe(0.0)
        ->and($prices->price($this->door, 10)['discount_label'])->toBe('10+ doors')
        ->and($prices->price($this->hatch, 10)['discount_label'])->toBe('Bilco promo'); // type rule doesn't apply to hatches
});

it('picks the single best discount and never stacks them', function () {
    $shah = account(['discount_pct' => 10]);
    PriceRule::create(['label' => 'Shah hatches', 'account_id' => $shah->id, 'product_type' => 'roof_hatch', 'discount_pct' => 12]);

    $hatch = PriceCalculator::for($shah, null)->price($this->hatch, 1);
    $door = PriceCalculator::for($shah, 15)->price($this->door, 1);

    expect($hatch)->toMatchArray(['discount_pct' => 12.0, 'discount_label' => 'Shah hatches', 'unit_cents' => 70400]) // not 10 + 12
        ->and($door)->toMatchArray(['discount_pct' => 15.0, 'discount_label' => 'Quote discount', 'unit_cents' => 8500]);
});

it('ignores other accounts\' rules, inactive rules and rules out of date', function () {
    $other = account(['name' => 'Other Co', 'discount_pct' => 0]);
    PriceRule::create(['label' => 'Other only', 'account_id' => $other->id, 'discount_pct' => 30]);
    PriceRule::create(['label' => 'Off', 'discount_pct' => 30, 'active' => false]);
    PriceRule::create(['label' => 'Expired', 'discount_pct' => 30, 'ends_on' => now()->subDay()]);
    PriceRule::create(['label' => 'Future', 'discount_pct' => 30, 'starts_on' => now()->addDay()]);

    expect(PriceCalculator::for(account(['discount_pct' => 0]), null)->price($this->door, 1)['discount_pct'])->toBe(0.0)
        ->and(PriceCalculator::for($other, null)->price($this->door, 1)['discount_label'])->toBe('Other only');
});
