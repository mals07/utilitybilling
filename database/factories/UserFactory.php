<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state: a verified account owner without a company yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'name' => "{$firstName} {$lastName}",
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password1'),
            'is_admin' => true,
            'last_login_at' => now(),
            'welcomed_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * The email address has not been verified yet.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => ['email_verified_at' => null]);
    }

    /**
     * Just signed up: the welcome page hasn't been seen yet.
     */
    public function notWelcomed(): static
    {
        return $this->state(fn (array $attributes) => ['welcomed_at' => null]);
    }

    /**
     * A non-admin member of the company, with the given role.
     */
    public function memberOf(Company $company, ?Role $role = null): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->id,
            'is_admin' => false,
            'role_id' => $role?->id,
        ]);
    }

    /**
     * Invited but hasn't accepted yet: no username or password.
     */
    public function invited(): static
    {
        return $this->state(fn (array $attributes) => [
            'username' => null,
            'password' => null,
            'email_verified_at' => null,
            'last_login_at' => null,
            'invited_at' => now(),
        ]);
    }
}
