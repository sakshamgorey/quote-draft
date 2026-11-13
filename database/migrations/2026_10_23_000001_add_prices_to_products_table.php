<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Store selling price (Searchspring price == sale_price). Null = $0 in the feed: price on request.
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('msrp', 10, 2)->nullable();
            // When the page holding this price was fetched. Drafts show "prices as of".
            $table->timestamp('priced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['price', 'msrp', 'priced_at']);
        });
    }
};
