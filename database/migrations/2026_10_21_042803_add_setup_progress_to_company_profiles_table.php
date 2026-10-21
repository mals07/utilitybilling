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
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->string('setup_step', 30)->nullable()->after('disconnection_skip_days');
            $table->timestamp('setup_completed_at')->nullable()->after('setup_step');
        });

        // Companies set up before the wizard existed are already in use.
        DB::table('company_profiles')->update(['setup_completed_at' => now()]);

        // The setup wizard replaces the Getting Started checklist.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('getting_started_dismissed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('getting_started_dismissed_at')->nullable()->after('tour_completed_at');
        });

        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropColumn(['setup_step', 'setup_completed_at']);
        });
    }
};
