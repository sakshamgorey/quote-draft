<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('draft_lines', function (Blueprint $table) {
            // Part of the finalize snapshot, like sku/name/url/price.
            $table->text('product_image_url')->nullable()->after('product_url');
        });
    }

    public function down(): void
    {
        Schema::table('draft_lines', function (Blueprint $table) {
            $table->dropColumn('product_image_url');
        });
    }
};
