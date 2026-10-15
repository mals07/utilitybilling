<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Company;
use App\Models\Country;
use App\Models\Province;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city = self::city();

        return [
            'user_id' => User::factory(),
            'company_name' => fake()->company(),
            'address' => fake()->streetAddress(),
            'city_id' => $city->id,
            'province_id' => $city->province_id,
            'country_id' => $city->province->country_id,
            'zip_code' => '1000',
            'email' => fake()->companyEmail(),
            'head_of_company' => fake()->name(),
            'point_person_first_name' => fake()->firstName(),
            'point_person_last_name' => fake()->lastName(),
            'point_person_contact' => '09171234567',
            'point_person_email' => fake()->safeEmail(),
            'due_date_days' => 5,
            'timezone' => 'Asia/Manila',
        ];
    }

    /**
     * The owner works in the company they created.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Company $company): void {
            $company->user()->update(['company_id' => $company->id]);
        });
    }

    /**
     * A city in Metro Manila, created on first use.
     */
    public static function city(): City
    {
        $country = Country::query()->firstOrCreate(['code' => 'PH'], ['name' => 'Philippines']);
        $province = Province::query()->firstOrCreate(['country_id' => $country->id, 'name' => 'Metro Manila']);

        return City::query()->with('province')->firstOrCreate(['province_id' => $province->id, 'name' => 'Manila']);
    }
}
