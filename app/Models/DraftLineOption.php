<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DraftLineOption extends Model
{
    public const DEFAULT = 'default';

    /** A required option the store marks no standard choice for: the lowest-priced one, to check. */
    public const ASSUMED = 'assumed';

    public const NOTES = 'notes';

    public const SALESPERSON = 'salesperson';

    protected $guarded = ['id'];

    public function line(): BelongsTo
    {
        return $this->belongsTo(DraftLine::class, 'draft_line_id');
    }
}
