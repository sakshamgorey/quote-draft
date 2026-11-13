<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every line now needs a product the salesperson picked; "ask the customer" is gone.
        Schema::table('draft_lines', function (Blueprint $table) {
            $table->dropColumn('ask_customer');
        });

        // What was sent to Zoho, exactly, and what came back. One per draft = idempotent sends.
        Schema::create('zoho_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mode', 16); // dummy | live
            $table->string('method', 8);
            $table->text('url');
            $table->jsonb('request'); // headers (token masked) + body
            $table->jsonb('response');
            $table->string('zoho_record_id', 64)->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoho_submissions');

        Schema::table('draft_lines', function (Blueprint $table) {
            $table->boolean('ask_customer')->default(false);
        });
    }
};
