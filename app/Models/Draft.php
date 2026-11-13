<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Draft extends Model
{
    use HasUlids;

    public const DRAFT = 'draft';

    public const FINALIZED = 'finalized';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'extraction' => 'array',
            'notes' => 'array',
            'account_snapshot' => 'array',
            'discount_pct' => 'float',
            'finalized_at' => 'datetime',
            'ai_suggested_at' => 'datetime',
        ];
    }

    public function zohoSubmission(): HasOne
    {
        return $this->hasOne(ZohoSubmission::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Highest discount this quote may carry: the account's max, or the company default. */
    public function maxDiscountPct(): float
    {
        return $this->account?->maxDiscountPct() ?? (float) config('pricing.max_discount_pct');
    }

    /** ULID goes in the `ulid` column, not the primary key. */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DraftLine::class)->orderBy('position');
    }

    public function isFinalized(): bool
    {
        return $this->status === self::FINALIZED;
    }
}
