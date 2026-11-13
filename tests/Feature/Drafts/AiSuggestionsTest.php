<?php

use App\Ai\Agents\SuggestionAdvisor;
use App\Models\Draft;
use App\Services\Drafts\SkuDictionary;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Prompts\AgentPrompt;

/** A Cendrex PFI door at 24x24, its high-security variant, another size, and a rail-less hatch world. */
function pfiCatalog(): void
{
    product(['sku' => 'BA-PFI-24-24', 'model' => 'BA-PFI', 'brand' => 'Cendrex', 'nominal_width' => 24, 'nominal_height' => 24, 'popularity' => 9]);
    product(['sku' => 'BA-PFI-HS-24-24', 'model' => 'BA-PFI-HS', 'brand' => 'Cendrex', 'nominal_width' => 24, 'nominal_height' => 24, 'popularity' => 1]);
    product(['sku' => 'BA-PFI-HS-12-12', 'model' => 'BA-PFI-HS', 'brand' => 'Cendrex', 'nominal_width' => 12, 'nominal_height' => 12]);
}

function pfiDraft(): array
{
    return draftFor('2x Cendrex BA-PFI 24x24, high security', [item([
        'quote' => 'BA-PFI 24x24', 'size' => ['width' => 24, 'height' => 24, 'quote' => '24x24'],
        'model' => ['value' => 'BA-PFI', 'quote' => 'BA-PFI'],
        'features' => [['text' => 'high security', 'quote' => 'high security']],
    ])]);
}

it('is off unless DRAFT_AI_SUGGESTIONS is on', function () {
    pfiCatalog();
    $draft = pfiDraft();

    expect($draft['ai_suggestions']['enabled'])->toBeFalse();
    $this->post("/drafts/{$draft['ulid']}/suggestions")->assertNotFound();
    SuggestionAdvisor::assertNeverPrompted();
});

it('keeps only candidates it offered, tied to the chosen product, and shows them after the catalog ones', function () {
    config(['drafts.ai_suggestions.enabled' => true]);
    pfiCatalog();
    $draft = pfiDraft();
    expect($draft['lines'][0]['selected']['sku'])->toBe('BA-PFI-24-24');

    SuggestionAdvisor::fake([['suggestions' => [
        ['line' => 1, 'sku' => 'BA-PFI-HS-24-24', 'kind' => 'variant', 'reason' => 'HS = high security in the Cendrex PFI family.', 'quote' => 'high security'],
        ['line' => 1, 'sku' => 'BA-PFI-HS-12-12', 'kind' => 'variant', 'reason' => 'Wrong size: never offered.', 'quote' => null],
        ['line' => 1, 'sku' => 'BA-INVENTED-1', 'kind' => 'add_on', 'reason' => 'Made up.', 'quote' => null],
        ['line' => 7, 'sku' => 'BA-PFI-HS-24-24', 'kind' => 'variant', 'reason' => 'No such line.', 'quote' => null],
    ]]]);

    $this->post("/drafts/{$draft['ulid']}/suggestions")->assertRedirect();

    SuggestionAdvisor::assertPrompted(fn (AgentPrompt $p) => str_contains($p->prompt, '1. variant | BA-PFI-HS-24-24 | Cendrex')
        && str_contains($p->prompt, '- HS [SCOPED_MEANING]: High security')
        && str_contains($p->prompt, 'Find the exact SKU first') // the dictionary's rules
        && ! str_contains($p->prompt, 'BA-PFI-HS-12-12'));

    $shown = showDraft($draft['ulid']);
    $line = $shown['lines'][0];

    expect($shown['ai_suggestions'])->toMatchArray(['enabled' => true, 'status' => 'done', 'error' => null])
        ->and($line['suggestions'])->toHaveCount(1)
        ->and($line['suggestions'][0])->toMatchArray(['sku' => 'BA-PFI-HS-24-24', 'source' => 'ai', 'kind' => 'variant', 'quote' => 'high security']);

    // Switching to the variant: the suggestions were for the old product, so they go.
    $this->patch("/drafts/{$draft['ulid']}/lines/{$line['id']}", ['product_id' => product_id('BA-PFI-HS-24-24')]);

    expect(showDraft($draft['ulid'])['lines'][0]['suggestions'])->toBe([]);
});

it('saves a friendly error when the AI is busy on both models', function () {
    config(['drafts.ai_suggestions.enabled' => true]);
    pfiCatalog();
    $draft = pfiDraft();

    SuggestionAdvisor::fake(fn () => throw ProviderOverloadedException::forProvider('gemini', 503));

    $this->post("/drafts/{$draft['ulid']}/suggestions")->assertRedirect();

    expect(Draft::first()->ai_suggestions_status)->toBe('failed')
        ->and(showDraft($draft['ulid'])['ai_suggestions']['error'])->toBe('The AI service is busy right now. Try again in a minute.');
});

it('reads code meanings, scopes and rules from the SKU dictionary', function () {
    $dictionary = new SkuDictionary(base_path('SKU-ACRONYM-DICTIONARY.sample.md'));
    $found = $dictionary->for(['BA-PFI-HS-24-24', 'BA-S-50TB-36-30']);

    expect($found['codes'])->toHaveKeys(['PFI', 'HS'])
        ->and($found['codes'])->not->toHaveKey('BA')
        ->and($found['codes']['HS'])->toMatchArray(['class' => 'SCOPED_MEANING'])
        ->and($found['codes']['HS']['meaning'])->toContain('High security')
        ->and(collect($found['embedded'])->pluck('codes')->flatten()->all())->toContain('50TB')
        ->and($dictionary->rules())->toStartWith('1. Find the exact SKU first');
});
