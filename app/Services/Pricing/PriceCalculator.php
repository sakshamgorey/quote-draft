<?php

namespace App\Services\Pricing;

use App\Models\Account;
use App\Models\PriceRule;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Line prices from trusted data only: catalog list price, the account's
 * contract discount, active price rules and the quote discount the
 * salesperson set. The single best discount wins; discounts never stack,
 * so every price has one reason the reviewer can read.
 *
 * Money is integer cents throughout.
 */
class PriceCalculator
{
    /** @var Collection<int, PriceRule> */
    private Collection $rules;

    public function __construct(
        private ?Account $account = null,
        private ?float $quoteDiscountPct = null,
    ) {
        $this->rules = PriceRule::query()->applicableTo($account)->get();
    }

    public static function for(?Account $account, ?float $quoteDiscountPct): self
    {
        return new self($account, $quoteDiscountPct);
    }

    /**
     * Null when the product has no catalog price (price on request).
     *
     * @return ?array{list_cents: int, discount_pct: float, discount_label: ?string, unit_cents: int, total_cents: int}
     */
    public function price(Product $product, int $quantity): ?array
    {
        if ($product->price === null) {
            return null;
        }

        $list = (int) round((float) $product->price * 100);
        [$pct, $label] = $this->bestDiscount($product, $quantity);
        $unit = (int) round($list * (1 - $pct / 100));

        return [
            'list_cents' => $list,
            'discount_pct' => $pct,
            'discount_label' => $label,
            'unit_cents' => $unit,
            'total_cents' => $unit * $quantity,
        ];
    }

    /** @return array{0: float, 1: ?string} */
    private function bestDiscount(Product $product, int $quantity): array
    {
        $candidates = [];

        if ($this->account && $this->account->discount_pct > 0) {
            $candidates[] = [$this->account->discount_pct, 'Contract discount'];
        }

        foreach ($this->rules as $rule) {
            if ($rule->matches($product, $quantity)) {
                $candidates[] = [$rule->discount_pct, $rule->label];
            }
        }

        if ($this->quoteDiscountPct > 0) {
            $candidates[] = [$this->quoteDiscountPct, 'Quote discount'];
        }

        // Highest wins; on a tie the earlier (contract, then rule) keeps its clearer label.
        $best = [0.0, null];
        foreach ($candidates as [$pct, $label]) {
            if ($pct > $best[0]) {
                $best = [(float) $pct, $label];
            }
        }

        return $best;
    }
}
