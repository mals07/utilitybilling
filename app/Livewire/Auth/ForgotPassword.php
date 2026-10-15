<?php

namespace App\Livewire\Auth;

use App\Enums\AuthTokenType;
use App\Models\User;
use App\Support\AuthEmails;
use App\Support\AuthTokens;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Forgot Password')]
class ForgotPassword extends Component
{
    public string $email = '';

    public ?string $message = null;

    /**
     * Development only (EMAIL_DELIVERY=console): the link that would have been emailed.
     */
    public ?string $devLink = null;

    public function sendResetLink(): void
    {
        $this->message = null;
        $this->devLink = null;
        $this->email = mb_strtolower(trim($this->email));

        $rules = AuthRules::email();
        $this->validate(['email' => $rules['rules']], $rules['messages']);

        $user = User::query()->where('email', $this->email)->whereNull('deactivated_at')->first();

        if ($user !== null && AuthTokens::secondsUntilResendAllowed($user, AuthTokenType::PasswordReset) === 0) {
            AuthEmails::trySend(fn () => AuthEmails::sendPasswordReset($user), 'password reset');
        }

        // Same response whether or not the account exists.
        $this->message = "If an account exists for {$this->email}, a password reset link has been sent. It expires in 1 hour.";
        $this->devLink = $user ? AuthEmails::devLink($this->email, AuthTokenType::PasswordReset) : null;
    }

    public function render(): View
    {
        return view('livewire.auth.forgot-password');
    }
}
