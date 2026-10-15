<?php

namespace Database\Factories;

use App\Enums\DistributionType;
use App\Models\ChargeElement;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChargeElement>
 */
class ChargeElementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'distribution_type' => DistributionType::Debit,
            'is_after_due' => false,
        ];
    }
}
