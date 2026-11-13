<?php

it('keeps a non-rated SKU for clarification and blocks finalize and send with or without an extracted size', function (bool $withSize) {
    product(['sku' => 'BA-AHD-12-12', 'model' => 'BA-AHD', 'price' => 100]);
    product(['sku' => 'BA-PFI-12-12', 'model' => 'BA-PFI', 'fire_rated' => true]);
    $notes = 'Need 2 fire-rated access panels, SKU BA-AHD-12-12';

    $draft = draftFor($notes, [item([
        'quote' => $notes,
        'quantity' => ['value' => 2, 'quote' => '2 fire-rated access panels'],
        'model' => ['value' => 'BA-AHD-12-12', 'quote' => 'BA-AHD-12-12'],
        'fire_rated' => ['value' => true, 'quote' => 'fire-rated'],
        'size' => $withSize ? ['width' => 12, 'height' => 12, 'quote' => '12-12'] : null,
    ])]);

    expect($draft['lines'][0]['status'])->toBe('needs_clarification');
    expect($draft['lines'][0]['selected']['sku'])->toBe('BA-AHD-12-12');
    expect($draft['lines'][0]['questions'][0])->toContain('Fire-rated Yes', 'Non-fire-rated');
    expect(implode(' ', $draft['lines'][0]['questions']))->not->toContain("isn't listed in this size");
    expect(array_column($draft['lines'][0]['options'], 'sku'))->toContain('BA-PFI-12-12');
    expect($draft['totals']['subtotal_cents'])->toBe(20000);
    expect($draft['can_finalize'])->toBeFalse();
    expect($draft['can_send'])->toBeFalse();
    expect(collect($draft['flags'])->firstWhere('code', 'product_conflict'))
        ->toMatchArray(['severity' => 'blocking', 'quote' => 'fire-rated']);

    $this->post("/drafts/{$draft['ulid']}/finalize")->assertSessionHasErrors(['finalize']);
    $this->post("/drafts/{$draft['ulid']}/zoho")->assertSessionHasErrors(['finalize']);

    $this->assertDatabaseHas('drafts', ['ulid' => $draft['ulid'], 'status' => 'draft']);
    $this->assertDatabaseCount('zoho_submissions', 0);
})->with([true, false]);

it('keeps the exact SKU visible when its type or opening conflicts with the request', function (array $overrides, string $spec, string $evidence) {
    product(['sku' => 'BA-AHD-12-12', 'model' => 'BA-AHD']);
    $notes = 'Need BA-AHD-12-12 as a 24x24 roof hatch';

    $draft = draftFor($notes, [item(array_merge([
        'quote' => $notes,
        'model' => ['value' => 'BA-AHD-12-12', 'quote' => 'BA-AHD-12-12'],
        'size' => ['width' => 12, 'height' => 12, 'quote' => '12-12'],
    ], $overrides))]);

    expect($draft['lines'][0]['status'])->toBe('needs_clarification');
    expect($draft['lines'][0]['selected']['sku'])->toBe('BA-AHD-12-12');
    expect(collect($draft['lines'][0]['selected']['checks'])->firstWhere('spec', $spec))
        ->toMatchArray(['verdict' => 'no', 'evidence' => $evidence]);
})->with([
    'type' => [['product_type' => 'roof_hatch', 'size' => null], 'product_type', 'Access door'],
    'opening' => [['size' => ['width' => 24, 'height' => 24, 'quote' => '24x24']], 'size', '12" x 12"'],
]);

it('does not waive a conflict when the salesperson confirms the same product', function () {
    $product = product(['sku' => 'BA-AHD-12-12', 'price' => 100, 'surface_type' => ['Drywall']]);
    $draft = draftFor('BA-AHD-12-12 for tile', [item([
        'quote' => 'BA-AHD-12-12 for tile',
        'model' => ['value' => 'BA-AHD-12-12', 'quote' => 'BA-AHD-12-12'],
        'surface_type' => ['value' => 'Tile', 'quote' => 'tile'],
    ])]);
    $line = $draft['lines'][0];

    $this->patch("/drafts/{$draft['ulid']}/lines/{$line['id']}", ['product_id' => $product->id])->assertRedirect();

    expect(showDraft($draft['ulid'])['can_finalize'])->toBeFalse();
    $this->assertDatabaseHas('draft_lines', ['id' => $line['id'], 'product_picked_by' => 'salesperson']);

    $this->patch("/drafts/{$draft['ulid']}/lines/{$line['id']}", ['clear_spec' => 'surface_type'])->assertRedirect();

    expect(showDraft($draft['ulid'])['lines'][0]['status'])->toBe('ready');
    expect(showDraft($draft['ulid'])['can_finalize'])->toBeTrue();
    $this->assertDatabaseHas('drafts', ['ulid' => $draft['ulid'], 'message' => 'BA-AHD-12-12 for tile']);
});

