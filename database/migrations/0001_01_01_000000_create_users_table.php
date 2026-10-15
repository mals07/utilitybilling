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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // Display name, kept as "first last".
            $table->string('name', 100);
            $table->string('first_name', 75);
            $table->string('last_name', 75);
            $table->string('email', 150)->unique();
            // Chosen by invited users when they accept the invitation; they can log in with it.
            $table->string('username', 30)->nullable()->unique();
            // Null until an invited user accepts the invitation.
            $table->string('password')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            // Admins can do everything; other users get what their role allows.
            $table->boolean('is_admin')->default(false);
            // Foreign keys are added once the roles, companies and departments tables exist.
            $table->unsignedBigInteger('role_id')->nullable();
            // The company the user works in: the one they own, or the one that invited them.
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            // Deactivated users can't log in.
            $table->timestamp('deactivated_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
