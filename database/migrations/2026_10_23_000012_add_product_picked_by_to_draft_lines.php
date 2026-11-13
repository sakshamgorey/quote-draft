<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('draft_lines', function (Blueprint $table) {
            // auto: preselected by the matcher; ai: the AI's tie-break; salesperson: chosen or confirmed by hand.
            $table->string('product_picked_by')->nullable()->after('product_id');
            // The AI tie-break: {product_id, reason, tied, model}
            $table->jsonb('ai_pick')->nullable();
        });

        // Lines that already have a product were chosen by hand (or kept): nothing to check.
        DB::table('draft_lines')->whereNotNull('product_id')->update(['product_picked_by' => 'salesperson']);
    }

    public function down(): void
    {
        Schema::table('draft_lines', fn (Blueprint $table) => $table->dropColumn(['product_picked_by', 'ai_pick']));
    }
};