it('checks a manually chosen product outside the matching candidates', function () {
    product(['sku' => 'RIGHT', 'fire_rated' => true]);
    $wrong = product(['sku' => 'WRONG', 'product_type' => 'roof_hatch', 'nominal_width' => 30, 'nominal_height' => 36]);
    $draft = draftFor('12x12 fire-rated panel', [item([
        'quote' => '12x12 fire-rated panel',
        'size' => ['width' => 12, 'height' => 12, 'quote' => '12x12'],
        'fire_rated' => ['value' => true, 'quote' => 'fire-rated'],
    ])]);
    $line = $draft['lines'][0];

    $this->patch("/drafts/{$draft['ulid']}/lines/{$line['id']}", ['product_id' => $wrong->id])->assertRedirect();

    $after = showDraft($draft['ulid']);
    expect($after['lines'][0]['selected'])->toMatchArray(['sku' => 'WRONG', 'group' => 'also']);
    expect($after['lines'][0]['status'])->toBe('needs_clarification');
    expect(collect($after['flags'])->where('code', 'product_conflict'))->toHaveCount(3);
    expect($after['can_finalize'])->toBeFalse();
});

it('does not turn missing catalog evidence into a contradiction', function () {
    product(['sku' => 'BA-AHD-12-12', 'fire_rated' => null, 'surface_type' => []]);

    $draft = draftFor('BA-AHD-12-12 fire-rated for tile', [item([
        'quote' => 'BA-AHD-12-12 fire-rated for tile',
        'model' => ['value' => 'BA-AHD-12-12', 'quote' => 'BA-AHD-12-12'],
        'fire_rated' => ['value' => true, 'quote' => 'fire-rated'],
        'surface_type' => ['value' => 'Tile', 'quote' => 'tile'],
    ])]);

    expect(collect($draft['lines'][0]['selected']['checks'])->where('verdict', 'unknown'))->toHaveCount(2);
    expect(collect($draft['flags'])->where('code', 'product_conflict'))->toBeEmpty();
});

it('does not blame the size when a model is excluded by fire rating', function () {
    product(['sku' => 'BA-AHD-12-12', 'model' => 'BA-AHD']);
    product(['sku' => 'BA-PFI-12-12', 'model' => 'BA-PFI', 'fire_rated' => true]);

    $draft = draftFor('12x12 fire-rated BA-AHD panel', [item([
        'quote' => '12x12 fire-rated BA-AHD panel',
        'size' => ['width' => 12, 'height' => 12, 'quote' => '12x12'],
        'model' => ['value' => 'BA-AHD', 'quote' => 'BA-AHD'],
        'fire_rated' => ['value' => true, 'quote' => 'fire-rated'],
    ])]);

    expect(implode(' ', $draft['lines'][0]['questions']))->not->toContain("isn't listed in this size");
});

it('does not block a requested SKU because its page omits insulation', function (?bool $tagged, string $expected) {
    product(['sku' => 'BA-AHD-12-12', 'insulated' => $tagged])->page()->create([
        'parser_version' => 1, 'insulated' => false, 'insulated_basis' => 'not_mentioned',
    ]);

    $draft = draftFor('BA-AHD-12-12 insulated', [item([
        'quote' => 'BA-AHD-12-12 insulated',
        'model' => ['value' => 'BA-AHD-12-12', 'quote' => 'BA-AHD-12-12'],
        'insulated' => ['value' => true, 'quote' => 'insulated'],
    ])]);

    expect(collect($draft['lines'][0]['selected']['checks'])->firstWhere('spec', 'insulated')['verdict'])->toBe($expected);
    expect(collect($draft['flags'])->where('code', 'product_conflict'))->toBeEmpty();
})->with([
    'unverified' => [null, 'unknown'],
    'confirmed by catalog tags' => [true, 'yes'],
]);
