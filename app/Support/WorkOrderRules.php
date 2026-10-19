<?php

namespace App\Support;

use App\Enums\CustomerStatus;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Models\Customer;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;

/**
 * Which order types make sense for a customer right now. Checked when an order is submitted,
 * when a follow-up is created, and again when an order is completed.
 */
final class WorkOrderRules
{
    /**
     * Each order type with the reason it can't be used for the customer, or null when it can.
     *
     * @return array<string, ?string> Keyed by WorkOrderType value.
     */
    public static function eligibility(Customer $customer, ?int $exceptOrderId = null): array
    {
        $open = self::openOrders($customer, $exceptOrderId);

        $reasons = [];
        foreach (WorkOrderType::cases() as $type) {
            $reasons[$type->value] = self::reasonFor($type, $customer, $open);
        }

        return $reasons;
    }

    /**
     * Why the order type can't be used for the customer, or null when it can.
     */
    public static function whyNot(WorkOrderType $type, Customer $customer, ?int $exceptOrderId = null): ?string
    {
        return self::reasonFor($type, $customer, self::openOrders($customer, $exceptOrderId));
    }

    /**
     * @return Collection<int, WorkOrder>
     */
    private static function openOrders(Customer $customer, ?int $exceptOrderId): Collection
    {
        return $customer->workOrders()
            ->whereIn('status', WorkOrderStatus::openValues())
            ->when($exceptOrderId !== null, fn ($query) => $query->whereKeyNot($exceptOrderId))
            ->get(['id', 'order_number', 'order_type']);
    }

    /**
     * @param  Collection<int, WorkOrder>  $open
     */
    private static function reasonFor(WorkOrderType $type, Customer $customer, Collection $open): ?string
    {
        $sameType = $open->first(fn (WorkOrder $order): bool => $order->order_type === $type);
        if ($sameType !== null) {
            return "work order {$sameType->order_number} is already open";
        }

        $opposite = match ($type) {
            WorkOrderType::Disconnection => WorkOrderType::Reconnection,
            WorkOrderType::Reconnection => WorkOrderType::Disconnection,
            default => null,
        };
        $conflict = $opposite === null ? null : $open->first(fn (WorkOrder $order): bool => $order->order_type === $opposite);
        if ($conflict !== null) {
            return "{$opposite->label()} {$conflict->order_number} is still open";
        }

        return match (true) {
            $type === WorkOrderType::NewInstallation && $customer->hasMeter() => 'customer already has a meter',
            $type === WorkOrderType::NewInstallation && $customer->status !== CustomerStatus::Pending => 'customer is not pending',
            $type === WorkOrderType::Disconnection && $customer->status !== CustomerStatus::Connected => 'customer is not connected',
            $type === WorkOrderType::Reconnection && $customer->status !== CustomerStatus::Disconnected => 'customer is not disconnected',
            $type->needsMeter() && ! $customer->hasMeter() => 'customer has no meter yet',
            default => null,
        };
    }
}
