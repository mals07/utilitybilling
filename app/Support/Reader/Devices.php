<?php

namespace App\Support\Reader;

use App\Enums\Access;
use App\Enums\Page;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Meter reader devices and their API tokens. A device is registered by signing in online with the
 * reader's web account; it then holds its own token, of which only the SHA-256 hash is stored.
 */
final class Devices
{
    /**
     * How long a token lasts without the device contacting the server.
     */
    public const TOKEN_DAYS = 30;

    /**
     * Registers a device for the user who signs in, and returns it with its token (shown only now).
     *
     * @return array{device: Device, token: string}
     *
     * @throws ReaderException When the sign-in fails or the user may not use the app.
     */
    public static function register(string $login, string $password, string $name, ?string $appVersion): array
    {
        $login = trim($login);
        $user = str_contains($login, '@')
            ? User::query()->where('email', mb_strtolower($login))->first()
            : User::query()->where('username', $login)->first();

        // Unknown accounts still check a hash, so they take as long as known ones.
        $passwordOk = Hash::check($password, $user?->password ?? Hash::make('not-a-real-password'));

        if ($user === null || $user->password === null || ! $passwordOk) {
            throw new ReaderException('Incorrect email address, username or password.', 401);
        }

        $refusal = self::refusal($user);
        if ($refusal !== null) {
            throw new ReaderException($refusal, 403);
        }

        $token = bin2hex(random_bytes(32));
        $device = Device::query()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'name' => mb_substr(trim($name), 0, 50),
            'token_hash' => hash('sha256', $token),
            'token_expires_at' => now()->addDays(self::TOKEN_DAYS),
            'app_version' => $appVersion,
            'last_seen_at' => now(),
        ]);

        return ['device' => $device, 'token' => $token];
    }

    /**
     * The device a token belongs to, if it is still valid; renews the token's expiry.
     *
     * @param  bool  $allowRevoked  Accept a signed-out device too, to upload what it already recorded.
     *                              Its token isn't renewed, so that ends when the token expires.
     */
    public static function authenticate(string $token, bool $allowRevoked = false): ?Device
    {
        $device = Device::query()->withoutGlobalScopes()->with('user')->where('token_hash', hash('sha256', $token))->first();

        if ($device === null
            || ($device->isRevoked() && ! $allowRevoked)
            || $device->token_expires_at->isPast()
            || self::refusal($device->user) !== null) {
            return null;
        }

        $device->forceFill([
            'last_seen_at' => now(),
            'token_expires_at' => $device->isRevoked() ? $device->token_expires_at : now()->addDays(self::TOKEN_DAYS),
        ])->saveQuietly();

        return $device;
    }

    /**
     * Ends the device's access at once. It may still upload the readings it already took (bills it
     * printed are with the customers), but every one is held for review; anything else is refused.
     */
    public static function revoke(Device $device, User $by): void
    {
        $device->update(['revoked_at' => now(), 'revoked_by_id' => $by->id]);
    }

    /**
     * Why the user may not use the meter reader app, if they may not: the app saves readings, so it
     * takes Add access on Meter Readings.
     */
    private static function refusal(User $user): ?string
    {
        return match (true) {
            $user->isDeactivated() => 'This account has been deactivated. Contact your administrator to restore access.',
            ! $user->hasCompany() => 'Your account isn\'t part of a company yet.',
            ! $user->canAccessPage(Page::MeterReadings, Access::Add) => 'Your role doesn\'t allow entering meter readings. Ask your administrator for access.',
            default => null,
        };
    }
}
