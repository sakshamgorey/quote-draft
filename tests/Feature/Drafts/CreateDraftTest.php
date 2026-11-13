<?php

use App\Ai\Agents\QuoteExtractor;
use App\Models\Draft;
use App\Models\Product;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;

beforeEach(function () {
    product(['sku' => 'BA-AHD-12-12', 'flange_type' => ['Exposed Flange'], 'price' => 54.25]);
    product(['sku' => 'BA-AHD-GYP-12-12', 'flange_type' => ['Drywall Flange'], 'surface_type' => ['Drywall'], 'price' => 61.10]);
    product(['sku' => 'BA-PFI-12-12', 'fire_rated' => true, 'flange_type' => ['Exposed Flange'], 'price' => 180.00,
        'image_url' => 'https://cdn.example.com/BA-PFI-12-12.jpg']);
    product(['sku' => 'BA-AHD-12-24', 'nominal_width' => 12, 'nominal_height' => 24, 'price' => 80.00]);
    product(['sku' => 'BA-AHD-24-24', 'nominal_width' => 24, 'nominal_height' => 24, 'price' => 99.00]);
    product(['sku' => 'BA-RH-30-36', 'product_type' => 'roof_hatch', 'nominal_width' => 30, 'nominal_height' => 36, 'price' => null]);
});

it('shows the create page', function () {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('drafts/create')->where('maxLength', 5000));
});

it('makes exactly one LLM call, saves the draft and matches products in Postgres', function () {
    $draft = draftFor('Hi, need 4 x 12x12 access panels for a drywall ceiling.', [item([
        'quote' => '4 x 12x12 access panels for a drywall ceiling',
        'quantity' => ['value' => 4, 'quote' => '4 x 12x12 access panels'],
        'size' => ['width' => 12, 'height' => 12, 'quote' => '12x12'],
        'surface_type' => ['value' => 'Drywall', 'quote' => 'drywall'],
    ])]);

    QuoteExtractor::assertPromptedTimes(1);
    expect($draft['lines'])->toHaveCount(1)
        ->and($draft['lines'][0]['status'])->toBe('ready')
        ->and($draft['lines'][0]['quantity'])->toBe(4)
        // Drywall-tagged door ranks first and is preselected; 12x24 / 24x24 excluded.
        ->and(array_column($draft['lines'][0]['options'], 'sku'))->toBe(['BA-AHD-GYP-12-12', 'BA-AHD-12-12', 'BA-PFI-12-12'])
        ->and($draft['lines'][0]['selected']['sku'])->toBe('BA-AHD-GYP-12-12')
        ->and($draft['lines'][0]['pick'])->toMatchArray(['confidence' => 'best_fit'])
        ->and($draft['can_finalize'])->toBeTrue();
});

it('never offers products disabled on the store', function () {
    product(['sku' => 'BA-OLD-12-12', 'is_available' => false, 'price' => 10]);

    $draft = draftFor('12x12 access panel', [item(['size' => ['width' => 12, 'height' => 12, 'quote' => '12x12']])]);

    expect(array_column($draft['lines'][0]['options'], 'sku'))->not->toContain('BA-OLD-12-12');
});

it('ranks store bestsellers first among equally good matches', function () {
    product(['sku' => 'BA-HOT-12-12', 'popularity' => 5000, 'price' => 70]);

    $draft = draftFor('12x12 access panel', [item(['size' => ['width' => 12, 'height' => 12, 'quote' => '12x12']])]);

    expect($draft['lines'][0]['options'][0]['sku'])->toBe('BA-HOT-12-12');
});

it('refuses to add or pick a product that is disabled on the store', function () {
    $draft = draftFor('12x12 access panel', [item(['size' => ['width' => 12, 'height' => 12, 'quote' => '12x12']])]);
    $old = product(['sku' => 'BA-OLD-24-24', 'is_available' => false]);
    $line = Draft::first()->lines->first();

    $this->post("/drafts/{$draft['ulid']}/lines", ['product_id' => $old->id])->assertSessionHasErrors('product_id');
    $this->patch("/drafts/{$draft['ulid']}/lines/{$line->id}", ['product_id' => $old->id])->assertSessionHasErrors('product_id');
});

