<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drafts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique(); // public, unguessable URL key (no login)
            $table->text('message'); // what the customer actually wrote
            $table->string('model', 64)->nullable(); // which LLM read it
            $table->jsonb('extraction')->nullable(); // raw LLM JSON, before validation (audit)
            $table->string('status', 16)->default('draft')->index(); // draft | finalized
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('draft_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('source', 8)->default('ai'); // ai | manual
            $table->string('product_type', 32)->nullable(); // null = unclear
            $table->text('quote')->nullable(); // customer's words for this line
            $table->jsonb('specs')->default('{}'); // name => {value, quote|null}; quote null = entered by salesperson
            $table->jsonb('rejected')->default('[]'); // specs the validator dropped
            $table->unsignedInteger('quantity')->default(1);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('ask_customer')->default(false); // resolved by sending the questions

            // Frozen on finalize: a later catalog import must not change a finished draft.
            $table->string('product_sku', 64)->nullable();
            $table->text('product_name')->nullable();
            $table->text('product_url')->nullable();
            $table->string('product_size', 32)->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->timestamps();

            $table->index(['draft_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_lines');
        Schema::dropIfExists('drafts');
    }
};
