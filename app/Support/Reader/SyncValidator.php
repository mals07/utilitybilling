<?php

namespace App\Support\Reader;

use App\Enums\DiscrepancySeverity;
use App\Enums\ReadingCode;
use App\Enums\SyncCheck;
use App\Enums\SyncItemStatus;
use App\Models\Customer;
use App\Models\MeterReading;
use App\Models\ReadingAssignment;
use App\Models\SyncBatch;
use App\Models\SyncItem;
use App\Support\Billing\BillCalculator;
use App\Support\Billing\BillingException;
use App\Support\Billing\BillingRun;
use App\Support\Billing\MeterReadings;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Compares a reading uploaded from a device (and the bill printed for it) with the live data, as it
 * is now rather than when the device downloaded its route. Blocking differences hold the item for a
 * supervisor; Info differences are only logged.
 *
 * @phpstan-type Finding array{check: SyncCheck, severity: DiscrepancySeverity, message: string, device_value: ?string, server_value: ?string}
 */
final class SyncValidator
{
    /**
     * @return list<Finding>
     */
    public static function check(SyncItem $item, SyncBatch $batch): array
    {
        $payload = $item->payload;
        $device = $batch->device;
        $company = $device->company;
        $customer = Customer::query()->find($item->customer_id);
        $period = (string) $item->billing_period;
        $bill = $payload['bill'] ?? null;
        $findings = [];

        if ($customer === null) {
            return [self::blocking(SyncCheck::NotBillable, 'The customer no longer exists.')];
        }
        $customer->setRelation('company', $company);

        if ($device->isRevoked()) {
            $findings[] = self::blocking(SyncCheck::DeviceSignedOut, "{$device->name} was signed out before this upload. Check the reading before accepting it.");
        }

        $assigned = ReadingAssignment::query()
            ->where('billing_period', $period)
            ->where('cluster_type_id', $customer->cluster_type_id)
            ->where('user_id', $device->user_id)
            ->exists();
        if (! $assigned) {
            $findings[] = self::blocking(SyncCheck::Assignment, "The customer's cluster isn't assigned to {$device->user->name} for ".BillingRun::periodLabel($period).'.');
        }

        $findings = [...$findings, ...self::clockFindings($item, $batch)];

        if ($bill !== null && NumberBlocks::freeBlockFor($device, (string) $bill['invoice_number']) === null) {
            $findings[] = self::blocking(SyncCheck::InvoiceNumber, 'The invoice number isn\'t one reserved for this device, or it is already used.', $bill['invoice_number']);
        }

        $other = MeterReading::query()->where('customer_id', $customer->id)->where('billing_period', $period)->first();
        $existingBill = BillingRun::existingBill($customer, $period);
        $heldElsewhere = SyncItem::query()
            ->where('customer_id', $customer->id)
            ->where('billing_period', $period)
            ->where('status', SyncItemStatus::Held)
            ->whereKeyNot($item->id)
            ->exists();
        if ($existingBill !== null) {
            $findings[] = self::blocking(SyncCheck::DuplicateReading, "Already billed for this period (invoice {$existingBill->invoice_number}).", null, $existingBill->invoice_number);
        } elseif ($other !== null) {
            $findings[] = self::blocking(SyncCheck::DuplicateReading, 'A reading for this period was already saved'.($other->device_id !== null ? ' from another device.' : ' in the office.'), $payload['present_reading'] ?? null, $other->present_reading);
        } elseif ($heldElsewhere) {
            $findings[] = self::blocking(SyncCheck::DuplicateReading, 'Another reading of this customer for the period is waiting for review.');
        }

        if (($payload['meter_number'] ?? null) !== $customer->meter_number) {
            $findings[] = self::blocking(SyncCheck::MeterChanged, 'The customer\'s meter was changed since the route was downloaded.', $payload['meter_number'] ?? null, $customer->meter_number);
        }

        if (! MeterReadings::customers($company, $period, null, '')->whereKey($customer->id)->exists()) {
            $findings[] = self::blocking(SyncCheck::NotBillable, "The customer is {$customer->status->label()} and isn't billed for this period.");
        }

        try {
            $evaluated = MeterReadings::evaluate($customer, $period, (string) ($payload['present_reading'] ?? ''), ReadingCode::from($payload['reading_code']));
        } catch (BillingException $exception) {
            return [...$findings, self::blocking(SyncCheck::Billing, $exception->getMessage())];
        }

        if (round((float) $payload['previous_reading'], 3) !== round($evaluated['previous'], 3)) {
            $findings[] = self::blocking(SyncCheck::History, 'The previous reading changed since the route was downloaded.', BillCalculator::quantity((float) $payload['previous_reading']), BillCalculator::quantity($evaluated['previous']));
        }

        $flagged = $evaluated['flags'] !== [];
        if ($flagged !== (($payload['flags'] ?? []) !== [])) {
            $findings[] = self::blocking(SyncCheck::Flags, 'The device and the server disagree on whether the reading needs review.', implode(' ', $payload['flags'] ?? []) ?: 'none', implode(' ', $evaluated['flags']) ?: 'none');
        } elseif ($flagged && $bill !== null && ! (($payload['confirmed_by_reader'] ?? false) && $company->readers_confirm_flagged)) {
            $findings[] = self::blocking(SyncCheck::Flags, 'A bill was printed for a reading that needs review without the reader confirming it.', implode(' ', $payload['flags']));
        }

        if (($payload['config_hash'] ?? null) !== $batch->routePackage?->config_hash
            || $batch->routePackage?->config_hash !== RoutePackages::configHash($company, $device, $period)) {
            $findings[] = self::info(SyncCheck::Config, 'Rates, charges or billing settings changed since the route was downloaded.');
        }

        if ($bill === null) {
            return $findings;
        }

        try {
            $server = BillingRun::bill($customer, $evaluated['usage'], (string) $bill['invoice_date']);
        } catch (BillingException $exception) {
            return [...$findings, self::blocking(SyncCheck::Billing, $exception->getMessage())];
        }

        if (self::lineKey($bill['lines']) !== self::lineKey($server['lines']) || (int) $bill['total_cents'] !== $server['totalCents']) {
            $findings[] = self::blocking(
                SyncCheck::Amount,
                'The printed bill differs from the bill worked out now.',
                Money::fromCents((int) $bill['total_cents']),
                Money::fromCents($server['totalCents']),
            );
        }

        if ((int) ($bill['previous_balance_cents'] ?? 0) !== $server['previousBalanceCents']) {
            $findings[] = self::info(
                SyncCheck::PreviousBalance,
                'The previous balance changed since the route was downloaded (e.g. a payment was posted). The invoice keeps the current balance.',
                Money::fromCents((int) ($bill['previous_balance_cents'] ?? 0)),
                Money::fromCents($server['previousBalanceCents']),
            );
        }

        $distance = self::distanceFromMeter($customer, $payload);
        $limit = $company->reader_gps_limit_meters;
        if ($limit !== null && $distance !== null && $distance > $limit) {
            $findings[] = self::info(SyncCheck::Location, 'The reading was taken '.number_format($distance).' m from the customer\'s map location.', number_format($distance).' m', 'within '.number_format($limit).' m');
        }

        return $findings;
    }

