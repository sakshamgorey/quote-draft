<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product pages on bestaccessdoors.com
    |--------------------------------------------------------------------------
    |
    | catalog:fetch-pages saves each product's page (URL from the Searchspring
    | import) and catalog:parse-pages reads the saved files into product_pages,
    | product_options(+values) and product_links. Fetching obeys robots.txt:
    | disallowed paths are skipped and a Crawl-delay for our agent is honoured.
    |
    */

    'storage_path' => storage_path('app/product-pages'),

    'user_agent' => env('PRODUCT_PAGES_USER_AGENT', 'BestQuoteDraft/0.1 (catalog import)'),

    // Minimum pause between page requests; robots.txt Crawl-delay wins if larger.
    'sleep_ms' => (int) env('PRODUCT_PAGES_SLEEP_MS', 1500),

    'timeout' => (int) env('PRODUCT_PAGES_TIMEOUT', 30),

    // Bump when the parser changes meaningfully, so stale parses are easy to find.
    'parser_version' => 2, // 2: option choice pictures

];
