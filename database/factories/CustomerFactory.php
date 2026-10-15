<?php

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Models\AccountType;
use App\Models\ChargeType;
use App\Models\ClusterType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\RateType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city = CompanyFactory::city();

        return [
            'company_id' => Company::factory(),
            'account_number' => str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'status' => CustomerStatus::Pending,
            'account_type_id' => fn (array $attributes) => AccountType::factory()->create(['company_id' => $attributes['company_id']])->id,
            'charge_type_id' => fn (array $attributes) => ChargeType::factory()->create(['company_id' => $attributes['company_id']])->id,
            'rate_type_id' => fn (array $attributes) => RateType::factory()->create(['company_id' => $attributes['company_id']])->id,
            'cluster_type_id' => fn (array $attributes) => ClusterType::factory()->create(['company_id' => $attributes['company_id']])->id,
            'home_address' => fake()->streetAddress(),
            'home_city_id' => $city->id,
            'home_province_id' => $city->province_id,
            'home_country_id' => $city->province->country_id,
            'home_zip_code' => '1000',
        ];
    }
}
