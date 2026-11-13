<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data read from each product's page on the store (catalog:fetch-pages / catalog:parse-pages).
 * Kept apart from `products` so the Searchspring import never overwrites it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('store_product_id')->nullable();
            $table->unsignedSmallInteger('parser_version');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('parsed_at')->nullable();
            $table->decimal('page_price', 10, 2)->nullable(); // shown on the page, compared with the feed
            $table->text('description')->nullable();
            $table->jsonb('spec_lines')->default('[]'); // [{label, value}] as written: "Cover: 14 gauge … insulation"
            $table->text('spec_sheet_url')->nullable();
            // Derived from the page. *_basis: stated = the page says so; not_mentioned = specs listed without it.
            $table->boolean('insulated')->nullable();
            $table->string('insulated_basis', 16)->nullable();
            $table->text('insulation_evidence')->nullable();
            $table->unsignedSmallInteger('gauge')->nullable();
            $table->jsonb('flange_types')->default('[]');
            $table->string('material', 120)->nullable();
            $table->jsonb('warnings')->default('[]'); // parse problems worth a human look
            $table->timestamps();
        });

        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('store_option_id')->nullable();
            $table->string('label'); // "Choose a Lock / Latch"
            $table->string('display_type', 32); // store widget: product-list, set-select, swatch…
            $table->boolean('required')->default(false);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });

        Schema::create('product_option_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_option_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('store_value_id')->nullable();
            $table->text('raw_label'); // exactly as on the page: "Key Operated Cylinder Cam Latch $16 + 5 days"
            $table->string('name'); // "Key Operated Cylinder Cam Latch"
            $table->integer('price_delta_cents')->nullable(); // null = couldn't read a price (see needs_review)
            $table->string('lead_time_text', 64)->nullable(); // "+ 5 days" — information only, never promised
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->boolean('is_default')->default(false); // "… - Standard"
            $table->boolean('is_none')->default(false); // "No Selected"
            $table->boolean('needs_review')->default(false);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->index(['product_option_id', 'position']);
        });

        Schema::create('product_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24); // size_sibling | compatible_with | accessory_for
            $table->foreignId('related_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('related_model', 64)->nullable(); // "BA-PDS"
            $table->string('related_type', 32)->nullable(); // product_type, for "for Roof Hatches"
            $table->text('related_url')->nullable(); // size picker link (set even when we don't have that product)
            $table->text('evidence')->nullable(); // the words it came from
            $table->timestamps();

            $table->index(['product_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_links');
        Schema::dropIfExists('product_option_values');
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('product_pages');
    }
};
