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
        // The customer's current meter. Changed only by completing work orders.
        Schema::table('customers', function (Blueprint $table) {
            $table->string('meter_number', 50)->nullable()->after('meter_type_id');
            $table->string('meter_serial_number', 50)->nullable()->after('meter_number');
            $table->date('meter_installed_on')->nullable()->after('meter_serial_number');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            // A value of App\Enums\WorkOrderStatus; now also "on_hold".
            $table->string('status', 20)->default('in_progress')->change();
            $table->string('hold_reason')->nullable()->after('status');
            $table->string('cancel_reason')->nullable()->after('hold_reason');
            // The order whose completion created this one, e.g. a Meter Repair found not repairable.
            $table->foreignId('parent_work_order_id')->nullable()->after('customer_id')->constrained('work_orders')->nullOnDelete();
            // The type-specific completion form: {fields: {key: value}, checklist: [item], remarks: ?string}.
            $table->json('completion_data')->nullable()->after('completed_by_id');
            // Something the office must follow up, e.g. "billing_adjustment"; a value of App\Enums\WorkOrderFlag.
            $table->string('review_flag', 50)->nullable()->after('completion_data');
            $table->decimal('fee_amount', 12, 2)->nullable()->after('review_flag');
            // Set by the Invoice page once the fee is on a bill.
            $table->timestamp('fee_billed_at')->nullable()->after('fee_amount');
        });

        // Readings taken while completing an order, picked up later by the Meter Readings page.
        Schema::create('work_order_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->string('meter_number', 50)->nullable();
            // A value of App\Enums\ReadingKind.
            $table->string('kind', 20);
            $table->decimal('reading', 12, 3);
            $table->date('read_on');
            // Set by the Meter Readings page once the reading is used.
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'read_on']);
        });

        Schema::create('work_order_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->binary('photo_data');
            $table->string('mime_type', 50);
            $table->string('file_name');
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // The order's timeline: who did what, and when.
        Schema::create('work_order_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 30);
            $table->text('description');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['work_order_id', 'id']);
        });

        // The fee prefilled when completing an order of each type.
        Schema::create('work_order_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            $table->string('order_type', 50);
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['company_id', 'order_type']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE work_order_photos MODIFY photo_data MEDIUMBLOB NOT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_fees');
        Schema::dropIfExists('work_order_activities');
        Schema::dropIfExists('work_order_photos');
        Schema::dropIfExists('work_order_readings');

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_work_order_id');
            $table->dropColumn(['hold_reason', 'cancel_reason', 'completion_data', 'review_flag', 'fee_amount', 'fee_billed_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['meter_number', 'meter_serial_number', 'meter_installed_on']);
        });
    }
};
