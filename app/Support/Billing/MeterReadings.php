<?php

namespace App\Support\Billing;

use App\Enums\CustomerStatus;
use App\Enums\MeterReadingStatus;
use App\Enums\ReadingCode;
use App\Models\Company;
use App\Models\Customer;
use App\Models\MeterReading;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Monthly meter readings: works out the usage of a reading, flags readings that look wrong, and
 * saves them for Generate Bills.
 *
 * - Usage = present − previous, plus usage on a meter replaced since the last bill.
 * - Rolled over (the meter passed 9999 and started again at 0): (9999 + 1 − previous) + present.
 * - No access / defective meter: estimated from the 3-month average or the rate's minimum
 *   consumption, as set on the Rates page. The next actual reading deducts what was estimated.
 * - A reading needs review when there was no usage, when usage is more than the company's
 *   multiplier × the 3-month average, when the meter rolled over, or when the actual usage came in
 *   below what was estimated. It is billed only after someone confirms it.
 */
final class MeterReadings
{
    /**
     * Works out a reading without saving it.
     *
     * @return array{previous: float, present: ?float, replaced: float, deducted: float, usage: float, estimated: bool, average: ?float, flags: list<string>}
     *
     * @throws BillingException
     */
    public static function evaluate(Customer $customer, string $period, string $present, ReadingCode $code): array
    {
        $customer->loadMissing(['rateType.setup', 'meterType', 'company']);
        if ($customer->rateType === null) {
            throw new BillingException('The customer has no rate. Set one on the Customers page.');
        }

        $history = MeterHistory::for($customer, $period);
        $previous = $history['previous'];
        $replaced = $history['replacedConsumption'];
        $recent = $history['recentConsumptions'];
        $average = $recent === [] ? null : round(array_sum($recent) / count($recent), 3);

        if ($code->isEstimated()) {
            return [
                'previous' => $previous,
                'present' => null,
                'replaced' => $replaced,
                'deducted' => 0.0,
                'usage' => round(self::estimate($customer, $average) + $replaced, 3),
                'estimated' => true,
                'average' => $average,
                'flags' => [],
            ];
        }

        $present = trim($present);
        if ($present === '') {
            throw new BillingException('Enter the present reading, or mark the meter No access or Defective.');
        }
        if (! preg_match(BillingRun::READING_PATTERN, $present)) {
            throw new BillingException('Enter the reading as a number, such as 1234 or 1234.5.');
        }

        $presentValue = (float) $present;
        $highest = $customer->meterType?->highestReading();
        $flags = [];

        if ($highest !== null && ($presentValue > $highest || $previous > $highest)) {
            throw new BillingException("A {$customer->meterType->dial_digits}-dial meter can't read above ".number_format($highest).'. Check the reading, or the dials of the meter type in Operation Setup.');
        }

        if ($code === ReadingCode::Rollover) {
            if ($highest === null) {
                throw new BillingException('Set the number of dials of the customer\'s meter type in Operation Setup to bill a rolled-over meter.');
            }
            if ($presentValue >= $previous) {
                throw new BillingException('The reading isn\'t lower than the previous one, so the meter didn\'t roll over. Save it as a normal reading.');
            }
            $measured = $highest + 1 - $previous + $presentValue;
            $flags[] = 'The meter rolled over past '.number_format($highest).'.';
        } else {
            if ($presentValue < $previous) {
                throw new BillingException(
                    'The present reading ('.BillCalculator::quantity($presentValue).') is lower than the previous reading ('.BillCalculator::quantity($previous).'). '
                    .($highest !== null ? 'Check the reading, or save it as a rolled-over meter.' : 'Check the reading, or mark the meter Defective.'),
                );
            }
            $measured = $presentValue - $previous;
        }
        $measured += $replaced;

        $estimatedSince = $history['estimatedSince'];
        $deducted = min($estimatedSince, $measured);
        $usage = round($measured - $deducted, 3);
        if ($estimatedSince > $measured) {
            $flags[] = 'Actual usage ('.BillCalculator::quantity($measured).' cu.m.) is less than the '.BillCalculator::quantity($estimatedSince).' cu.m. estimated since the last reading.';
        }

        $company = $customer->company;
        $multiplier = (float) $company->review_usage_multiplier;
        if ($usage <= 0 && $company->review_zero_usage) {
            $flags[] = 'No usage since the last reading.';
        } elseif ($multiplier > 0 && $average !== null && $average > 0 && $usage > $average * $multiplier) {
            $flags[] = BillCalculator::quantity($usage).' cu.m. is '.number_format($usage / $average, 1).'× the 3-month average of '.BillCalculator::quantity($average).' cu.m.';
        }

        return [
            'previous' => $previous,
            'present' => $presentValue,
            'replaced' => $replaced,
            'deducted' => round($deducted, 3),
            'usage' => $usage,
            'estimated' => false,
            'average' => $average,
            'flags' => $flags,
        ];
    }

    /**
     * Usage billed when the meter can't be read: the 3-month average when the rate allows it and
     * there is history, otherwise the rate's minimum consumption.
     *
     * @throws BillingException
     */
    private static function estimate(Customer $customer, ?float $average): float
    {
        $setup = $customer->rateType->setup;

        if ($setup?->defective_uses_three_month_average && $average !== null) {
            return $average;
        }
        if ($setup?->defective_uses_minimum_consumption && $setup->minimum_consumption !== null) {
            return (float) $setup->minimum_consumption;
        }

        throw new BillingException("The {$customer->rateType->name} rate has no estimate for this customer. Set a defective-meter computation on the Rates page, or enter a reading.");
    }

