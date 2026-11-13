<?php

/*
|--------------------------------------------------------------------------
| Catalog normalisation
|--------------------------------------------------------------------------
|
| Searchspring results carry no custom_* facet values per product, so the
| importer derives attributes from categories_hierarchy paths and the name.
| Everything here is matched case-insensitively after HTML-decoding.
|
*/

return [

    'product_types' => [
        'access_door', 'floor_door', 'roof_hatch', 'smoke_vent', 'skylight', 'ladder',
        'safety_rail', 'valve_box', 'vent_cover', 'door_louver_window_kit',
    ],

    // Values the extractor may return. They must match stored tag values exactly:
    // the matcher compares them against the text[] columns.
    'vocabulary' => [
        'application' => ['Wall', 'Ceiling', 'Floor', 'Roof', 'Attic', 'Door', 'Duct'],
        'surface_type' => ['Drywall', 'Plaster', 'Masonry', 'Concrete', 'Cement', 'Stucco', 'Tile', 'Ceramic Tile', 'Vinyl Tile', 'Acoustical Tile',
            'Carpet', 'Wood', 'Hardwood', 'Laminate', 'LVP', 'Flat Roof', 'Sloped Roof', 'Shingles', 'Slate', 'Corrugated Metal Sheets', 'Deck', 'Curb', 'Duct'],
        'material' => ['Steel', 'Aluminum', 'Stainless Steel', 'Galvanized Steel', 'Galvannealed Steel', 'Plastic', 'PVC', 'Wood'],
        'flange_type' => ['Exposed Flange', 'Hidden Flange', 'Drywall Flange', 'Plaster Flange', 'Anchor Flange', 'Bottom Flange', 'Bolt-In Flange', 'No Flange'],
    ],

    // Spelling fixes applied to every tag value before it's stored.
    'aliases' => [
        'Aeshetic Appeal' => 'Aesthetic Appeal',
        'Corrosion-resistant' => 'Corrosion-Resistant',
        '300 psf Load' => '300 PSF',
        '300 PSF Load' => '300 PSF',
        'Slope Roof' => 'Sloped Roof',
        'Fire Rated' => 'Fire-Rated',
        'Pine' => 'Wood',
        'Triple Reinforced Cement' => 'Triple Fiber Reinforced Cement',
        // Facet spellings (custom_surface_type / custom_flange_type / custom_purpose).
        'Plain-Tile' => 'Tile',
        'Slates' => 'Slate',
        'Slate Tiles' => 'Slate',
        'Flange Behind Drywall' => 'Drywall Flange',
        'Large Openings' => 'Large Opening',
    ],

    // "…>Application>X" values. Each maps to tags in one or more columns.
    'application_map' => [
        'Floor' => ['application' => ['Floor']],
        'Roof' => ['application' => ['Roof']],
        'Wall' => ['application' => ['Wall']],
        'Attic' => ['application' => ['Attic']],
        'Duct' => ['application' => ['Duct']],
        'Ceiling & Attic' => ['application' => ['Ceiling', 'Attic']],
        'Wall & Ceiling' => ['application' => ['Wall', 'Ceiling']],
        'Drywall' => ['surface_type' => ['Drywall']],
        'Plaster' => ['surface_type' => ['Plaster']],
        'Stucco' => ['surface_type' => ['Stucco']],
        'Flat Roof' => ['application' => ['Roof'], 'surface_type' => ['Flat Roof']],
        'Deck Mounted' => ['application' => ['Roof'], 'purpose' => ['Deck Mounted']],
        'Curb-Mounted' => ['application' => ['Roof'], 'purpose' => ['Curb-Mounted']],
        'Daylighting' => ['application' => ['Roof'], 'purpose' => ['Daylighting']],
        'Vision Lites' => ['application' => ['Door']],
        'Air Louvers' => ['application' => ['Door']],
        'Exterior' => ['purpose' => ['Exterior']],
        'General Purpose' => ['purpose' => ['General Purpose']],
        'Security' => ['purpose' => ['Security']],
        'Acoustical' => ['purpose' => ['Acoustical']],
        'Acoustical System' => ['purpose' => ['Acoustical']],
        'Recessed Installation' => ['purpose' => ['Recessed']],
        'Surface-mounted' => ['purpose' => ['Surface-Mounted']],
    ],

    // Surface words looked for in names and category paths.
    'surface_keywords' => [
        'Drywall', 'Plaster', 'Masonry', 'Concrete', 'Cement', 'Stucco', 'Wood', 'Tile', 'Carpet',
        'Acoustical Tile', 'Flat Roof', 'Sloped Roof', 'Metal Roof',
    ],

    // Never a surface: dropped from surface_type.
    'drop_surface' => ['NA', 'Retrofit', 'Install for Roof Hatch', 'Install For Attic Ladder', 'Install for Skylight'],

    // Name / path phrase => flange_type tag. First match per phrase; a product can have several.
    'flange_keywords' => [
        'Exposed Flange' => 'Exposed Flange',
        'Hidden Flange' => 'Hidden Flange',
        'Drywall Bead Flange' => 'Drywall Flange',
        'Drywall Flange' => 'Drywall Flange',
        'Mud in Flange' => 'Drywall Flange',
        'Behind Drywall Flange' => 'Drywall Flange',
        'Tapable Flange' => 'Drywall Flange',
        'Tapeable Flange' => 'Drywall Flange',
        'Plaster Flange' => 'Plaster Flange',
        'Plaster Bead' => 'Plaster Flange',
        'Anchor Flange' => 'Anchor Flange',
        'Bottom Flange' => 'Bottom Flange',
        'Bolt-In Flange' => 'Bolt-In Flange',
        'Cast-In Flange' => 'Cast-In Flange',
        'No Flange' => 'No Flange',
        'Flangeless' => 'No Flange',
    ],

];
