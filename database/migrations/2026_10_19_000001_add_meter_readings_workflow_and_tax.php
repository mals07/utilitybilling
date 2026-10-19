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
        // Tax and meter reading review settings on Company Setup → Operation Setup.
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->boolean('tax_enabled')->default(false)->after('overdue_handling');
            $table->string('tax_name', 30)->default('VAT')->after('tax_enabled');
            $table->decimal('tax_rate', 5, 2)->default(12)->after('tax_name');
            // Inclusive: rates and charges already contain the tax, so it is only shown, not added.
            $table->boolean('tax_inclusive')->default(false)->after('tax_rate');
            $table->boolean('tax_on_water')->default(true)->after('tax_inclusive');
            $table->boolean('tax_on_fees')->default(false)->after('tax_on_water');
            $table->boolean('tax_on_surcharges')->default(false)->after('tax_on_fees');
            // A reading needs review when usage is more than this many times the 3-month average; 0 turns it off.
            $table->decimal('review_usage_multiplier', 4, 1)->default(2)->after('tax_on_surcharges');
            $table->boolean('review_zero_usage')->default(true)->after('review_usage_multiplier');
        });

        // Whether the charge element is part of the amount the tax is computed on.
        Schema::table('charge_elements', function (Blueprint $table) {
            $table->boolean('is_taxable')->default(false)->after('is_after_due');
        });

        // Customers of a tax-exempt account type (e.g. Government) are billed without tax.
        Schema::table('account_types', function (Blueprint $table) {
            $table->boolean('is_tax_exempt')->default(false)->after('is_active');
        });

        // Number of dials on the meter, so a reading that wrapped past 9999 back to 0 can be billed.
        Schema::table('meter_types', function (Blueprint $table) {
            $table->unsignedTinyInteger('dial_digits')->nullable()->after('is_active');
        });

        Schema::table('meter_readings', function (Blueprint $table) {
            // A value of App\Enums\MeterReadingStatus.
            $table->string('status', 20)->default('ready')->after('billing_period');
            // A value of App\Enums\ReadingCode.
            $table->string('reading_code', 20)->default('normal')->after('status');
            // Usage measured (or estimated) before the rate's minimum consumption is applied.
            $table->decimal('actual_consumption', 12, 3)->nullable()->after('consumption');
            // Estimated usage billed since the last actual reading, deducted from this reading's usage.
            $table->decimal('estimate_deducted', 12, 3)->default(0)->after('replaced_meter_consumption');
            $table->string('review_reason', 500)->nullable()->after('is_defective');
            $table->string('notes', 500)->nullable()->after('review_reason');
            $table->foreignId('confirmed_by_id')->nullable()->after('created_by_id')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by_id');

            $table->index(['company_id', 'billing_period', 'status']);
        });

        DB::table('meter_readings')->whereNotNull('invoice_id')->update(['status' => 'billed']);
        DB::table('meter_readings')->where('is_defective', true)->update(['reading_code' => 'defective']);

        // The tax worked out for the bill, shown in its tax breakdown.
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('tax_name', 30)->nullable()->after('previous_balance');
            $table->decimal('tax_rate', 5, 2)->nullable()->after('tax_name');
            $table->boolean('tax_inclusive')->default(false)->after('tax_rate');
            $table->decimal('taxable_amount', 12, 2)->default(0)->after('tax_inclusive');
            $table->decimal('tax_amount', 12, 2)->default(0)->after('taxable_amount');
            $table->decimal('tax_exempt_amount', 12, 2)->default(0)->after('tax_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['tax_name', 'tax_rate', 'tax_inclusive', 'taxable_amount', 'tax_amount', 'tax_exempt_amount']);
        });

        Schema::table('meter_readings', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'billing_period', 'status']);
            $table->dropConstrainedForeignId('confirmed_by_id');
            $table->dropColumn(['status', 'reading_code', 'actual_consumption', 'estimate_deducted', 'review_reason', 'notes', 'confirmed_at']);
        });

        Schema::table('meter_types', function (Blueprint $table) {
            $table->dropColumn('dial_digits');
        });

        Schema::table('account_types', function (Blueprint $table) {
            $table->dropColumn('is_tax_exempt');
        });

        Schema::table('charge_elements', function (Blueprint $table) {
            $table->dropColumn('is_taxable');
        });

        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'tax_enabled', 'tax_name', 'tax_rate', 'tax_inclusive', 'tax_on_water', 'tax_on_fees', 'tax_on_surcharges',
                'review_usage_multiplier', 'review_zero_usage',
            ]);
        });
    }
};
