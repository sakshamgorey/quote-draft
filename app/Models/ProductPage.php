<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
            'parsed_at' => 'datetime',
            'page_price' => 'decimal:2',
            'spec_lines' => 'array',
            'insulated' => 'boolean',
            'flange_types' => 'array',
            'warnings' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
