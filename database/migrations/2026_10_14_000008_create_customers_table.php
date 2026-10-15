<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            // Numbered per company in the order customers are added, e.g. "000001".
            $table->string('account_number', 20);
            $table->string('first_name', 75);
            $table->string('last_name', 75);
            $table->string('company_name', 150)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone_number', 30)->nullable();
            $table->enum('status', ['pending', 'connected', 'disconnected'])->default('pending');

            $table->foreignId('account_type_id')->constrained();
            $table->foreignId('charge_type_id')->constrained();
            // Not chosen in the Add/Update Customer modals; assigned later.
            $table->foreignId('meter_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('rate_type_id')->constrained();
            $table->foreignId('cluster_type_id')->nullable()->constrained()->nullOnDelete();

            $table->string('home_address');
            $table->foreignId('home_city_id')->constrained('cities');
            $table->foreignId('home_province_id')->constrained('provinces');
            $table->foreignId('home_country_id')->constrained('countries');
            $table->string('home_zip_code', 10);

            // The billing address is optional; when given, all of its fields are.
            $table->string('billing_address')->nullable();
            $table->foreignId('billing_city_id')->nullable()->constrained('cities');
            $table->foreignId('billing_province_id')->nullable()->constrained('provinces');
            $table->foreignId('billing_country_id')->nullable()->constrained('countries');
            $table->string('billing_zip_code', 10)->nullable();

            // The pin dropped on the map; both are set or both are null.
            $table->double('latitude')->nullable();
            $table->double('longitude')->nullable();

            $table->binary('photo_data')->nullable();
            $table->string('photo_mime_type', 50)->nullable();
            $table->string('photo_file_name')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'account_number']);
            $table->index(['company_id', 'last_name', 'first_name']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE customers MODIFY photo_data MEDIUMBLOB NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
