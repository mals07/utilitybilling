<?php

namespace App\Support\Billing;

use App\Enums\ReadingKind;
use App\Models\Customer;
use App\Models\MeterReading;
use App\Models\WorkOrderReading;

/**
 * Where a customer's meter stands for a billing period: the reading to bill from, usage on a meter
 * replaced since the last bill, and a disconnection's final reading not billed yet.
 */
final class MeterHistory
{
    /**
     * @return array{previous: float, replacedConsumption: float, finalReading: ?float, recentConsumptions: list<float>}
     */
    public static function for(Customer $customer, string $period): array
    {
        $meter = $customer->meter_number;

        // Billed readings of earlier periods, latest first.
        $billed = MeterReading::query()
            ->where('customer_id', $customer->id)
            ->where('billing_period', '<', $period)
            ->whereNotNull('invoice_id')
            ->orderByDesc('billing_period')
            ->get();

        $lastRead = $billed->first(fn (MeterReading $reading): bool => $reading->present_reading !== null);

        $workOrderReadings = fn (string $meterNumber) => WorkOrderReading::query()
            ->where('customer_id', $customer->id)
            ->where('meter_number', $meterNumber);

        // Bill from the last billed reading of this meter, or from its first reading when it is new.
        if ($lastRead !== null && $lastRead->meter_number === $meter) {
            $previous = (float) $lastRead->present_reading;
        } else {
            $previous = $meter === null ? 0.0 : (float) ($workOrderReadings($meter)
                ->where('kind', ReadingKind::Initial)
                ->orderBy('read_on')->orderBy('id')
                ->value('reading') ?? 0);
        }

        // A meter replaced since the last bill: its usage up to its final reading is billed now.
        $replaced = 0.0;
        if ($lastRead !== null && $lastRead->meter_number !== null && $lastRead->meter_number !== $meter) {
            $oldFinal = $workOrderReadings($lastRead->meter_number)->where('kind', ReadingKind::Final)->max('reading');
            if ($oldFinal !== null) {
                $replaced = max((float) $oldFinal - (float) $lastRead->present_reading, 0);
            }
        }

        // A disconnection's final reading of this meter, taken after the last bill.
        $final = $meter === null ? null : $workOrderReadings($meter)
            ->where('kind', ReadingKind::Final)
            ->when($lastRead !== null, fn ($query) => $query->where('read_on', '>=', $lastRead->read_on))
            ->where('reading', '>', $previous)
            ->max('reading');

        return [
            'previous' => $previous,
            'replacedConsumption' => $replaced,
            'finalReading' => $final === null ? null : (float) $final,
            'recentConsumptions' => $billed->take(3)->map(fn (MeterReading $reading): float => (float) $reading->consumption)->values()->all(),
        ];
    }
}
