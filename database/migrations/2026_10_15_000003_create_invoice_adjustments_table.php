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
        // A credit memo (lowers what is owed on an invoice) or a debit memo (adds to it).
        Schema::create('invoice_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            // A value of App\Enums\AdjustmentType; credit and debit memos are numbered separately.
            $table->string('type', 10);
            $table->string('memo_number', 20);
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // A value of App\Enums\AdjustmentReason.
            $table->string('reason_category', 30);
            $table->string('reason', 500);
            // Always positive; the type says which way it goes.
            $table->decimal('amount', 12, 2);
            $table->date('issued_on');
            // The flagged work order this adjustment resolves.
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'type', 'memo_number']);
            $table->index(['invoice_id', 'voided_at']);
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('adjustment_id')->nullable()->after('source_invoice_id')->constrained('invoice_adjustments')->nullOnDelete();
        });

        // When the office dealt with a work order's review flag, and how.
        Schema::table('work_orders', function (Blueprint $table) {
            $table->timestamp('flag_resolved_at')->nullable()->after('review_flag');
            $table->string('flag_resolution')->nullable()->after('flag_resolved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['flag_resolved_at', 'flag_resolution']);
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adjustment_id');
        });

        Schema::dropIfExists('invoice_adjustments');
    }
};
