<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZohoSubmission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'request' => 'array',
            'response' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }
}
