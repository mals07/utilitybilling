<?php

namespace App\Livewire\Auth;

/**
 * Validation rules and messages shared by the sign-up, login, invitation and password pages.
 */
final class AuthRules
{
    public const EMAIL_PATTERN = '/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/';

    public const PASSWORD_HINT = 'At least 8 characters, including a letter and a number.';

    public const USERNAME_HINT = '3–30 characters: letters, numbers, dots, dashes or underscores.';

    /**
     * @return array{rules: list<string>, messages: array<string, string>}
     */
    public static function email(string $field = 'email'): array
    {
        return [
            'rules' => ['bail', 'required', 'max:150', 'regex:'.self::EMAIL_PATTERN],
            'messages' => [
                "{$field}.required" => 'Email address is required.',
                "{$field}.max" => 'Email address must be 150 characters or fewer.',
                "{$field}.regex" => 'Enter a valid email address.',
            ],
        ];
    }

    /**
     * A new password and its confirmation, in the fields "password" and "confirmPassword".
     *
     * @return array{rules: array<string, list<string>>, messages: array<string, string>}
     */
    public static function newPassword(): array
    {
        return [
            'rules' => [
                'password' => ['bail', 'required', 'min:8', 'max:128', 'regex:/[A-Za-z]/', 'regex:/\d/'],
                'confirmPassword' => ['bail', 'required', 'same:password'],
            ],
            'messages' => [
                'password.required' => 'Password is required.',
                'password.min' => 'Password must be at least 8 characters.',
                'password.max' => 'Password must be 128 characters or fewer.',
                'password.regex' => 'Password must include a letter and a number.',
                'confirmPassword.required' => 'Please confirm your password.',
                'confirmPassword.same' => 'Passwords do not match.',
            ],
        ];
    }

    /**
     * @return array{rules: list<string>, messages: array<string, string>}
     */
    public static function username(): array
    {
        return [
            'rules' => ['bail', 'required', 'min:3', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
            'messages' => [
                'username.required' => 'Username is required.',
                'username.min' => 'Username must be at least 3 characters.',
                'username.max' => 'Username must be 30 characters or fewer.',
                'username.regex' => 'Username may only use letters, numbers, dots, dashes and underscores.',
            ],
        ];
    }
}
