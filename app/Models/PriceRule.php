<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PriceRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'discount_pct' => 'float',
            'min_quantity' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'active' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Active, in date, and either global or for this account. */
    public function scopeApplicableTo(Builder $query, ?Account $account, ?Carbon $on = null): Builder
    {
        $on ??= now();

        return $query->where('active', true)
            ->where(fn ($q) => $q->whereNull('account_id')->when($account, fn ($q) => $q->orWhere('account_id', $account->id)))
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $on))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $on));
    }

    public function matches(Product $product, int $quantity): bool
    {
        return $quantity >= $this->min_quantity
            && ($this->product_type === null || $this->product_type === $product->product_type)
            && ($this->brand === null || $this->brand === $product->brand);
    }
}
