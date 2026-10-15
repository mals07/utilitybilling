<?php

namespace App\Livewire\Auth;

use App\Enums\AuthTokenType;
use App\Models\User;
use App\Support\AuthTokens;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Reset Password')]
class ResetPassword extends Component
{
    #[Locked]
    public string $token = '';

    #[Locked]
    public bool $linkValid = false;

    public string $password = '';

    public string $confirmPassword = '';

    /**
     * Shown when the link stopped working while the form was open.
     */
    public ?string $message = null;

    public function mount(): void
    {
        $this->token = (string) request()->query('token', '');
        $this->linkValid = AuthTokens::peek($this->token, AuthTokenType::PasswordReset) !== null;
    }

    public function resetPassword(): void
    {
        $this->message = null;

        $rules = AuthRules::newPassword();
        $this->validate($rules['rules'], $rules['messages']);

        $userId = AuthTokens::consume($this->token, AuthTokenType::PasswordReset);
        if ($userId === null) {
            $this->message = 'This password reset link is invalid or has expired. Please request a new one.';

            return;
        }

        $user = User::query()->findOrFail($userId);
        $user->forceFill([
            'password' => $this->password,
            // Opening the emailed link proves ownership of the address.
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        // Signs the user out of all devices, this browser included.
        $user->signOutEverywhere();
        if (Auth::id() === $user->id) {
            Auth::guard('web')->logout();
            session()->invalidate();
            session()->regenerateToken();
        }

        $this->redirectRoute('login', ['reset' => 1]);
    }

    public function render(): View
    {
        return view('livewire.auth.reset-password', ['passwordHint' => AuthRules::PASSWORD_HINT]);
    }
}
