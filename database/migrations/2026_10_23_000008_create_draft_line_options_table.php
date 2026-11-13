<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The options chosen on a draft line. Referenced by the store's own option / choice ids
        // (stable across catalog:parse-pages, which recreates our option rows), with a snapshot
        // of label and price so a finalized draft never changes.
        Schema::create('draft_line_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_line_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('store_option_id')->nullable();
            $table->unsignedInteger('store_value_id')->nullable();
            $table->string('option_label');
            $table->string('value_name');
            $table->integer('price_delta_cents')->nullable();
            $table->string('lead_time_text', 64)->nullable();
            $table->string('source', 16); // default | notes | salesperson
            $table->text('quote')->nullable(); // the notes' words, when source = notes
            $table->timestamps();

            $table->unique(['draft_line_id', 'store_option_id']);
        });

        Schema::table('draft_lines', function (Blueprint $table) {
            // Add-ons / variants the notes asked for: [{text, quote}] ("keyed lock", "with safety rails").
            $table->jsonb('option_requests')->default('[]')->after('rejected');
            // Frozen on finalize: sum of the chosen options' prices (not discounted).
            $table->decimal('options_price', 10, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('draft_lines', function (Blueprint $table) {
            $table->dropColumn(['option_requests', 'options_price']);
        });
        Schema::dropIfExists('draft_line_options');
    }
};
