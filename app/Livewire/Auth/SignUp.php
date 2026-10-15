<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Support\AuthEmails;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Sign Up')]
class SignUp extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $confirmPassword = '';

    public function register(): void
    {
        $this->name = trim($this->name);
        $this->email = mb_strtolower(trim($this->email));

        $email = AuthRules::email();
        $password = AuthRules::newPassword();

        $this->validate([
            'name' => ['bail', 'required', 'max:100'],
            'email' => [...$email['rules'], 'unique:users,email'],
            ...$password['rules'],
        ], [
            'name.required' => 'Full name is required.',
            'name.max' => 'Full name must be 100 characters or fewer.',
            'email.unique' => 'An account with this email address already exists.',
            ...$email['messages'],
            ...$password['messages'],
        ]);

        // Signing up starts a new company, so the new user is its admin.
        $user = User::query()->create([
            'name' => $this->name,
            ...User::splitFullName($this->name),
            'email' => $this->email,
            'password' => $this->password,
            'is_admin' => true,
            'last_login_at' => now(),
        ]);

        Auth::guard('web')->login($user);
        session()->regenerate();

        // Email failures shouldn't block sign-up; the user can resend from the verification page.
        AuthEmails::trySend(fn () => AuthEmails::sendVerification($user), 'verification');

        $this->redirectRoute('verify-email');
    }

    public function render(): View
    {
        return view('livewire.auth.sign-up', ['passwordHint' => AuthRules::PASSWORD_HINT]);
    }
}
