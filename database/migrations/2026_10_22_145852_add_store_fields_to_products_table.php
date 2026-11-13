<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('brand', 100)->nullable()->index(); // manufacturer: Acudor, Bilco, Milcor…
            // Searchspring "availability": disabled = discontinued/hidden on the store. Never offered.
            $table->boolean('is_available')->default(true)->index();
            $table->unsignedInteger('popularity')->default(0); // store's bestseller score
            $table->unsignedInteger('purchases')->default(0); // ga_unique_purchases
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);
        });

        // Searchspring custom_type_of_access (Access Doors, Floor Doors, Roof Hatches…).
        DB::statement("alter table products add column type_of_access text[] not null default '{}'");
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['brand', 'is_available', 'popularity', 'purchases', 'rating', 'rating_count', 'type_of_access']);
        });
    }
};
