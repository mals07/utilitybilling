<?php

namespace Database\Factories;

use App\Models\RateRange;
use App\Models\RateType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RateRange>
 */
class RateRangeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rate_type_id' => RateType::factory(),
            'range_from' => 0,
            'range_to' => 10,
            'rate' => '12.50',
            'description' => 'First block',
        ];
    }
}
