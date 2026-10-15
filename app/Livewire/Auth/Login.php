<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Login')]
class Login extends Component
{
    /**
     * "Remember me" keeps the user signed in for 30 days.
     */
    public const REMEMBER_MINUTES = 30 * 24 * 60;

    /**
     * Email address or username.
     */
    public string $login = '';

    public string $password = '';

    public bool $remember = false;

    public ?string $message = null;

    /**
     * @var array{tone: string, text: string}|null
     */
    public ?array $notice = null;

    public function mount(): void
    {
        $this->notice = match (true) {
            request()->query('verified') === '1' => ['tone' => 'success', 'text' => 'Your email address is verified. Log in to continue.'],
            request()->query('reset') === '1' => ['tone' => 'success', 'text' => 'Your password was updated. Log in with your new password.'],
            request()->query('verify') === 'invalid' => ['tone' => 'error', 'text' => 'That verification link is invalid or has expired. Log in to request a new one.'],
            default => null,
        };
    }

    public function authenticate(): void
    {
        $this->message = null;
        $this->login = trim($this->login);

        $this->validate([
            'login' => ['bail', 'required', 'max:150'],
            'password' => ['required'],
        ], [
            'login.required' => 'Email address or username is required.',
            'login.max' => 'Email address or username must be 150 characters or fewer.',
            'password.required' => 'Password is required.',
        ]);

        // Anything with an @ is an email address; usernames can't contain one.
        $user = str_contains($this->login, '@')
            ? User::query()->where('email', mb_strtolower($this->login))->first()
            : User::query()->where('username', $this->login)->first();

        // Invited users have no password until they accept, so they can't log in yet.
        // Unknown accounts still check a hash, so they take as long as known ones.
        $passwordOk = Hash::check($this->password, $user?->password ?? Hash::make('not-a-real-password'));

        if ($user === null || $user->password === null || ! $passwordOk) {
            $this->message = 'Incorrect email address, username or password.';

            return;
        }

        if ($user->isDeactivated()) {
            $this->message = 'This account has been deactivated. Contact your administrator to restore access.';

            return;
        }

        $user->forceFill(['last_login_at' => now()])->save();

        Auth::guard('web')->setRememberDuration(self::REMEMBER_MINUTES);
        Auth::guard('web')->login($user, $this->remember);
        session()->regenerate();

        $this->redirectRoute($user->homeRoute());
    }

    public function render(): View
    {
        return view('livewire.auth.login');
    }
}
