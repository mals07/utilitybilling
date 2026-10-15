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
        // Single-use email tokens (verification, password reset, invitation). Only the SHA-256 hash is stored.
        Schema::create('auth_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'type']);
        });

        // Emails captured instead of sent while EMAIL_DELIVERY=console (development).
        Schema::create('email_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('to', 150);
            $table->string('subject');
            $table->text('body');
            $table->string('action_url', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['to', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_outbox');
        Schema::dropIfExists('auth_tokens');
    }
};
