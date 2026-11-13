<?php

use App\Ai\Agents\QuoteExtractor;
use App\Models\Draft;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    config([
        'ai.providers.gemini.models.text.default' => 'primary-model',
        'ai.providers.gemini.models.text.fallback' => 'fallback-model',
    ]);
});

/** Fake that fails on the default model with $error and answers on any other. */
function failPrimaryWith(Throwable $error): void
{
    QuoteExtractor::fake(fn (string $prompt, $attachments, $provider, string $model) => $model === 'primary-model'
        ? throw $error
        : ['items' => []]);
}

it('retries once on the fallback model when the default is busy, and records which model read the notes', function () {
    failPrimaryWith(ProviderOverloadedException::forProvider('gemini', 503));

    $this->post('/drafts', ['message' => 'need a 12x12 access panel', 'account_id' => account()->id])->assertRedirect();

    QuoteExtractor::assertPromptedTimes(2);
    QuoteExtractor::assertPrompted(fn (AgentPrompt $p) => $p->model === 'primary-model');
    QuoteExtractor::assertPrompted(fn (AgentPrompt $p) => $p->model === 'fallback-model');
    expect(Draft::first()->model)->toBe('fallback-model');
});

it('caps the first attempt so a retry after a timeout still fits the request budget', function () {
    failPrimaryWith(ProviderConnectionException::forProvider('gemini'));

    $this->post('/drafts', ['message' => 'need a 12x12 access panel', 'account_id' => account()->id])->assertRedirect();

    QuoteExtractor::assertPrompted(fn (AgentPrompt $p) => $p->model === 'primary-model' && $p->timeout === 25);
    QuoteExtractor::assertPrompted(fn (AgentPrompt $p) => $p->model === 'fallback-model' && $p->timeout <= 50 && $p->timeout >= 8);
});

it('gives up after one retry with a friendly message', function () {
    QuoteExtractor::fake(fn () => throw ProviderOverloadedException::forProvider('gemini', 503));

    $this->from('/')->post('/drafts', ['message' => 'need a 12x12 access panel', 'account_id' => account()->id])
        ->assertSessionHasErrors(['message' => 'The AI service is busy right now. Please try again in a minute.']);

    QuoteExtractor::assertPromptedTimes(2);
    expect(Draft::count())->toBe(0);
});

it('uses the whole budget on the default model when no fallback is set', function () {
    config(['ai.providers.gemini.models.text.fallback' => null]);
    QuoteExtractor::fake([['items' => []]]);

    $this->post('/drafts', ['message' => 'need a 12x12 access panel', 'account_id' => account()->id])->assertRedirect();

    QuoteExtractor::assertPromptedTimes(1);
    QuoteExtractor::assertPrompted(fn (AgentPrompt $p) => $p->timeout === 50);
});
