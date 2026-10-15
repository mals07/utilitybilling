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
        // Each account owns exactly one company profile, created during onboarding.
        Schema::create('company_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('company_name', 150);
            $table->string('address');
            $table->foreignId('city_id')->constrained();
            $table->foreignId('province_id')->constrained();
            $table->foreignId('country_id')->constrained();
            $table->string('zip_code', 10);
            $table->string('email', 150);
            $table->string('tax_id_number', 30)->nullable();
            $table->string('phone_number', 30)->nullable();
            $table->string('company_email', 150)->nullable();
            $table->string('website')->nullable();

            $table->binary('logo_data')->nullable();
            $table->string('logo_mime_type', 50)->nullable();
            $table->string('logo_file_name')->nullable();
            $table->binary('icon_data')->nullable();
            $table->string('icon_mime_type', 50)->nullable();
            $table->string('icon_file_name')->nullable();

            $table->string('head_of_company', 150);
            $table->string('point_person_first_name', 75);
            $table->string('point_person_last_name', 75);
            $table->string('point_person_contact', 30);
            $table->string('point_person_email', 150);
            $table->string('employee_number', 50)->nullable();
            $table->string('employee_title', 100)->nullable();

            // Operation setup
            $table->string('company_header_line')->nullable();
            $table->string('billing_header_1')->nullable();
            $table->string('billing_header_2')->nullable();
            $table->string('billing_header_3')->nullable();
            $table->string('billing_footer_1')->nullable();
            $table->string('billing_footer_2')->nullable();
            $table->string('billing_footer_3')->nullable();
            $table->unsignedSmallInteger('due_date_days')->default(5);
            $table->string('currency', 3)->nullable();
            $table->string('timezone', 64)->nullable();

            $table->timestamps();
        });

        // Uploads can be up to 3MB, more than a BLOB holds.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE company_profiles MODIFY logo_data MEDIUMBLOB NULL, MODIFY icon_data MEDIUMBLOB NULL');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('company_profiles')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::dropIfExists('company_profiles');
    }
};
