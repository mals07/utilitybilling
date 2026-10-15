<?php

namespace App\Enums;

/**
 * Kinds of single-use links emailed to users.
 */
enum AuthTokenType: string
{
    case EmailVerification = 'email_verification';
    case PasswordReset = 'password_reset';
    case Invitation = 'invitation';

    /**
     * How long a link stays valid, in minutes.
     */
    public function lifetimeMinutes(): int
    {
        return match ($this) {
            self::EmailVerification => 24 * 60,
            self::PasswordReset => 60,
            self::Invitation => 7 * 24 * 60,
        };
    }

    public function subject(): string
    {
        return match ($this) {
            self::EmailVerification => 'Verify your Utility Billing email address',
            self::PasswordReset => 'Reset your Utility Billing password',
            self::Invitation => "You're invited to Utility Billing",
        };
    }
}
