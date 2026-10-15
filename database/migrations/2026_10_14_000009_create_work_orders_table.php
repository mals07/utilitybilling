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
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            // Numbered per company in the order work orders are submitted, e.g. "000001".
            $table->string('order_number', 20);
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // A value of App\Enums\WorkOrderType.
            $table->string('order_type', 50);
            $table->foreignId('meter_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('meter_number', 50)->nullable();
            $table->string('serial_number', 50)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['in_progress', 'completed', 'cancelled'])->default('in_progress');
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'order_number']);
            $table->index(['company_id', 'status', 'requested_at']);
        });

        // The role whose users are listed in the work order's Assigned To field; null lists every active user.
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->foreignId('work_order_assignee_role_id')->nullable()->constrained('roles')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_order_assignee_role_id');
        });

        Schema::dropIfExists('work_orders');
    }
};
