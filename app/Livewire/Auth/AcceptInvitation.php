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
#[Title('Accept Invitation')]
class AcceptInvitation extends Component
{
    #[Locked]
    public string $token = '';

    public string $username = '';

    public string $password = '';

    public string $confirmPassword = '';

    public ?string $message = null;

    public function mount(): void
    {
        $this->token = (string) request()->query('token', '');
    }

    /**
     * The invited user, while the link is valid and the account active.
     */
    private function invitedUser(): ?User
    {
        $userId = AuthTokens::peek($this->token, AuthTokenType::Invitation);

        return $userId === null
            ? null
            : User::query()->with('company:id,company_name')->whereNull('deactivated_at')->find($userId);
    }

    public function accept(): void
    {
        $this->message = null;
        $this->username = trim($this->username);

        $username = AuthRules::username();
        $password = AuthRules::newPassword();
        $this->validate(
            ['username' => $username['rules'], ...$password['rules']],
            [...$username['messages'], ...$password['messages']],
        );

        $expired = 'This invitation link is invalid or has expired. Ask your administrator to resend it.';

        // Check the username before using up the token, so a taken name can be corrected.
        $invitedId = AuthTokens::peek($this->token, AuthTokenType::Invitation);
        if ($invitedId === null) {
            $this->message = $expired;

            return;
        }

        $taken = User::query()->where('username', $this->username)->whereKeyNot($invitedId)->exists();
        if ($taken) {
            $this->addError('username', 'This username is already taken. Please choose another.');

            return;
        }

        $userId = AuthTokens::consume($this->token, AuthTokenType::Invitation);
        if ($userId === null) {
            $this->message = $expired;

            return;
        }

        $user = User::query()->whereNull('deactivated_at')->find($userId);
        if ($user === null) {
            $this->message = 'This account has been deactivated. Contact your administrator.';

            return;
        }

        $user->forceFill([
            'username' => $this->username,
            'password' => $this->password,
            // Opening the emailed link proves ownership of the address.
            'email_verified_at' => now(),
            'last_login_at' => now(),
        ])->save();

        Auth::guard('web')->login($user);
        session()->regenerate();

        $this->redirectRoute('dashboard');
    }

    public function render(): View
    {
        return view('livewire.auth.accept-invitation', [
            'invitedUser' => $this->invitedUser(),
            'passwordHint' => AuthRules::PASSWORD_HINT,
            'usernameHint' => AuthRules::USERNAME_HINT,
        ]);
    }
}
