<?php

use App\Models\Draft;
use App\Models\ZohoSubmission;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests(); // dummy mode must never touch the network
    product(['sku' => 'BA-AHD-12-12', 'name' => '12" x 12" Flush Access Door', 'price' => 100.00, 'priced_at' => now()]);
    product(['sku' => 'BA-RH-30-36', 'name' => '30" x 36" Roof Hatch', 'product_type' => 'roof_hatch', 'nominal_width' => 30, 'nominal_height' => 36, 'price' => null]);
});

function readyDraft(): array
{
    $draft = draftFor('3 x 12x12 panel and a 30x36 roof hatch', [
        item(['quote' => '3 x 12x12 panel', 'quantity' => ['value' => 3, 'quote' => '3 x 12x12 panel'], 'size' => ['width' => 12, 'height' => 12, 'quote' => '12x12']]),
        item(['product_type' => 'roof_hatch', 'quote' => 'a 30x36 roof hatch', 'size' => ['width' => 30, 'height' => 36, 'quote' => '30x36']]),
    ], account(['discount_pct' => 10, 'payment_terms' => 'Net 30']));

    return $draft;
}

it('builds the exact Zoho CRM v8 Quotes insert request from the draft', function () {
    $request = readyDraft()['zoho']['request'];
    $quote = $request['body']['data'][0];

    expect($request['method'])->toBe('POST')
        ->and($request['url'])->toBe('https://www.zohoapis.com/crm/v8/Quotes')
        ->and($request['headers']['Authorization'])->toStartWith('Zoho-oauthtoken ')->not->toContain('1000.') // never a real token
        ->and($request['body']['trigger'])->toBe([])
        ->and($quote['Subject'])->toStartWith('Shah Construction – quote draft ')
        ->and($quote['Quote_Stage'])->toBe('Draft')
        ->and($quote['Valid_Till'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and($quote['Quoted_Items'])->toHaveCount(2)
        ->and($quote['Quoted_Items'][0])->toBe([
            'Product_Name' => ['id' => 'ZOHO_ID_FOR_SKU:BA-AHD-12-12', 'name' => '12" x 12" Flush Access Door'],
            'Quantity' => 3,
            'List_Price' => 100.0,
            'Discount' => 30.0, // (100 − 90) × 3
            'Description' => 'SKU BA-AHD-12-12; opening 12" x 12"; 10% Contract discount (base product)',
        ])
        // Price on request: no list price or discount; Zoho uses the product's own price.
        ->and($quote['Quoted_Items'][1])->not->toHaveKeys(['List_Price', 'Discount']);
});

it('finalizes and sends in one step, records the exact request, and never sends twice', function () {
    $draft = readyDraft();

    $this->post("/drafts/{$draft['ulid']}/zoho")->assertSessionHas('status');
    $this->post("/drafts/{$draft['ulid']}/zoho")->assertSessionHas('status', 'Already sent to Zoho.');

    $submission = ZohoSubmission::sole();
    $after = showDraft($draft['ulid']);

    expect(Draft::first()->status)->toBe('finalized')
        ->and($submission->mode)->toBe('dummy')
        ->and($submission->zoho_record_id)->toStartWith('DUMMY-')
        ->and($submission->request)->toBe($after['zoho']['request'])
        ->and($submission->response['data'][0])->toMatchArray(['code' => 'SUCCESS', 'status' => 'success', 'message' => 'record added'])
        ->and($after['zoho']['record_id'])->toBe($submission->zoho_record_id)
        ->and($after['can_send'])->toBeFalse();
});

it('will not send while a line has no product', function () {
    $draft = draftFor('a roof hatch', [item(['product_type' => 'roof_hatch', 'quote' => 'a roof hatch'])], account());

    $this->post("/drafts/{$draft['ulid']}/zoho")->assertSessionHasErrors(['finalize' => 'Pick a product on every line (or remove it) first.']);

    expect(ZohoSubmission::count())->toBe(0)->and(Draft::first()->status)->toBe('draft');
});

it('will not send with a blocking flag', function () {
    $draft = readyDraft();
    $this->patch("/drafts/{$draft['ulid']}", ['discount_pct' => 50]);

    $this->post("/drafts/{$draft['ulid']}/zoho")->assertSessionHasErrors(['finalize' => 'Fix the blocking flags first.']);
    expect(ZohoSubmission::count())->toBe(0);
});
