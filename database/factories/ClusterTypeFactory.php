<?php

namespace Database\Factories;

use App\Models\ClusterType;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClusterType>
 */
class ClusterTypeFactory extends Factory
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
