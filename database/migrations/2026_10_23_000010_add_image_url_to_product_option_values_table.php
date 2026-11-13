<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_values', function (Blueprint $table) {
            // The store's picture of the choice (curb face, color swatch), shown in the draft's option picker.
            $table->string('image_url', 1000)->nullable()->after('raw_label');
        });
    }

    public function down(): void
    {
        Schema::table('product_option_values', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
