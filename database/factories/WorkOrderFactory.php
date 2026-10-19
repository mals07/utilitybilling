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
            // A type any customer may have, so the default order never breaks the eligibility rules.
            'order_type' => WorkOrderType::PipeRepair,
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

    /**
     * For the given customer, with its meter.
     */
    public function forCustomer(Customer $customer, WorkOrderType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'order_type' => $type,
            'meter_type_id' => $customer->meter_type_id,
            'meter_number' => $customer->meter_number,
            'serial_number' => $customer->meter_serial_number,
        ]);
    }

    public function onHold(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WorkOrderStatus::OnHold,
            'hold_reason' => 'Waiting for materials',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WorkOrderStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
