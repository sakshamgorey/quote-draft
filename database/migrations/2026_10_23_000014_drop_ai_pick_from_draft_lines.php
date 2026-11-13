<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The AI no longer breaks ties: its picks count as automatic, so drafts:preselect re-judges them.
        DB::table('draft_lines')->where('product_picked_by', 'ai')->update(['product_picked_by' => 'auto']);

        Schema::table('draft_lines', fn (Blueprint $table) => $table->dropColumn('ai_pick'));
    }

    public function down(): void
    {
        Schema::table('draft_lines', fn (Blueprint $table) => $table->jsonb('ai_pick')->nullable());
    }
};
