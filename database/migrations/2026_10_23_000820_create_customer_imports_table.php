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
        Schema::create('customer_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_name');
            $table->unsignedInteger('imported_count');
            $table->unsignedInteger('skipped_count');
            // The account numbers given to the first and last imported customer; null when none were imported.
            $table->string('first_account_number', 20)->nullable();
            $table->string('last_account_number', 20)->nullable();
            // The rows left out, with their values and the problem, for the downloadable skipped-rows file.
            $table->json('skipped_rows')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            // The import that added the customer; null for customers added one at a time.
            $table->foreignId('customer_import_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_import_id');
        });

        Schema::dropIfExists('customer_imports');
    }
};
