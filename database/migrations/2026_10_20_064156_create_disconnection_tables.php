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
        // Disconnection settings on Company Setup → Operation Setup.
        Schema::table('company_profiles', function (Blueprint $table) {
            // A customer is flagged for disconnection with this many overdue bills.
            $table->unsignedTinyInteger('disconnection_overdue_bills')->default(2)->after('review_zero_usage');
            // Working days the customer has to pay after a notice of disconnection is issued.
            $table->unsignedTinyInteger('disconnection_notice_days')->default(5)->after('disconnection_overdue_bills');
            // ISO weekdays (1 = Monday … 7 = Sunday) not counted as working days, e.g. "6,7".
            $table->string('disconnection_skip_days', 20)->default('6,7')->after('disconnection_notice_days');
        });

        // A customer exempt from disconnection for non-payment, e.g. a hospital, until the date given (or for good).
        Schema::table('customers', function (Blueprint $table) {
            $table->string('disconnection_exempt_reason')->nullable()->after('status');
            $table->date('disconnection_exempt_until')->nullable()->after('disconnection_exempt_reason');
            $table->foreignId('disconnection_exempted_by_id')->nullable()->after('disconnection_exempt_until')->constrained('users')->nullOnDelete();
        });

        Schema::create('disconnection_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            $table->string('notice_number', 20);
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // A value of App\Enums\DisconnectionNoticeStatus.
            $table->string('status', 20)->default('pending');
            // What the customer owed when the notice was issued.
            $table->unsignedSmallInteger('overdue_bills');
            $table->decimal('overdue_amount', 12, 2);
            $table->date('oldest_due_date');
            $table->date('issued_on');
            // The last day to pay; the customer may be disconnected after it.
            $table->date('deadline');
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason')->nullable();
            $table->foreignId('issued_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'notice_number']);
            $table->index(['company_id', 'status', 'deadline']);
            $table->index(['customer_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disconnection_notices');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disconnection_exempted_by_id');
            $table->dropColumn(['disconnection_exempt_reason', 'disconnection_exempt_until']);
        });

        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropColumn(['disconnection_overdue_bills', 'disconnection_notice_days', 'disconnection_skip_days']);
        });
    }
};
