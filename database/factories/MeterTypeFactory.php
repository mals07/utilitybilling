<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\MeterType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeterType>
 */
class MeterTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => ucfirst(fake()->unique()->word()),
            'is_active' => true,
        ];
    }
}
