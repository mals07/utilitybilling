<?php

namespace App\Support;

use App\Enums\AuthTokenType;
use App\Models\AuthToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Single-use tokens for emailed links. The token goes in the link; only its SHA-256 hash is stored.
 */
final class AuthTokens
{
    /**
     * Minimum wait before another email of the same type can be requested.
     */
    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Issues a new token, invalidating any earlier one of the same type.
     */
    public static function issue(User $user, AuthTokenType $type): string
    {
        $token = Str::random(43);

        DB::transaction(function () use ($user, $type, $token): void {
            AuthToken::query()->where('user_id', $user->id)->where('type', $type)->delete();
            AuthToken::query()->create([
                'user_id' => $user->id,
                'type' => $type,
                'token_hash' => self::hash($token),
                'expires_at' => now()->addMinutes($type->lifetimeMinutes()),
            ]);
        });

        return $token;
    }

    /**
     * Seconds until another token may be issued, or 0.
     */
    public static function secondsUntilResendAllowed(User $user, AuthTokenType $type): int
    {
        $latest = AuthToken::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->latest('created_at')
            ->value('created_at');

        if ($latest === null) {
            return 0;
        }

        $elapsed = now()->diffInSeconds($latest, absolute: true);

        return max(0, (int) ceil(self::RESEND_COOLDOWN_SECONDS - $elapsed));
    }

    /**
     * The token's user id if it is valid and unexpired, without using it up.
     */
    public static function peek(string $token, AuthTokenType $type): ?int
    {
        if ($token === '') {
            return null;
        }

        $record = AuthToken::query()->where('token_hash', self::hash($token))->first();

        if ($record === null || $record->type !== $type || $record->expires_at->isPast()) {
            return null;
        }

        return $record->user_id;
    }

    /**
     * Validates and deletes the token. Returns its user id, or null if invalid or expired.
     */
    public static function consume(string $token, AuthTokenType $type): ?int
    {
        $userId = self::peek($token, $type);

        if ($userId === null) {
            return null;
        }

        // The delete count makes concurrent uses of one token unable to both succeed.
        $deleted = AuthToken::query()->where('token_hash', self::hash($token))->where('type', $type)->delete();

        return $deleted === 1 ? $userId : null;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
