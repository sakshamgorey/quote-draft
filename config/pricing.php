<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pricing guardrails
    |--------------------------------------------------------------------------
    |
    | Prices always come from the catalog and these rules, never from the AI.
    | Each line gets the single best applicable discount (contract, price rule
    | or quote discount); discounts never stack.
    |
    */

    // Highest discount a quote may carry when the account sets no max of its own.
    'max_discount_pct' => (float) env('PRICING_MAX_DISCOUNT_PCT', 15),

    // Flag lines whose catalog price is older than this.
    'stale_after_days' => (int) env('PRICING_STALE_AFTER_DAYS', 7),

    // "Double-check" flags.
    'large_quantity' => (int) env('PRICING_LARGE_QUANTITY', 100),
    'large_total_cents' => (int) env('PRICING_LARGE_TOTAL_CENTS', 2_500_000), // $25,000

];
