<?php

namespace App\Console\Commands;

use App\Models\Draft;
use App\Models\DraftLine;
use App\Services\Drafts\DraftBuilder;
use App\Services\Drafts\ProductMatcher;
use Illuminate\Console\Command;

class PreselectDraftProducts extends Command
{
    protected $signature = 'drafts:preselect
        {--draft=* : Only these draft ULIDs}';

    protected $description = 'Redo the automatic preselection on open-draft lines (products the salesperson chose are left alone)';

    public function handle(DraftBuilder $drafts, ProductMatcher $matcher): int
    {
        $lines = DraftLine::query()
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_picked_by', DraftLine::PICKED_AUTO))
            ->whereHas('draft', fn ($q) => $q->where('status', Draft::DRAFT)
                ->when($this->option('draft'), fn ($q, $ulids) => $q->whereIn('ulid', $ulids)))
            ->get();

        $counts = [];
        foreach ($lines as $line) {
            $drafts->autoPick($line);
            $line->refresh();

            $match = $matcher->match($line->requestedItem());
            $outcome = $line->product_id ? ($match['pick']['confidence'] ?? 'kept') : ($match['shortlist'] ? 'choose' : 'none');
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        $this->table(['', 'lines'], [
            ['Lines checked', $lines->count()],
            ['Named in the notes (SKU, title, model)', $counts['named'] ?? 0],
            ['Only match', $counts['only'] ?? 0],
            ['Alone in confirming a stated detail', $counts['best_fit'] ?? 0],
            ['Equals: salesperson picks from the shortlist', $counts['choose'] ?? 0],
            ['Still none (needs details or no match)', $counts['none'] ?? 0],
        ]);

        return self::SUCCESS;
    }
}
