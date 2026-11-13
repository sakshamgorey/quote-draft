<?php

namespace App\Services\Drafts;

use App\Ai\Agents\QuoteExtractor;
use App\Ai\FallbackPrompt;
use App\Models\Account;
use App\Models\Draft;
use App\Models\DraftLine;
use App\Models\Product;
use App\Services\Pricing\PriceCalculator;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * notes → redact → ONE QuoteExtractor call → DraftValidator → saved Draft with
 * one line per requested item. Everything after this (picking, answering,
 * editing, pricing) runs in Postgres and PHP; the LLM is never called again.
 */
class DraftBuilder
{
    /** Top-level fields the extractor returns. */
    private const EXTRACTION_KEYS = ['items', 'customer_reference', 'requested_discount', 'delivery_request', 'internal_notes', 'follow_ups'];

    /** Seconds for both AI attempts together. Under DraftController::REQUEST_TIME_LIMIT (55). */
    public const EXTRACTION_BUDGET = 50;

    public function __construct(
        private DraftValidator $validator,
        private ProductMatcher $matcher,
        private Redactor $redactor,
        private OptionSelector $options,
    ) {}

    /**
     * @throws InvalidExtraction
     * @throws AiException on provider failures
     */
    public function create(string $message, Account $account): Draft
    {
        // The AI sees only the redacted text, and every quote is checked against it.
        $redacted = $this->redactor->redact($message, $account);
        [$response, $model] = $this->extract($redacted);

        $raw = [];
        foreach (self::EXTRACTION_KEYS as $key) {
            $raw[$key] = $response[$key] ?? null;
        }

        $items = $this->validator->validate($raw['items'], $redacted);
        $notes = $this->validator->notes($raw, $redacted);

        return DB::transaction(function () use ($message, $redacted, $account, $raw, $items, $notes, $model) {
            $draft = Draft::create([
                'account_id' => $account->id,
                'message' => $message,
                'redacted_message' => $redacted,
                'model' => $model,
                'extraction' => $raw,
                'notes' => $notes,
            ]);

            foreach ($items as $i => $item) {
                $line = $draft->lines()->create([
                    'position' => $i + 1,
                    'product_type' => $item->type,
                    'quote' => $item->quote,
                    'specs' => $item->specs,
                    'rejected' => $item->rejected,
                    'option_requests' => $item->optionRequests,
                    'features' => $item->features,
                    'quantity' => $item->value('quantity') ?? 1,
                ]);

                $this->autoPick($line);
            }

            return $draft;
        });
    }

    /**
     * The one extraction call, retried once on the fallback model when the default is
     * busy, rate limited or too slow (FallbackPrompt).
     *
     * @return array{0: StructuredAgentResponse, 1: string} the response and the model that produced it
     */
    private function extract(string $notes): array
    {
        return FallbackPrompt::run(new QuoteExtractor, $notes, self::EXTRACTION_BUDGET);
    }

    /** Add a product the salesperson found themselves (search) or took from a line's suggestions. */
    public function addManualLine(Draft $draft, Product $product, int $quantity, bool $fromSuggestion = false): DraftLine
    {
        $line = $draft->lines()->create([
            'position' => (int) $draft->lines()->max('position') + 1,
            'source' => $fromSuggestion ? DraftLine::SOURCE_SUGGESTION : 'manual',
            'product_type' => $product->product_type,
            'specs' => [],
            'quantity' => $quantity,
        ]);

        $this->setProduct($line, $product->id, DraftLine::PICKED_SALESPERSON);

        return $line;
    }

    /**
     * Re-match after specs change. Keeps the salesperson's product if it still fits the size;
     * otherwise preselects the matcher's pick (ProductMatcher::pick: named in the notes, the
     * only match, or alone in confirming a stated detail). When the best matches are equals,
     * nothing is preselected: the salesperson picks from the shortlist.
     */
    public function autoPick(DraftLine $line): void
    {
        $match = $this->matcher->match($line->requestedItem());

        if ($line->product_id && $line->product_picked_by !== DraftLine::PICKED_AUTO && $match['products']->contains('id', $line->product_id)) {
            return;
        }

        $this->setProduct($line, $match['pick']['id'] ?? null, DraftLine::PICKED_AUTO);
    }

    /**
     * Put a product on the line and choose its options afresh (notes' wishes, else the
     * store's standard). Choosing the same product again only records who confirmed it.
     */
    public function setProduct(DraftLine $line, ?int $productId, string $pickedBy): void
    {
        $changed = (int) $line->product_id !== (int) $productId;
        $line->update(['product_id' => $productId, 'product_picked_by' => $productId ? $pickedBy : null]);

        if ($changed) {
            $this->options->initialize($line->refresh());
        }
    }

    /**
     * Freeze what the customer is quoted: products, list and net prices, the discount
     * and its reason, and the account details. Later imports or rule changes can't touch it.
     */
    public function finalize(Draft $draft): void
    {
        $draft->loadMissing('account');
        $prices = PriceCalculator::for($draft->account, $draft->discount_pct);

        DB::transaction(function () use ($draft, $prices) {
            foreach ($draft->lines()->with('product.options.values', 'chosenOptions')->get() as $line) {
                if (! $line->product) {
                    continue;
                }

                $price = $prices->price($line->product, $line->quantity);

                // Freeze each chosen option at the store's current price and label.
                $optionsCents = 0;
                foreach ($line->chosenOptions as $chosen) {
                    $current = $line->product->options->firstWhere('store_option_id', $chosen->store_option_id)
                        ?->values->firstWhere('store_value_id', $chosen->store_value_id);
                    if ($current) {
                        $chosen->update(['value_name' => $current->name, 'price_delta_cents' => $current->price_delta_cents, 'lead_time_text' => $current->lead_time_text]);
                    }
                    $optionsCents += $chosen->price_delta_cents ?? 0;
                }

                $line->update([
                    'options_price' => $optionsCents / 100,
                    'product_sku' => $line->product->sku,
                    'product_name' => $line->product->name,
                    'product_url' => $line->product->url,
                    'product_image_url' => $line->product->image_url,
                    'product_size' => $line->product->openingSize(),
                    'list_price' => $price ? $price['list_cents'] / 100 : null,
                    'discount_pct' => $price['discount_pct'] ?? null,
                    'discount_label' => $price['discount_label'] ?? null,
                    'unit_price' => $price ? $price['unit_cents'] / 100 : null, // net
                ]);
            }

            $draft->update([
                'status' => Draft::FINALIZED,
                'finalized_at' => now(),
                'account_snapshot' => $draft->account?->summary(),
            ]);
        });
    }
}
