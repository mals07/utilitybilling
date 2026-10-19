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
        // How unpaid bills are surcharged: a value of App\Enums\OverdueHandling.
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->string('overdue_handling', 20)->default('next_bill')->after('due_date_days');
        });

        // Whether a work order fee gets its own invoice or goes on the next monthly bill: App\Enums\FeeBilling.
        // Null uses the type's default (FeeBilling::defaultFor()).
        Schema::table('work_order_fees', function (Blueprint $table) {
            $table->string('billing', 20)->nullable()->after('amount');
            $table->decimal('amount', 12, 2)->nullable()->change();
        });

        // A monthly reading taken during a billing run. One per customer per billing period.
        Schema::create('meter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('meter_number', 50)->nullable();
            // "2026-10"
            $table->string('billing_period', 7);
            $table->decimal('previous_reading', 12, 3);
            $table->decimal('present_reading', 12, 3)->nullable();
            // Billed consumption in cu.m., after the minimum or a defective-meter estimate.
            $table->decimal('consumption', 12, 3);
            // Usage on a meter replaced during the period, included in consumption.
            $table->decimal('replaced_meter_consumption', 12, 3)->default(0);
            $table->boolean('is_defective')->default(false);
            $table->date('read_on');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'billing_period']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            // Numbered per company in the order invoices are issued, e.g. "000001".
            $table->string('invoice_number', 20);
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // A value of App\Enums\InvoiceKind: a monthly bill or a work order fee.
            $table->string('kind', 20);
            $table->string('billing_period', 7)->nullable();
            $table->foreignId('meter_reading_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->decimal('total_amount', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('balance', 12, 2);
            // Unpaid balance of the customer's other bills when this one was issued, shown on the bill.
            $table->decimal('previous_balance', 12, 2)->default(0);
            // A value of App\Enums\InvoiceStatus.
            $table->string('status', 20)->default('unpaid');
            // When after-due charges were added for this invoice (on itself or on a later bill); once only.
            $table->timestamp('surcharged_at')->nullable();
            $table->foreignId('surcharged_on_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'invoice_number']);
            $table->index(['customer_id', 'status', 'due_date']);
            $table->index(['company_id', 'billing_period']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            // A value of App\Enums\InvoiceLineKind.
            $table->string('kind', 20);
            $table->string('description');
            $table->decimal('quantity', 12, 3)->nullable();
            $table->decimal('rate', 12, 2)->nullable();
            // Negative for deductions such as discounts.
            $table->decimal('amount', 12, 2);
            $table->foreignId('charge_element_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            // The overdue invoice a surcharge line is for.
            $table->foreignId('source_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('meter_readings', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('read_on')->constrained()->nullOnDelete();
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('fee_invoice_id')->nullable()->after('fee_billed_at')->constrained('invoices')->nullOnDelete();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            // Official receipt number, per company, e.g. "000001".
            $table->string('receipt_number', 20);
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 12, 2);
            // Part of the amount not applied to any bill yet: the customer's advance credit.
            $table->decimal('unapplied_amount', 12, 2)->default(0);
            // A value of App\Enums\PaymentMethod.
            $table->string('method', 20);
            $table->string('reference_number', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('received_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'receipt_number']);
            $table->index(['customer_id', 'voided_at']);
        });

        // How much of a payment went to which invoice.
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->index('invoice_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_invoice_id');
        });
        Schema::table('meter_readings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });

        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('meter_readings');

        Schema::table('work_order_fees', function (Blueprint $table) {
            $table->dropColumn('billing');
        });
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropColumn('overdue_handling');
        });
    }
};
