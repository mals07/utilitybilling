<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Temporary, local only: signs in as a brand-new account with no company, so the setup wizard can
 * be tried from step 1 without signing up again. Each use leaves an "Onboarding Tester" account behind.
 */
class RestartOnboardingController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(app()->isLocal(), 404);

        $user = User::query()->create([
            'name' => 'Onboarding Tester',
            ...User::splitFullName('Onboarding Tester'),
            'email' => 'onboarding-'.now()->format('YmdHis').'-'.Str::lower(Str::random(4)).'@onboarding.test',
            'password' => Str::password(32),
            'is_admin' => true,
            'email_verified_at' => now(),
            'last_login_at' => now(),
        ]);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('setup');
    }
}