it('auto-picks when exactly one product fits', function () {
    $draft = draftFor('one 12x12 fire rated access panel', [item([
        'quote' => '12x12 fire rated access panel',
        'size' => ['width' => 12, 'height' => 12, 'quote' => '12x12'],
        'fire_rated' => ['value' => true, 'quote' => 'fire rated'],
    ])]);

    expect($draft['lines'][0]['status'])->toBe('ready')
        ->and($draft['lines'][0]['selected']['sku'])->toBe('BA-PFI-12-12')
        ->and($draft['totals']['subtotal_cents'])->toBe(18000)
        ->and($draft['can_finalize'])->toBeTrue();
});

it('asks for the size instead of guessing, and re-matches when the salesperson answers', function () {
    $draft = draftFor('and a roof hatch', [item(['product_type' => 'roof_hatch', 'quote' => 'a roof hatch'])]);

    expect($draft['lines'][0]['status'])->toBe('needs_details')
        ->and($draft['lines'][0]['questions'][0])->toBe('What opening size do you need for the roof hatch (width x height, in inches)?');

    $line = Draft::first()->lines->first();
    $this->patch("/drafts/{$draft['ulid']}/lines/{$line->id}", ['size' => ['width' => 36, 'height' => 30]])->assertRedirect();

    $line->refresh();
    expect($line->specs['size'])->toEqual(['value' => ['width' => 36, 'height' => 30], 'quote' => null]) // jsonb reorders keys
        ->and($line->product->sku)->toBe('BA-RH-30-36'); // only one fits (either orientation) → auto-picked
    QuoteExtractor::assertPromptedTimes(1); // the original read only: answering never calls the LLM
});

it('lets the salesperson pick, change quantity and remove lines; every remaining line needs a product', function () {
    $draft = draftFor('4 x 12x12 access panels and a roof hatch', [
        item(['quote' => '12x12 access panels', 'size' => ['width' => 12, 'height' => 12, 'quote' => '12x12']]),
        item(['product_type' => 'roof_hatch', 'quote' => 'a roof hatch']),
        item(['product_type' => 'unknown', 'quote' => 'and']),
    ]);
    [$doors, $hatch, $junk] = Draft::first()->lines;
    $url = fn ($line) => "/drafts/{$draft['ulid']}/lines/{$line->id}";

    $this->patch($url($doors), ['product_id' => product_id('BA-AHD-GYP-12-12'), 'quantity' => 4]);
    $this->delete($url($junk));

    $page = showDraft($draft['ulid']);
    expect(array_column($page['lines'], 'status'))->toBe(['ready', 'needs_details'])
        ->and($page['lines'][0]['line_total_cents'])->toBe(4 * 6110)
        ->and($page['totals']['subtotal_cents'])->toBe(24440)
        ->and($page['can_finalize'])->toBeFalse() // the hatch still has no product
        ->and($page['can_send'])->toBeFalse();

    $this->patch($url($hatch), ['product_id' => product_id('BA-RH-30-36')]);

    expect(showDraft($draft['ulid'])['can_send'])->toBeTrue();
});

it('finalizes with a price snapshot that later catalog changes cannot touch', function () {
    $draft = draftFor('one 12x12 fire rated access panel', [item([
        'quote' => '12x12 fire rated access panel',
        'size' => ['width' => 12, 'height' => 12, 'quote' => '12x12'],
        'fire_rated' => ['value' => true, 'quote' => 'fire rated'],
    ])]);

    $this->post("/drafts/{$draft['ulid']}/finalize")->assertRedirect();
    Product::where('sku', 'BA-PFI-12-12')->update(['price' => 999]);

    $page = $this->get("/drafts/{$draft['ulid']}")->viewData('page')['props']['draft'];
    $line = Draft::first()->lines->first();

    expect($page['status'])->toBe('finalized')
        ->and($page['lines'][0]['selected']['image_url'])->toBe('https://cdn.example.com/BA-PFI-12-12.jpg')
        ->and($page['lines'][0]['selected']['price_cents'])->toBe(18000)
        ->and($page['totals']['subtotal_cents'])->toBe(18000);

    // Locked.
    $this->patch("/drafts/{$draft['ulid']}/lines/{$line->id}", ['quantity' => 2])->assertStatus(409);
});

