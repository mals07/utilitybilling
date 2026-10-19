<?php

namespace App\Support\Billing;

use App\Enums\CustomerStatus;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceLineKind;
use App\Enums\InvoiceStatus;
use App\Enums\OverdueHandling;
use App\Enums\WorkOrderStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MeterReading;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The monthly billing run: works out each customer's bill from their reading and issues it.
 *
 * @phpstan-import-type Line from BillCalculator
 */
final class BillingRun
{
    public const READING_PATTERN = '/^\d{1,9}(\.\d{1,3})?$/';

    /**
     * Customers to bill for the period: Connected with a meter, or Disconnected with a final reading
     * not billed yet. Those already billed for the period are included and shown as billed.
     *
     * @return Builder<Customer>
     */
    public static function candidates(Company $company, ?int $clusterId, string $search): Builder
    {
        return Customer::query()
            ->where('company_id', $company->id)
            ->whereNotNull('meter_number')
            ->whereIn('status', [CustomerStatus::Connected, CustomerStatus::Disconnected])
            ->when($clusterId !== null, fn (Builder $query) => $query->where('cluster_type_id', $clusterId))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('account_number', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('meter_number', 'like', "%{$search}%")))
            ->orderBy('account_number');
    }

    /**
     * The customer's monthly bill for the period that is not void, if any.
     */
    public static function existingBill(Customer $customer, string $period): ?Invoice
    {
        return $customer->invoices()
            ->where('kind', InvoiceKind::Monthly)
            ->where('billing_period', $period)
            ->where('status', '!=', InvoiceStatus::Void)
            ->first();
    }

    /**
     * Works out the bill without saving anything.
     *
     * @return array{previous: float, present: ?float, replaced: float, consumption: float, billed: float, minimumApplied: bool, estimated: bool, lines: list<Line>, totalCents: int, previousBalanceCents: int, overdue: Collection<int, Invoice>, fees: Collection<int, WorkOrder>}
     *
     * @throws BillingException
     */
    public static function compute(Customer $customer, string $period, string $present, bool $defective, string $invoiceDate): array
    {
        $customer->loadMissing(['rateType.ranges', 'rateType.setup', 'chargeType.elements.chargeElement', 'company']);
        $rate = $customer->rateType ?? throw new BillingException('The customer has no rate. Set one on the Customers page.');
        $history = MeterHistory::for($customer, $period);
        $previous = $history['previous'];
        $replaced = $history['replacedConsumption'];
        $estimated = false;

        if ($defective) {
            $presentValue = null;
            $consumption = self::defectiveEstimate($customer, $history['recentConsumptions']) + $replaced;
            $estimated = true;
        } else {
            if ($present === '') {
                throw new BillingException('Enter the present reading, or tick Defective meter.');
            }
            if (! preg_match(self::READING_PATTERN, $present)) {
                throw new BillingException('Enter the reading as a number, such as 1234 or 1234.5.');
            }
            $presentValue = (float) $present;
            if ($presentValue < $previous) {
                throw new BillingException('The present reading ('.BillCalculator::quantity($presentValue).') is lower than the previous reading ('.BillCalculator::quantity($previous).'). Check the reading, or tick Defective meter.');
            }
            $consumption = $presentValue - $previous + $replaced;
        }

        $water = BillCalculator::water($rate, $consumption);
        $waterCents = array_sum(array_column($water['lines'], 'cents'));
        $lines = [...$water['lines'], ...BillCalculator::charges($customer->chargeType, $waterCents)];

        $fees = self::unbilledFees($customer);
        foreach ($fees as $order) {
            $lines[] = [
                'kind' => InvoiceLineKind::WorkOrderFee,
                'description' => "{$order->order_type->label()} fee (work order {$order->order_number})",
                'quantity' => null,
                'rate' => null,
                'cents' => Money::toCents($order->fee_amount),
                'work_order_id' => $order->id,
            ];
        }

        $overdue = collect();
        if ($customer->company->overdue_handling === OverdueHandling::NextBill) {
            $overdue = self::unsurchargedOverdue($customer, $invoiceDate);
            foreach ($overdue as $invoice) {
                array_push($lines, ...BillCalculator::surcharges(
                    $customer->chargeType,
                    Money::toCents($invoice->balance),
                    "on overdue invoice {$invoice->invoice_number}",
                    $invoice->id,
                ));
            }
        }

        return [
            'previous' => $previous,
            'present' => $presentValue,
            'replaced' => $replaced,
            'consumption' => $consumption,
            'billed' => $water['billed'],
            'minimumApplied' => $water['minimumApplied'],
            'estimated' => $estimated,
            'lines' => $lines,
            'totalCents' => max(array_sum(array_column($lines, 'cents')), 0),
            'previousBalanceCents' => Money::toCents($customer->invoices()->whereIn('status', InvoiceStatus::openValues())->sum('balance')),
            'overdue' => $overdue,
            'fees' => $fees,
        ];
    }

    /**
     * Consumption billed for a defective meter: the average of the last three billed months when the
     * rate allows it and there is history, otherwise the rate's minimum consumption.
     *
     * @param  list<float>  $recent
     */
    private static function defectiveEstimate(Customer $customer, array $recent): float
    {
        $setup = $customer->rateType->setup;

        if ($setup?->defective_uses_three_month_average && $recent !== []) {
            return round(array_sum($recent) / count($recent), 3);
        }
        if ($setup?->defective_uses_minimum_consumption && $setup->minimum_consumption !== null) {
            return (float) $setup->minimum_consumption;
        }

        throw new BillingException("The {$customer->rateType->name} rate has no defective-meter computation for this customer. Set one on the Rates page, or enter a reading.");
    }

    /**
     * Completed work orders whose fee hasn't been billed yet.
     *
     * @return Collection<int, WorkOrder>
     */
    public static function unbilledFees(Customer $customer): Collection
    {
        return $customer->workOrders()
            ->where('status', WorkOrderStatus::Completed)
            ->whereNotNull('fee_amount')
            ->where('fee_amount', '>', 0)
            ->whereNull('fee_invoice_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * Open invoices past due on $date that haven't been surcharged yet.
     *
     * @return Collection<int, Invoice>
     */
    public static function unsurchargedOverdue(Customer $customer, string $date): Collection
    {
        return $customer->invoices()
            ->whereIn('status', InvoiceStatus::openValues())
            ->whereDate('due_date', '<', $date)
            ->whereNull('surcharged_at')
            ->orderBy('due_date')
            ->get();
    }

    /**
     * Issues the customer's bill for the period.
     *
     * @throws BillingException
     */
    public static function generate(Customer $customer, string $period, string $present, bool $defective, string $invoiceDate, string $readOn, User $actor): Invoice
    {
        return DB::transaction(function () use ($customer, $period, $present, $defective, $invoiceDate, $readOn, $actor): Invoice {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            if ($existing = self::existingBill($customer, $period)) {
                throw new BillingException("Already billed for this period (invoice {$existing->invoice_number}).");
            }

            $bill = self::compute($customer, $period, $present, $defective, $invoiceDate);
            $company = $customer->company;

            $reading = MeterReading::query()->updateOrCreate(
                ['customer_id' => $customer->id, 'billing_period' => $period],
                [
                    'company_id' => $company->id,
                    'meter_number' => $customer->meter_number,
                    'previous_reading' => $bill['previous'],
                    'present_reading' => $bill['present'],
                    'consumption' => round($bill['billed'], 3),
                    'replaced_meter_consumption' => $bill['replaced'],
                    'is_defective' => $defective,
                    'read_on' => $readOn,
                    'created_by_id' => $actor->id,
                ],
            );

            $invoice = self::issue($company, $customer, [
                'kind' => InvoiceKind::Monthly,
                'billing_period' => $period,
                'meter_reading_id' => $reading->id,
                'invoice_date' => $invoiceDate,
                'previous_balance' => Money::fromCents($bill['previousBalanceCents']),
            ], $bill['lines'], $actor);

            $reading->update(['invoice_id' => $invoice->id]);

            WorkOrder::query()->whereIn('id', $bill['fees']->pluck('id'))->update(['fee_invoice_id' => $invoice->id, 'fee_billed_at' => now()]);
            Invoice::query()->whereIn('id', $bill['overdue']->pluck('id'))->update(['surcharged_at' => now(), 'surcharged_on_invoice_id' => $invoice->id]);

            Ledger::settle($customer);

            return $invoice->fresh();
        });
    }

    /**
     * Creates an invoice with its lines, due the company's due-date days after the invoice date.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<Line>  $lines
     */
    public static function issue(Company $company, Customer $customer, array $attributes, array $lines, User $actor): Invoice
    {
        $total = Money::fromCents(max(array_sum(array_column($lines, 'cents')), 0));
        $dueDate = Carbon::parse($attributes['invoice_date'])->addDays($company->due_date_days ?? 0)->toDateString();

        $invoice = Invoice::createNumbered($company, [
            ...$attributes,
            'customer_id' => $customer->id,
            'due_date' => $dueDate,
            'total_amount' => $total,
            'amount_paid' => 0,
            'balance' => $total,
            'status' => Money::toCents($total) === 0 ? InvoiceStatus::Paid : InvoiceStatus::Unpaid,
            'created_by_id' => $actor->id,
        ]);

        foreach (array_values($lines) as $sort => $line) {
            $invoice->lines()->create([
                'kind' => $line['kind'],
                'description' => mb_substr($line['description'], 0, 255),
                'quantity' => $line['quantity'],
                'rate' => $line['rate'],
                'amount' => Money::fromCents($line['cents']),
                'charge_element_id' => $line['charge_element_id'] ?? null,
                'work_order_id' => $line['work_order_id'] ?? null,
                'source_invoice_id' => $line['source_invoice_id'] ?? null,
                'sort' => $sort,
            ]);
        }

        return $invoice;
    }

    /**
     * "2026-10": a valid month, not after the current one.
     */
    public static function periodError(string $period, string $today): ?string
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            return 'Choose the billing month.';
        }

        return $period > substr($today, 0, 7) ? "You can't bill a month that hasn't started yet." : null;
    }

    /**
     * "October 2026"
     */
    public static function periodLabel(string $period): string
    {
        return Carbon::createFromFormat('Y-m-d', $period.'-01')->format('F Y');
    }

    /**
     * Today's date for the company.
     */
    public static function today(Company $company): string
    {
        return Format::todayIn($company->timezone);
    }
}
