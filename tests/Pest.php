<?php

use App\Ai\Agents\QuoteExtractor;
use App\Models\Account;
use App\Models\Draft;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Real Postgres (bestdraft_test, see phpunit.xml): text[], tsvector and pg_trgm can't be faked in SQLite.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/** One extracted item as QuoteExtractor returns it. */
function item(array $overrides = []): array
{
    return array_merge([
        'product_type' => 'access_door', 'quote' => '12x12 access panel',
        'quantity' => null, 'size' => null, 'fire_rated' => null, 'insulated' => null,
        'application' => null, 'surface_type' => null, 'material' => null, 'flange_type' => null, 'load_psf' => null,
    ], $overrides);
}

/**
 * POST notes with a faked extractor and return the saved draft as the page sees it.
 *
 * @param  array  $extra  top-level extractor fields (requested_discount, internal_notes, …)
 */
function draftFor(string $message, array $items, ?Account $account = null, array $extra = []): array
{
    QuoteExtractor::fake([['items' => $items] + $extra])->preventStrayPrompts();
    // Drafts need an account; tests that don't care get one with list prices and no contacts.
    $account ??= Account::firstWhere('name', 'List Price Customer')
        ?? account(['name' => 'List Price Customer', 'discount_pct' => 0, 'max_discount_pct' => null, 'contacts' => [], 'payment_terms' => null]);

    $response = test()->post('/drafts', ['message' => $message, 'account_id' => $account->id]);
    $draft = Draft::latest('id')->firstOrFail();
    $response->assertRedirect("/drafts/{$draft->ulid}");

    return showDraft($draft->ulid);
}

function showDraft(string $ulid): array
{
    return test()->get("/drafts/{$ulid}")->viewData('page')['props']['draft'];
}

function account(array $attributes = []): Account
{
    return Account::create(array_merge([
        'name' => 'Shah Construction',
        'contacts' => [['name' => 'Priya Shah', 'email' => 'priya@shah.example', 'phone' => '555-201-3344']],
        'ship_to' => "120 Maple St\nSpringfield, IL",
        'payment_terms' => 'Net 30',
        'discount_pct' => 10,
        'max_discount_pct' => 15,
    ], $attributes));
}

function product_id(string $sku): int
{
    return Product::where('sku', $sku)->value('id');
}

/** A catalog row with sensible defaults; override what the test cares about. */
function product(array $attributes = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'uid' => (string) (1000 + $n),
        'sku' => "BA-TEST-{$n}",
        'model' => 'BA-TEST',
        'name' => "Test product {$n}",
        'url' => "https://www.bestaccessdoors.com/test-{$n}/",
        'product_type' => 'access_door',
        'nominal_width' => 12,
        'nominal_height' => 12,
        'fire_rated' => false,
        'application' => [],
        'surface_type' => [],
        'material' => [],
        'flange_type' => [],
        'purpose' => [],
        'category_paths' => [],
    ], $attributes));
}
