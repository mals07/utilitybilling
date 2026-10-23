<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The last number given out in each of a company's numbered sequences (invoices, receipts, ...),
        // so the next number doesn't have to be found by reading every number issued so far.
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            // The table, plus the columns that split it into separate sequences, e.g. "invoice_adjustments:type=credit".
            $table->string('sequence', 100);
            $table->unsignedBigInteger('last_number');

            $table->unique(['company_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
