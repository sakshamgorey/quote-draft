<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer accounts: the trusted source for who a quote is for and what they pay.
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->jsonb('contacts')->default('[]'); // [{name, email, phone}] — also used to redact notes
            $table->text('ship_to')->nullable();
            $table->string('payment_terms', 64)->nullable(); // "Net 30"
            $table->boolean('tax_exempt')->default(false);
            $table->decimal('discount_pct', 5, 2)->default(0); // contract discount on every line
            $table->decimal('max_discount_pct', 5, 2)->nullable(); // null = config('pricing.max_discount_pct')
            $table->text('notes')->nullable(); // internal, never sent to the AI or the customer
            $table->timestamps();

            // Same Scout pgsql engine as products: company names get typo tolerance.
            $table->searchable(['name'], [
                'weights' => ['name' => 'A'],
                'trigram' => ['columns' => ['name'], 'create_extension' => true],
            ]);
        });

        // Quantity breaks and promotions. account_id null = applies to everyone.
        Schema::create('price_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('label', 120);
            $table->string('product_type', 32)->nullable(); // null = any type
            $table->string('brand', 100)->nullable(); // null = any brand
            $table->unsignedInteger('min_quantity')->default(1);
            $table->decimal('discount_pct', 5, 2);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'account_id']);
        });

        Schema::table('drafts', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('ulid')->constrained()->nullOnDelete();
            $table->text('redacted_message')->nullable()->after('message'); // exactly what the AI saw
            $table->jsonb('notes')->nullable()->after('extraction'); // validated discount / delivery / internal / follow-ups
            $table->decimal('discount_pct', 5, 2)->nullable(); // quote-level discount set by the salesperson
            $table->jsonb('account_snapshot')->nullable(); // frozen on finalize
        });

        Schema::table('draft_lines', function (Blueprint $table) {
            // Frozen on finalize, next to unit_price (which becomes the net price).
            $table->decimal('list_price', 10, 2)->nullable()->after('product_size');
            $table->decimal('discount_pct', 5, 2)->nullable()->after('list_price');
            $table->string('discount_label', 160)->nullable()->after('discount_pct');
        });
    }

    public function down(): void
    {
        Schema::table('draft_lines', function (Blueprint $table) {
            $table->dropColumn(['list_price', 'discount_pct', 'discount_label']);
        });
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
            $table->dropColumn(['redacted_message', 'notes', 'discount_pct', 'account_snapshot']);
        });
        Schema::dropIfExists('price_rules');
        Schema::dropIfExists('accounts');
    }
};
