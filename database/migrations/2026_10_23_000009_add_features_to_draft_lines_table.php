<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('draft_lines', function (Blueprint $table) {
            // Features the notes mention ("high security", "airtight"): [{text, quote}]. Ranking only.
            $table->jsonb('features')->default('[]')->after('option_requests');
        });
    }

    public function down(): void
    {
        Schema::table('draft_lines', function (Blueprint $table) {
            $table->dropColumn('features');
        });
    }
};
