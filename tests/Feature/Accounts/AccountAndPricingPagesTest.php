<?php

use App\Models\Account;
use App\Models\PriceRule;
use Inertia\Testing\AssertableInertia as Assert;

it('creates, edits and deletes an account', function () {
    $this->post('/accounts', [
        'name' => 'Lakeside Hospital',
        'contacts' => [['name' => 'Ana Ruiz', 'email' => 'ana@lakeside.example', 'phone' => null]],
        'discount_pct' => 5,
        'max_discount_pct' => 12,
        'tax_exempt' => true,
    ])->assertRedirect();

    $account = Account::firstWhere('name', 'Lakeside Hospital');
    expect($account->contacts[0]['name'])->toBe('Ana Ruiz')->and($account->maxDiscountPct())->toBe(12.0);

    $this->put("/accounts/{$account->id}", ['name' => 'Lakeside Hospital', 'discount_pct' => 5, 'max_discount_pct' => null])->assertRedirect();
    expect($account->refresh()->maxDiscountPct())->toBe((float) config('pricing.max_discount_pct'));

    $this->delete("/accounts/{$account->id}")->assertRedirect('/accounts');
    expect(Account::count())->toBe(0);
});

it('rejects a maximum discount below the contract discount', function () {
    $this->post('/accounts', ['name' => 'X', 'discount_pct' => 20, 'max_discount_pct' => 10])->assertSessionHasErrors('max_discount_pct');
});

it('lists and searches accounts with typo tolerance', function () {
    account(['name' => 'Shah Construction']);
    account(['name' => 'Northside School District']);

    $this->get('/accounts')->assertOk()->assertInertia(fn (Assert $page) => $page->component('accounts/index')->where('accounts.total', 2));
    $this->getJson('/accounts/lookup?q=shah')->assertOk()->assertJsonPath('data.0.name', 'Shah Construction');
    $this->getJson('/accounts/lookup?q=nortside')->assertOk()->assertJsonPath('data.0.name', 'Northside School District'); // per-word typo
    $this->getJson('/accounts/lookup?q=nor')->assertOk()->assertJsonPath('data.0.name', 'Northside School District'); // partial word
});

it('manages price rules', function () {
    $shah = account();

    $this->get('/pricing')->assertOk()->assertInertia(fn (Assert $page) => $page->component('pricing/index'));
    $this->post('/pricing', ['label' => 'Shah hatches', 'account_id' => $shah->id, 'product_type' => 'roof_hatch', 'min_quantity' => 1, 'discount_pct' => 12])
        ->assertRedirect();
    $rule = PriceRule::first();

    $this->patch("/pricing/{$rule->id}", ['active' => false]);
    expect($rule->refresh()->active)->toBeFalse();

    $this->post('/pricing', ['label' => 'bad', 'min_quantity' => 1, 'discount_pct' => 0, 'product_type' => 'garage'])
        ->assertSessionHasErrors(['discount_pct', 'product_type']);

    $this->delete("/pricing/{$rule->id}");
    expect(PriceRule::count())->toBe(0);
});
