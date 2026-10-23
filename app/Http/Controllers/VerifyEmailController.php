<?php

namespace App\Http\Controllers;

use App\Enums\AuthTokenType;
use App\Models\User;
use App\Support\AuthTokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Target of the emailed verification link.
 */
class VerifyEmailController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $userId = AuthTokens::consume((string) $request->query('token', ''), AuthTokenType::EmailVerification);
        $currentUser = $request->user();

        if ($userId === null) {
            return $currentUser
                ? redirect()->route('verify-email', ['error' => 'invalid'])
                : redirect()->route('login', ['verify' => 'invalid']);
        }

        User::query()->whereKey($userId)->whereNull('email_verified_at')->update(['email_verified_at' => now()]);

        // Signed in as this user: continue to the welcome page (or the setup wizard once it's been seen).
        if ($currentUser?->id === $userId) {
            return redirect()->route($currentUser->needsWelcome() ? 'welcome' : 'setup', ['verified' => 1]);
        }

        return redirect()->route('login', ['verified' => 1]);
    }
}
