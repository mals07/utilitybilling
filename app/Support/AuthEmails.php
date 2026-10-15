<?php

namespace App\Support;

use App\Enums\AuthTokenType;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The verification, password reset and invitation emails.
 */
final class AuthEmails
{
    public static function sendVerification(User $user): void
    {
        $url = route('auth.verify', ['token' => AuthTokens::issue($user, AuthTokenType::EmailVerification)]);

        EmailDelivery::send($user->email, AuthTokenType::EmailVerification->subject(), implode("\n", [
            "Hi {$user->name},",
            '',
            'Thanks for signing up for Utility Billing. Confirm your email address by opening this link:',
            $url,
            '',
            "The link expires in 24 hours. If you didn't create an account, you can ignore this email.",
        ]), $url);
    }

    public static function sendPasswordReset(User $user): void
    {
        $url = route('password.reset', ['token' => AuthTokens::issue($user, AuthTokenType::PasswordReset)]);

        EmailDelivery::send($user->email, AuthTokenType::PasswordReset->subject(), implode("\n", [
            "Hi {$user->name},",
            '',
            'We received a request to reset your Utility Billing password. Choose a new one here:',
            $url,
            '',
            "The link expires in 1 hour. If you didn't request this, you can ignore this email.",
        ]), $url);
    }

    public static function sendInvitation(User $user, string $companyName): void
    {
        $url = route('invitation.accept', ['token' => AuthTokens::issue($user, AuthTokenType::Invitation)]);

        EmailDelivery::send($user->email, AuthTokenType::Invitation->subject(), implode("\n", [
            "Hi {$user->name},",
            '',
            "{$companyName} has invited you to Utility Billing. Open this link to verify your email address and create your username and password:",
            $url,
            '',
            "The link expires in 7 days. If you weren't expecting this, you can ignore this email.",
        ]), $url);
    }

    /**
     * Runs a send, reporting failure instead of throwing, so a mail outage doesn't undo the save around it.
     */
    public static function trySend(callable $send, string $context): bool
    {
        try {
            $send();

            return true;
        } catch (Throwable $exception) {
            Log::error("Failed to send {$context} email", ['exception' => $exception]);

            return false;
        }
    }

    /**
     * The link last emailed to an address, while EMAIL_DELIVERY=console.
     */
    public static function devLink(string $email, AuthTokenType $type): ?string
    {
        return EmailDelivery::latestLink($email, $type->subject());
    }
}
