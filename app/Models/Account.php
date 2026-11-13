<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class Account extends Model
{
    use Searchable;

    protected $guarded = ['id'];

    protected $hidden = ['search_vector'];

    protected function casts(): array
    {
        return [
            'contacts' => 'array',
            'tax_exempt' => 'boolean',
            'discount_pct' => 'float',
            'max_discount_pct' => 'float',
        ];
    }

    /** Keys are column names for the pgsql engine; they match the migration's searchable(). */
    public function toSearchableArray(): array
    {
        return ['name' => $this->name];
    }

    public function priceRules(): HasMany
    {
        return $this->hasMany(PriceRule::class);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(Draft::class);
    }

    /** The most a quote for this account may be discounted. */
    public function maxDiscountPct(): float
    {
        return $this->max_discount_pct ?? (float) config('pricing.max_discount_pct');
    }

    /** What the draft page and finalize snapshot show about the customer. */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contacts' => $this->contacts ?? [],
            'ship_to' => $this->ship_to,
            'payment_terms' => $this->payment_terms,
            'tax_exempt' => $this->tax_exempt,
            'discount_pct' => $this->discount_pct,
            'max_discount_pct' => $this->maxDiscountPct(),
        ];
    }
}
