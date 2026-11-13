<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TAG_COLUMNS = ['application', 'surface_type', 'material', 'flange_type', 'purpose', 'category_paths'];

    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 32)->unique(); // Searchspring uid — the upsert key
            $table->string('sku', 64)->index();
            $table->string('model', 64)->index(); // SKU prefix: BA-AHD-GYP-18-18 → BA-AHD-GYP
            $table->text('name');
            $table->text('url');
            $table->text('image_url')->nullable();
            $table->string('product_type', 32)->index();

            // Opening size: what customers quote. Never match on overall.
            $table->decimal('nominal_width', 7, 3)->nullable();
            $table->decimal('nominal_height', 7, 3)->nullable();
            // Including flange (Searchspring width/height). Display only.
            $table->decimal('overall_width', 7, 3)->nullable();
            $table->decimal('overall_height', 7, 3)->nullable();

            $table->boolean('fire_rated')->nullable();
            $table->boolean('insulated')->nullable();
            $table->timestamps();

            $table->index(['product_type', 'nominal_width', 'nominal_height']);

            // laravel/scout PR #998 (same as foodsearch): generated tsvector
            // + GIN index, and a pg_trgm index on name for typo tolerance.
            $table->searchable(['name', 'sku', 'model'], [
                'weights' => ['name' => 'A', 'sku' => 'A', 'model' => 'B'],
                'trigram' => [
                    'columns' => ['name'],
                    'create_extension' => true,
                ],
            ]);
        });

        // Blueprint has no array type. Multi-value tags as text[] + GIN so
        // `application && '{Wall}'` style filters use an index.
        foreach (self::TAG_COLUMNS as $column) {
            DB::statement("alter table products add column {$column} text[] not null default '{}'");
            DB::statement("create index products_{$column}_gin on products using gin ({$column})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