it('refuses to finalize while a line is unresolved', function () {
    $draft = draftFor('and a roof hatch', [item(['product_type' => 'roof_hatch', 'quote' => 'a roof hatch'])]);

    $this->post("/drafts/{$draft['ulid']}/finalize")->assertSessionHasErrors('finalize');
    expect(Draft::first()->status)->toBe('draft');
});

it('adds a product the salesperson searched for', function () {
    $draft = draftFor('and a roof hatch', [item(['product_type' => 'roof_hatch', 'quote' => 'a roof hatch'])]);

    $this->post("/drafts/{$draft['ulid']}/lines", ['product_id' => product_id('BA-AHD-24-24'), 'quantity' => 2])->assertRedirect();

    $page = $this->get("/drafts/{$draft['ulid']}")->viewData('page')['props']['draft'];
    expect($page['lines'][1])->toMatchArray(['source' => 'manual', 'status' => 'ready', 'quantity' => 2, 'line_total_cents' => 19800]);
});

it('offers the nearest sizes when the exact size does not exist', function () {
    $draft = draftFor('one 13x13 access door', [item([
        'quote' => '13x13 access door',
        'size' => ['width' => 13, 'height' => 13, 'quote' => '13x13'],
    ])]);

    expect($draft['lines'][0]['status'])->toBe('no_match')
        ->and($draft['lines'][0]['questions'][0])->toStartWith('We don\'t list a 13" x 13" access door. Closest opening sizes we carry: 12" x 12"');
});

it('never uses a size the model invented', function () {
    $draft = draftFor('need a 12x12 access panel', [item(['size' => ['width' => 24, 'height' => 24, 'quote' => '12x12']])]);

    expect($draft['lines'][0]['status'])->toBe('needs_details')
        ->and($draft['lines'][0]['questions'][0])->toContain('confirm the opening size');
});

it('keeps the message and shows a friendly error when the AI provider is overloaded', function () {
    QuoteExtractor::fake(fn () => throw ProviderOverloadedException::forProvider('gemini', 503));

    $this->from('/')->post('/drafts', ['message' => 'need a 12x12 access panel', 'account_id' => account()->id])
        ->assertRedirect('/')
        ->assertSessionHasErrors(['message' => 'The AI service is busy right now. Please try again in a minute.']);
    expect(Draft::count())->toBe(0);
});

it('shows a friendly error when the AI provider times out', function () {
    QuoteExtractor::fake(fn () => throw ProviderConnectionException::forProvider('gemini'));

    $this->from('/')->post('/drafts', ['message' => 'need a 12x12 access panel', 'account_id' => account()->id])
        ->assertRedirect('/')
        ->assertSessionHasErrors(['message' => 'The AI service took too long to answer. Please try again.']);
    expect(Draft::count())->toBe(0);
});

it('validates the message without calling the LLM', function () {
    QuoteExtractor::fake()->preventStrayPrompts();

    $this->post('/drafts', ['message' => ''])->assertSessionHasErrors('message');
    $this->post('/drafts', ['message' => str_repeat('a', 5001)])->assertSessionHasErrors('message');

    QuoteExtractor::assertNeverPrompted();
});

it('lists drafts newest first', function () {
    draftFor('one 12x12 access panel', [item()]);

    $this->get('/drafts')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('drafts/index')
        ->where('drafts.total', 1)
        ->where('drafts.data.0.excerpt', 'one 12x12 access panel'));
});
