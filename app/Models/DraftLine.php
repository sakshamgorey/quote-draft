<?php

namespace App\Models;

use App\Services\Drafts\RequestedItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DraftLine extends Model
{
    /** Who put the product on the line: the matcher (only when the notes settle it) or the salesperson. */
    public const PICKED_AUTO = 'auto';

    public const PICKED_SALESPERSON = 'salesperson';

    /** Line sources: read from the notes, added from search, added from a suggestion. */
    public const SOURCE_SUGGESTION = 'suggestion';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'specs' => 'array',
            'rejected' => 'array',
            'option_requests' => 'array',
            'features' => 'array',
            'ai_suggestions' => 'array',
            'unit_price' => 'decimal:2',
            'list_price' => 'decimal:2',
            'options_price' => 'decimal:2',
            'discount_pct' => 'float',
        ];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Options chosen for the selected product (store option id → choice). */
    public function chosenOptions(): HasMany
    {
        return $this->hasMany(DraftLineOption::class);
    }

    /** The line as the matcher sees it: same rules whether specs came from the LLM or the salesperson. */
    public function requestedItem(): RequestedItem
    {
        return new RequestedItem($this->product_type, $this->quote, $this->specs ?? [], $this->rejected ?? [], $this->option_requests ?? [], $this->features ?? []);
    }
}
