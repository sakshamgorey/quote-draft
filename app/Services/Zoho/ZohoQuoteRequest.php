<?php

namespace App\Services\Zoho;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Builds the exact Zoho CRM request for a draft: Insert Records into Quotes (API v8).
 *
 * Built only from trusted data: the presented draft's chosen products, computed
 * prices and account. Internal notes, follow-ups, delivery requests and the raw
 * notes never go in. Zoho needs its own record IDs for lookups; until accounts and
 * products are mapped, the IDs are explicit placeholders a live sender would
 * resolve first (account by name, product by Product_Code = our SKU).
 */
class ZohoQuoteRequest
{
    /**
     * @param  array  $draft  DraftPresenter::show() without the zoho key
     * @return array{method: string, url: string, headers: array<string, string>, body: array}
     */
    public function build(array $draft): array
    {
        $account = $draft['account'];
        $created = Carbon::parse($draft['created_at']);

        $quote = array_filter([
            'Subject' => Str::limit(($account['name'] ?? 'No account').' – quote draft '.$created->format('Y-m-d').' ('.Str::upper(Str::substr($draft['ulid'], -6)).')', 255, ''),
            'Account_Name' => $account ? ['id' => 'ZOHO_ID_FOR_ACCOUNT:'.$account['name']] : null,
            'Quote_Stage' => config('zoho.quote_stage'),
            'Valid_Till' => $created->copy()->addDays(config('zoho.quote_valid_days'))->format('Y-m-d'),
            'Terms_and_Conditions' => ($account['payment_terms'] ?? null) ? "Payment terms: {$account['payment_terms']}" : null,
            'Description' => "Drafted from salesperson notes in BEST Quote Draft (draft {$draft['ulid']}). Prices as of {$draft['prices_as_of']}."
                .(($account['tax_exempt'] ?? false) ? ' Customer is tax exempt.' : ''),
            'Quoted_Items' => array_values(array_map(fn ($line) => $this->item($line), array_filter($draft['lines'], fn ($l) => $l['selected'] !== null))),
        ], fn ($v) => $v !== null);

        return [
            'method' => 'POST',
            'url' => rtrim(config('zoho.api_domain'), '/').'/crm/'.config('zoho.version').'/Quotes',
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ••••••••', // never stored or shown
                'Content-Type' => 'application/json',
            ],
            // trigger: [] = don't fire Zoho workflows/approvals/blueprints on insert.
            'body' => ['data' => [$quote], 'trigger' => []],
        ];
    }

    private function item(array $line): array
    {
        $s = $line['selected'];
        $money = fn (?int $cents) => $cents === null ? null : round($cents / 100, 2);

        // Zoho line items have no option field: each chosen option is itemized in the description
        // and its price is folded into List_Price.
        $options = array_map(
            fn ($g) => "{$g['label']}: {$g['selected']['name']}".($g['selected']['price_delta_cents'] ? ' (+$'.number_format($g['selected']['price_delta_cents'] / 100, 2).')' : ''),
            array_filter($line['option_groups'] ?? [], fn ($g) => $g['selected'] !== null),
        );
        $optionsCents = $line['options_price_cents'] ?? 0;

        $description = collect([
            "SKU {$s['sku']}",
            $s['size'] ? "opening {$s['size']}" : null,
            ...$options,
            $line['discount_pct'] > 0 ? rtrim(rtrim(number_format($line['discount_pct'], 2), '0'), '.')."% {$line['discount_label']} (base product)" : null,
        ])->filter()->implode('; ');

        return array_filter([
            'Product_Name' => ['id' => 'ZOHO_ID_FOR_SKU:'.$s['sku'], 'name' => $s['name']],
            'Quantity' => $line['quantity'],
            // Price on request: no list price; Zoho falls back to the product's own price.
            'List_Price' => $line['list_price_cents'] !== null ? $money($line['list_price_cents'] + $optionsCents) : null,
            // Discount on the base product only, as an amount: (list − net base) × quantity.
            'Discount' => $line['list_price_cents'] !== null && $line['base_price_cents'] !== null
                ? $money(($line['list_price_cents'] - $line['base_price_cents']) * $line['quantity'])
                : null,
            'Description' => $description,
        ], fn ($v) => $v !== null);
    }
}
