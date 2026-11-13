<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('draft_lines', function (Blueprint $table) {
            $table->string('source', 16)->default('ai')->change(); // ai | manual | suggestion
        });
    }

    public function down(): void
    {
        Schema::table('draft_lines', function (Blueprint $table) {
            $table->string('source', 8)->default('ai')->change();
        });
    }
};
