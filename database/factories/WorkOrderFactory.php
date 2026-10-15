<?php

namespace Database\Factories;

use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    /**
     * An In Progress order requested just now.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'order_number' => str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'customer_id' => fn (array $attributes) => Customer::factory()->create(['company_id' => $attributes['company_id']])->id,
            'order_type' => fake()->randomElement(WorkOrderType::cases()),
            'description' => fake()->sentence(),
            'status' => WorkOrderStatus::InProgress,
            'requested_at' => now(),
        ];
    }

    /**
     * Requested the given number of days ago.
     */
    public function requestedDaysAgo(int $days): static
    {
        return $this->state(fn (array $attributes) => ['requested_at' => now()->subDays($days)]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WorkOrderStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