    /**
     * The device's clock must be close to the server's when it syncs, and the reading taken after the
     * route was downloaded, within the billing period or later.
     *
     * @return list<Finding>
     */
    private static function clockFindings(SyncItem $item, SyncBatch $batch): array
    {
        $company = $batch->device->company;
        $drift = (int) $company->reader_clock_drift_minutes;
        $readAt = Carbon::parse($item->payload['read_at']);
        $findings = [];

        $offBy = (int) abs($batch->device_time->diffInMinutes($batch->created_at));
        if ($offBy > $drift) {
            $findings[] = self::blocking(SyncCheck::Clock, "The device's clock was {$offBy} minutes off when it synced.", $batch->device_time->toIso8601String(), $batch->created_at->toIso8601String());
        }

        $periodStart = Carbon::createFromFormat('Y-m-d H:i:s', $item->billing_period.'-01 00:00:00', $company->timezone ?: config('app.timezone'));
        $downloaded = $batch->routePackage?->as_of;
        if ($readAt->lt($periodStart)
            || $readAt->gt($batch->device_time->copy()->addMinutes(1))
            || ($downloaded !== null && $readAt->lt($downloaded->copy()->subMinutes($drift)))) {
            $findings[] = self::blocking(SyncCheck::Clock, 'The reading time is outside the billing period or before the route was downloaded.', $readAt->toIso8601String());
        }

        return $findings;
    }

    /**
     * Lines compared by what they bill, not by ids: kind, description and amount, in order.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<string>
     */
    private static function lineKey(array $lines): array
    {
        return array_map(
            fn (array $line): string => (is_string($line['kind']) ? $line['kind'] : $line['kind']->value).'|'.$line['description'].'|'.(int) $line['cents'],
            array_values($lines),
        );
    }

    /**
     * Meters between where the reading was taken and the customer's map location, when both are known.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function distanceFromMeter(Customer $customer, array $payload): ?float
    {
        if ($customer->latitude === null || $customer->longitude === null || ! isset($payload['latitude'], $payload['longitude'])) {
            return null;
        }

        $toRadians = fn (float $degrees): float => $degrees * M_PI / 180;
        $latitudeDelta = $toRadians((float) $payload['latitude'] - (float) $customer->latitude);
        $longitudeDelta = $toRadians((float) $payload['longitude'] - (float) $customer->longitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos($toRadians((float) $customer->latitude)) * cos($toRadians((float) $payload['latitude'])) * sin($longitudeDelta / 2) ** 2;

        return 6_371_000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @return Finding
     */
    private static function blocking(SyncCheck $check, string $message, ?string $deviceValue = null, ?string $serverValue = null): array
    {
        return ['check' => $check, 'severity' => DiscrepancySeverity::Blocking, 'message' => $message, 'device_value' => $deviceValue, 'server_value' => $serverValue];
    }

    /**
     * @return Finding
     */
    private static function info(SyncCheck $check, string $message, ?string $deviceValue = null, ?string $serverValue = null): array
    {
        return ['check' => $check, 'severity' => DiscrepancySeverity::Info, 'message' => $message, 'device_value' => $deviceValue, 'server_value' => $serverValue];
    }
}
