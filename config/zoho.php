<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Zoho CRM quote hand-off
    |--------------------------------------------------------------------------
    |
    | "dummy" builds the exact request (Zoho CRM API v8, Insert Records into the
    | Quotes module) and records it with a Zoho-shaped response, without any
    | network call. There is no live mode yet: it needs OAuth, and Zoho record
    | IDs for accounts and products (looked up by name / Product_Code).
    |
    | Request shape from https://www.zoho.com/crm/developer/docs/api/v8/insert-records.html
    |
    */

    'mode' => env('ZOHO_MODE', 'dummy'),

    'api_domain' => env('ZOHO_API_DOMAIN', 'https://www.zohoapis.com'),

    'version' => 'v8',

    'quote_stage' => env('ZOHO_QUOTE_STAGE', 'Draft'),

    'quote_valid_days' => (int) env('ZOHO_QUOTE_VALID_DAYS', 30),

    // Fields in the body not shown in Zoho's official Quotes sample. Confirm against a
    // real org with GET {api_domain}/crm/v8/settings/fields?module=Quotes before going live.
    'unverified_fields' => ['Account_Name', 'Quote_Stage', 'Valid_Till', 'Terms_and_Conditions', 'Description', 'Quoted_Items[].List_Price', 'Quoted_Items[].Discount (amount)'],

];
