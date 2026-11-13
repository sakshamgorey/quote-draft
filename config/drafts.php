<?php

return [

    /*
    | A second, optional AI call per draft: per line, it picks catalog variants and add-ons
    | worth suggesting ("the notes want high security: BA-PFI-HS"), reading SKU codes with
    | the SKU dictionary, and breaks ties between equally good products. It runs only when
    | the salesperson clicks "Ask AI" on a draft (the catalog's own suggestions show without
    | it). It only chooses among real catalog candidates that code gives it; nothing is added
    | to the quote until the salesperson clicks.
    */
    'ai_suggestions' => [
        'enabled' => (bool) env('DRAFT_AI_SUGGESTIONS', false),
        // Null: the extraction model (ai.providers.gemini.models.text.default), with its fallback.
        'model' => env('DRAFT_AI_SUGGESTIONS_MODEL'),
        'per_line' => 3,
        // Real products offered to the AI per line: same-family variants, best matches, add-ons.
        'candidates_per_line' => 30,
        // The full dictionary is private; a clone falls back to the bundled sample excerpt.
        'dictionary' => base_path(env('SKU_DICTIONARY_PATH', is_file(base_path('SKU-ACRONYM-DICTIONARY.md')) ? 'SKU-ACRONYM-DICTIONARY.md' : 'SKU-ACRONYM-DICTIONARY.sample.md')),
    ],

];
