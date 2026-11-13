<?php

use App\Ai\Agents\QuoteExtractor;
use App\Models\Draft;
use App\Services\Drafts\Redactor;

it('masks the account\'s contacts, any email and any phone, but not sizes or SKUs', function () {
    $account = account(['contacts' => [
        ['name' => 'Priya Shah', 'email' => 'priya@shah.example', 'phone' => '555-201-3344'],
        ['name' => 'Tom Reyes', 'email' => null, 'phone' => null],
    ]]);

    $notes = "Call w/ Priya Shah 10/22, then priya again. Tom Reyes cc'd.\n"
        ."priya@shah.example / other@site.example, cell (555) 201-3344 or 555.777.1212\n"
        .'6x 24x24 FR panels, like BA-AHD-12-12, qty 1000';

    expect((new Redactor)->redact($notes, $account))->toBe(
        "Call w/ [CONTACT_1] 10/22, then [CONTACT_1] again. [CONTACT_2] cc'd.\n"
        ."[EMAIL] / [EMAIL], cell [PHONE] or [PHONE]\n"
        .'6x 24x24 FR panels, like BA-AHD-12-12, qty 1000'
    );
});

it('sends only the redacted notes to the AI and keeps the original for the salesperson', function () {
    product(['sku' => 'BA-AHD-12-12', 'price' => 50]);
    QuoteExtractor::fake([['items' => []]]);

    $this->post('/drafts', ['message' => 'Priya wants a 12x12 panel, call 555-201-3344', 'account_id' => account()->id]);

    QuoteExtractor::assertPrompted(fn ($prompt) => $prompt->prompt === '[CONTACT_1] wants a 12x12 panel, call [PHONE]');
    expect(Draft::first())
        ->message->toBe('Priya wants a 12x12 panel, call 555-201-3344')
        ->redacted_message->toBe('[CONTACT_1] wants a 12x12 panel, call [PHONE]');
});
