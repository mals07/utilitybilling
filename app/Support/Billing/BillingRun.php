<?php

namespace App\Support\Billing;

use App\Enums\InvoiceKind;
use App\Enums\InvoiceLineKind;
use App\Enums\InvoiceStatus;
use App\Enums\MeterReadingStatus;
use App\Enums\OverdueHandling;
use App\Enums\ReadingCode;
use App\Enums\WorkOrderStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MeterReading;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The monthly billing run: works out each customer's bill from their saved reading and issues it.
 *
 * @phpstan-import-type Line from BillCalculator
 * @phpstan-import-type TaxSummary from Tax
 */
final class BillingRun
{
    public const READING_PATTERN = '/^\d{1,9}(\.\d{1,3})?$/';

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
     * Works out the bill for a reading without saving anything.
     *
     * @return array{previous: float, present: ?float, replaced: float, consumption: float, billed: float, minimumApplied: bool, estimated: bool, lines: list<Line>, tax: TaxSummary, totalCents: int, previousBalanceCents: int, overdue: Collection<int, Invoice>, fees: Collection<int, WorkOrder>}
     *
     * @throws BillingException
     */
    public static function compute(Customer $customer, string $period, string $present, bool $defective, string $invoiceDate): array
    {
        $reading = MeterReadings::evaluate($customer, $period, $present, $defective ? ReadingCode::Defective : ReadingCode::Normal);

        return [
            'previous' => $reading['previous'],
            'present' => $reading['present'],
            'replaced' => $reading['replaced'],
            'consumption' => $reading['usage'],
            'estimated' => $reading['estimated'],
            ...self::bill($customer, $reading['usage'], $invoiceDate),
        ];
    }

    /**
     * Works out the bill for a month's usage (before the minimum consumption) without saving anything:
     * water, charges, work order fees, overdue surcharges and tax.
     *
     * @return array{billed: float, minimumApplied: bool, lines: list<Line>, tax: TaxSummary, totalCents: int, previousBalanceCents: int, overdue: Collection<int, Invoice>, fees: Collection<int, WorkOrder>}
     *
     * @throws BillingException
     */
    public static function bill(Customer $customer, float $usage, string $invoiceDate): array
    {
        $customer->loadMissing(['rateType.ranges', 'rateType.setup', 'chargeType.elements.chargeElement', 'accountType', 'company']);
        $rate = $customer->rateType ?? throw new BillingException('The customer has no rate. Set one on the Customers page.');

        $water = BillCalculator::water($rate, $usage);
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

        $tax = Tax::apply($customer->company, $customer, $lines);

        return [
            'billed' => $water['billed'],
            'minimumApplied' => $water['minimumApplied'],
            'lines' => $tax['lines'],
            'tax' => $tax['summary'],
            'totalCents' => max(array_sum(array_column($tax['lines'], 'cents')), 0),
            'previousBalanceCents' => Money::toCents($customer->invoices()->whereIn('status', InvoiceStatus::openValues())->sum('balance')),
            'overdue' => $overdue,
            'fees' => $fees,
        ];
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
     * Saves the reading as entered (accepted, even when it would need review) and issues the
     * customer's bill for the period in one step.
     *
     * @throws BillingException
     */
    public static function generate(Customer $customer, string $period, string $present, bool $defective, string $invoiceDate, string $readOn, User $actor): Invoice
    {
        return DB::transaction(function () use ($customer, $period, $present, $defective, $invoiceDate, $readOn, $actor): Invoice {
            $reading = MeterReadings::save($customer, $period, $present, $defective ? ReadingCode::Defective : ReadingCode::Normal, $readOn, $actor, confirmed: true);

            return self::generateFor($reading, $invoiceDate, $actor);
        });
    }

    /**
     * Issues the bill for a saved reading that is Ready to bill.
     *
     * @throws BillingException
     */
    public static function generateFor(MeterReading $reading, string $invoiceDate, User $actor): Invoice
    {
        return DB::transaction(function () use ($reading, $invoiceDate, $actor): Invoice {
            $customer = Customer::query()->lockForUpdate()->findOrFail($reading->customer_id);
            $reading = MeterReading::query()->lockForUpdate()->findOrFail($reading->id);
            $period = $reading->billing_period;

            if ($existing = self::existingBill($customer, $period)) {
                throw new BillingException("Already billed for this period (invoice {$existing->invoice_number}).");
            }
            if ($reading->isBilled()) {
                throw new BillingException('This reading is already billed.');
            }
            if ($reading->status === MeterReadingStatus::Review) {
                throw new BillingException('The reading needs review. Confirm it on the Meter Readings page first.');
            }
            if ($reading->meter_number !== $customer->meter_number) {
                throw new BillingException('The meter was changed after this reading was taken. Read the new meter on the Meter Readings page.');
            }

            // Worked out again from the saved reading, in case an earlier month was billed or voided since.
            $evaluated = MeterReadings::evaluate($customer, $period, (string) ($reading->present_reading ?? ''), $reading->reading_code);
            $bill = self::bill($customer, $evaluated['usage'], $invoiceDate);
            $company = $customer->company;

            $invoice = self::issue($company, $customer, [
                'kind' => InvoiceKind::Monthly,
                'billing_period' => $period,
                'meter_reading_id' => $reading->id,
                'invoice_date' => $invoiceDate,
                'previous_balance' => Money::fromCents($bill['previousBalanceCents']),
                ...Tax::invoiceAttributes($bill['tax']),
            ], $bill['lines'], $actor);

            $reading->update([
                'status' => MeterReadingStatus::Billed,
                'invoice_id' => $invoice->id,
                'previous_reading' => $evaluated['previous'],
                'consumption' => round($bill['billed'], 3),
                'actual_consumption' => $evaluated['usage'],
                'replaced_meter_consumption' => $evaluated['replaced'],
                'estimate_deducted' => $evaluated['deducted'],
            ]);

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
