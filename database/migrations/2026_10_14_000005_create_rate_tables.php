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
        Schema::create('rate_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        // Minimum consumption and how defective meters are billed, one per rate type.
        Schema::create('rate_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_type_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('minimum_consumption')->nullable();
            $table->boolean('defective_uses_minimum_consumption')->default(false);
            $table->boolean('defective_uses_three_month_average')->default(false);
            $table->timestamps();
        });

        // Consumption ranges are contiguous: each starts at the previous range's end + 1.
        Schema::create('rate_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('range_from');
            $table->unsignedInteger('range_to');
            $table->decimal('rate', 12, 2);
            $table->string('description');
            $table->timestamps();

            $table->unique(['rate_type_id', 'range_from']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_ranges');
        Schema::dropIfExists('rate_setups');
        Schema::dropIfExists('rate_types');
    }
};
