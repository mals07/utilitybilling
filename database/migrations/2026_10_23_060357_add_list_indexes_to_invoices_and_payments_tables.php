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
        Schema::table('invoices', function (Blueprint $table) {
            // The Invoices list: newest first.
            $table->index(['company_id', 'invoice_date', 'id']);
            // Outstanding and overdue totals, the Open/Overdue filters and the surcharge run.
            $table->index(['company_id', 'status', 'due_date']);
        });

        Schema::table('payments', function (Blueprint $table) {
            // The Payments list: newest first.
            $table->index(['company_id', 'paid_on', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'invoice_date', 'id']);
            $table->dropIndex(['company_id', 'status', 'due_date']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'paid_on', 'id']);
        });
    }
};
