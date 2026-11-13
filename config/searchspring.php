<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Searchspring (bestaccessdoors.com site search)
    |--------------------------------------------------------------------------
    |
    | One endpoint, no tracking params (userId, sessionId, pageLoadId, ...).
    | The API caps resultsPerPage at 100, whatever we ask for.
    |
    */

    'base_url' => env('SEARCHSPRING_BASE_URL', 'https://oj9wwz.a.searchspring.io'),

    'site_id' => env('SEARCHSPRING_SITE_ID', 'oj9wwz'),

    'user_agent' => env('SEARCHSPRING_USER_AGENT', 'BestQuoteDraft/0.1 (catalog import)'),

    'timeout' => (int) env('SEARCHSPRING_TIMEOUT', 30),

    'per_page' => 100,

    // Pause before every network request after the first one in a run.
    'sleep_ms' => (int) env('SEARCHSPRING_SLEEP_MS', 1500),

    // Per category. Access Doors (~25 pages) and Roof & Floor Hatches (~7) get cut here.
    'max_pages' => (int) env('SEARCHSPRING_MAX_PAGES', 5),

    // Raw responses live here; imports read these files and only fetch missing pages.
    'storage_path' => storage_path('app/searchspring'),

    // Facet fields learned per product by filtering on each value (FacetStore).
    // Values come from the whole-catalog facets in {storage_path}/page-1.json.
    // Field => products column the values feed.
    'facet_fields' => [
        'custom_surface_type' => 'surface_type',
        'custom_flange_type' => 'flange_type',
        'custom_purpose' => 'purpose',
        'custom_fire_rated' => 'fire_rated',
        'custom_type_of_access' => 'type_of_access',
    ],

    // Top-level categories fetched one by one (bgfilter.categories_hierarchy).
    // "Popular Picks" is skipped: it re-lists products from the others.
    'categories' => [
        'access-doors' => 'Products>Access Doors & Panels',
        'roof-floor-hatches' => 'Products>Roof & Floor Hatches',
        'smoke-vents' => 'Products>Smoke Vents',
        'skylights' => 'Products>Skylights',
        'door-louver-window-kits' => 'Products>Door Louver & Window Kits',
        'ladders' => 'Products>Ladders',
        'valve-boxes' => 'Products>Valve Boxes',
        'vent-covers' => 'Products>Vent Covers',
        'safety-rails' => 'Products>Safety Rails',
    ],

];
