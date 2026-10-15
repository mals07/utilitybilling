<?php

namespace App\Livewire\Auth;

use App\Enums\AuthTokenType;
use App\Models\User;
use App\Support\AuthEmails;
use App\Support\AuthTokens;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Verify Email')]
class VerifyEmail extends Component
{
    public bool $invalidLink = false;

    /**
     * @var array{tone: string, text: string}|null
     */
    public ?array $status = null;

    public function mount(): void
    {
        if ($this->user()->hasVerifiedEmail()) {
            $this->redirectRoute($this->user()->homeRoute());

            return;
        }

        $this->invalidLink = request()->query('error') === 'invalid';
    }

    public function resend(): void
    {
        $user = $this->user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectRoute($user->homeRoute());

            return;
        }

        $wait = AuthTokens::secondsUntilResendAllowed($user, AuthTokenType::EmailVerification);
        if ($wait > 0) {
            $this->status = ['tone' => 'error', 'text' => "Please wait {$wait} seconds before requesting another email."];

            return;
        }

        $sent = AuthEmails::trySend(fn () => AuthEmails::sendVerification($user), 'verification');

        $this->status = $sent
            ? ['tone' => 'success', 'text' => "A new verification link was sent to {$user->email}."]
            : ['tone' => 'error', 'text' => "We couldn't send the email. Please try again shortly."];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    public function render(): View
    {
        return view('livewire.auth.verify-email', [
            'email' => $this->user()->email,
            'devLink' => AuthEmails::devLink($this->user()->email, AuthTokenType::EmailVerification),
        ]);
    }
}