    /**
     * Saves the customer's reading for the period, replacing an unbilled one. It is Ready to bill,
     * or Needs review when it looks wrong (unless $confirmed, when the user already accepted it).
     *
     * @throws BillingException
     */
    public static function save(Customer $customer, string $period, string $present, ReadingCode $code, string $readOn, User $actor, bool $confirmed = false): MeterReading
    {
        return DB::transaction(function () use ($customer, $period, $present, $code, $readOn, $actor, $confirmed): MeterReading {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($customer->meter_number === null) {
                throw new BillingException('The customer has no meter.');
            }

            if ($bill = BillingRun::existingBill($customer, $period)) {
                throw new BillingException("Already billed for this period (invoice {$bill->invoice_number}). Void the invoice to correct the reading.");
            }

            $unbilledEarlier = MeterReading::query()
                ->where('customer_id', $customer->id)
                ->where('billing_period', '<', $period)
                ->whereNull('invoice_id')
                ->orderBy('billing_period')
                ->value('billing_period');
            if ($unbilledEarlier !== null) {
                throw new BillingException('The '.BillingRun::periodLabel($unbilledEarlier).' reading isn\'t billed yet. Bill it on Generate Bills first.');
            }

            $reading = self::evaluate($customer, $period, $present, $code);
            $minimum = (float) ($customer->rateType->setup?->minimum_consumption ?? 0);
            $needsReview = $reading['flags'] !== [] && ! $confirmed;

            return MeterReading::query()->updateOrCreate(
                ['customer_id' => $customer->id, 'billing_period' => $period],
                [
                    'company_id' => $customer->company_id,
                    'meter_number' => $customer->meter_number,
                    'status' => $needsReview ? MeterReadingStatus::Review : MeterReadingStatus::Ready,
                    'reading_code' => $code,
                    'previous_reading' => $reading['previous'],
                    'present_reading' => $reading['present'],
                    'consumption' => round(max($reading['usage'], $minimum), 3),
                    'actual_consumption' => $reading['usage'],
                    'replaced_meter_consumption' => $reading['replaced'],
                    'estimate_deducted' => $reading['deducted'],
                    'is_defective' => $code === ReadingCode::Defective,
                    'review_reason' => $reading['flags'] === [] ? null : mb_substr(implode(' ', $reading['flags']), 0, 500),
                    'read_on' => $readOn,
                    'created_by_id' => $actor->id,
                    'confirmed_by_id' => $reading['flags'] !== [] && $confirmed ? $actor->id : null,
                    'confirmed_at' => $reading['flags'] !== [] && $confirmed ? now() : null,
                ],
            );
        });
    }

    /**
     * Accepts a reading that needs review, so it can be billed.
     *
     * @throws BillingException
     */
    public static function confirm(MeterReading $reading, User $actor): void
    {
        DB::transaction(function () use ($reading, $actor): void {
            $reading = self::lock($reading);

            if ($reading->status !== MeterReadingStatus::Review) {
                throw new BillingException('This reading doesn\'t need review.');
            }

            $reading->update(['status' => MeterReadingStatus::Ready, 'confirmed_by_id' => $actor->id, 'confirmed_at' => now()]);
        });
    }

    /**
     * Deletes an unbilled reading, so the meter can be read again.
     *
     * @throws BillingException
     */
    public static function remove(MeterReading $reading): void
    {
        DB::transaction(function () use ($reading): void {
            $reading = self::lock($reading);

            if ($reading->isBilled()) {
                throw new BillingException('This reading is already billed. Void the invoice to correct it.');
            }

            $reading->delete();
        });
    }

    /**
     * The reading as it is now, locked until the transaction ends, so a bill or another user's
     * change made a moment ago is seen and none can start meanwhile.
     *
     * @throws BillingException When someone deleted the reading.
     */
    private static function lock(MeterReading $reading): MeterReading
    {
        return MeterReading::query()->lockForUpdate()->find($reading->id)
            ?? throw new BillingException('That reading no longer exists. The page has been refreshed.');
    }

    /**
     * Customers to read for the period: Connected with a meter, Disconnected with a final reading
     * not billed yet, and anyone who already has a reading for the period.
     *
     * @return Builder<Customer>
     */
    public static function customers(Company $company, string $period, ?int $clusterId, string $search): Builder
    {
        return Customer::query()
            ->where('customers.company_id', $company->id)
            ->whereNotNull('meter_number')
            ->where(fn (Builder $query) => $query
                ->where('status', CustomerStatus::Connected)
                ->orWhereHas('meterReadings', fn (Builder $readings) => $readings->where('billing_period', $period))
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', CustomerStatus::Disconnected)
                    ->whereExists(fn (QueryBuilder $final) => $final->selectRaw('1')
                        ->from('work_order_readings')
                        ->whereColumn('work_order_readings.customer_id', 'customers.id')
                        ->whereColumn('work_order_readings.meter_number', 'customers.meter_number')
                        ->where('work_order_readings.kind', 'final')
                        ->whereNotExists(fn (QueryBuilder $billed) => $billed->selectRaw('1')
                            ->from('meter_readings')
                            ->whereColumn('meter_readings.customer_id', 'customers.id')
                            ->whereNotNull('meter_readings.invoice_id')
                            ->where('meter_readings.billing_period', '<', $period)
                            ->whereColumn('meter_readings.read_on', '>=', 'work_order_readings.read_on')))))
            ->when($clusterId !== null, fn (Builder $query) => $query->where('cluster_type_id', $clusterId))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('account_number', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('meter_number', 'like', "%{$search}%")));
    }
}
