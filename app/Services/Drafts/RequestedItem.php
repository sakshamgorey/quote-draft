<?php

namespace App\Services\Drafts;

/** One item the customer asked for, after validation. Only specs that passed checks are here. */
final class RequestedItem
{
    /**
     * @param  ?string  $type  a catalog product_type, or null when unclear
     * @param  array<string, array{value: mixed, quote: string}>  $specs
     * @param  list<string>  $rejected  spec names the model returned but code couldn't verify
     * @param  list<array{text: string, quote: string}>  $optionRequests  add-ons / variants asked for ("keyed lock")
     * @param  list<array{text: string, quote: string}>  $features  qualities asked for ("high security"): ranking only
     */
    public function __construct(
        public readonly ?string $type,
        public readonly ?string $quote,
        public readonly array $specs = [],
        public readonly array $rejected = [],
        public readonly array $optionRequests = [],
        public readonly array $features = [],
    ) {}

    public function value(string $spec): mixed
    {
        return $this->specs[$spec]['value'] ?? null;
    }

    public function has(string $spec): bool
    {
        return isset($this->specs[$spec]);
    }
}
