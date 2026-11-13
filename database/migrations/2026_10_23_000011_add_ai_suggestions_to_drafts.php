<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->string('ai_suggestions_status')->nullable(); // done / failed; null = not asked yet
            $table->string('ai_suggestions_model')->nullable();
            $table->text('ai_suggestions_error')->nullable();
            $table->timestamp('ai_suggested_at')->nullable();
        });

        Schema::table('draft_lines', function (Blueprint $table) {
            // [{product_id, kind: variant|add_on, reason, quote, for_product_id}]
            $table->jsonb('ai_suggestions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('draft_lines', fn (Blueprint $table) => $table->dropColumn('ai_suggestions'));
        Schema::table('drafts', fn (Blueprint $table) => $table->dropColumn(['ai_suggestions_status', 'ai_suggestions_model', 'ai_suggestions_error', 'ai_suggested_at']));
    }
};
