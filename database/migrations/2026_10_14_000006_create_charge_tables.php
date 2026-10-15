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
        Schema::create('charge_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('charge_elements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            $table->string('name');
            $table->enum('distribution_type', ['credit', 'debit']);
            // Charged only after the due date, e.g. a surcharge.
            $table->boolean('is_after_due')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        // A charge element applied to a charge type, as a fixed amount or a percentage.
        Schema::create('charge_type_elements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charge_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_element_id')->constrained()->cascadeOnDelete();
            $table->enum('value_type', ['fixed', 'percentage']);
            $table->decimal('value', 12, 2);
            $table->timestamps();

            $table->unique(['charge_type_id', 'charge_element_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charge_type_elements');
        Schema::dropIfExists('charge_elements');
        Schema::dropIfExists('charge_types');
    }
};
