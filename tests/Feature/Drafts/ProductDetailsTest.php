<?php

it('shows the product page specs on every option and the description on the chosen product', function () {
    product(['sku' => 'WITH-PAGE'])->page()->create([
        'parser_version' => 1,
        'description' => 'A 12x12 steel access door.',
        'spec_lines' => array_map(fn ($i) => ['label' => "Spec {$i}", 'value' => "Value {$i}"], range(1, 10)),
        'spec_sheet_url' => 'https://www.bestaccessdoors.com/content/BA-TEST.pdf',
    ]);
    product(['sku' => 'NO-PAGE']);

    $draft = draftFor('12x12 access panel', [item(['size' => ['width' => 12, 'height' => 12, 'quote' => '12x12']])]);
    $line = $draft['lines'][0];
    $withPage = collect($line['options'])->firstWhere('sku', 'WITH-PAGE');

    expect($withPage['spec_lines'])->toHaveCount(8)
        ->and($withPage['spec_lines'][0])->toBe(['label' => 'Spec 1', 'value' => 'Value 1'])
        ->and($withPage['spec_sheet_url'])->toBe('https://www.bestaccessdoors.com/content/BA-TEST.pdf')
        ->and($withPage)->not->toHaveKey('description')
        ->and(collect($line['options'])->firstWhere('sku', 'NO-PAGE')['spec_lines'])->toBe([]);

    $this->patch("/drafts/{$draft['ulid']}/lines/{$line['id']}", ['product_id' => product_id('WITH-PAGE')]);

    expect(showDraft($draft['ulid'])['lines'][0]['selected']['description'])->toBe('A 12x12 steel access door.');
});
