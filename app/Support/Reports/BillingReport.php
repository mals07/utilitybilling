<?php

namespace App\Support\Reports;

use App\Enums\InvoiceKind;
use App\Enums\InvoiceLineKind;
use App\Enums\InvoiceStatus;
use App\Enums\Page;
use App\Enums\ReportFilter;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\Billing\BillingRun;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Every bill dated in a range of days with what it is made of (water, charges, surcharges, memos,
 * tax), and the totals by zone and by account type. Voided bills are left out.
 */
class BillingReport extends Report
{
    /**
     * @var Collection<int, object>|null
     */
    private ?Collection $bills = null;

    public static function page(): Page
    {
        return Page::BillingReport;
    }

    public static function filters(): array
    {
        return [ReportFilter::Dates, ReportFilter::Zone, ReportFilter::AccountType];
    }

    public function subtitle(): string
    {
        return 'Bills dated '.$this->datesLabel();
    }

    public function summary(): array
    {
        $bills = $this->bills();

        return [
            ['label' => 'Amount billed', 'value' => $this->money($bills->sum('total'))],
            ['label' => 'Bills', 'value' => Format::integer($bills->count())],
            ['label' => 'Water used (m³)', 'value' => Format::quantity($bills->sum('usage'))],
            ['label' => 'Tax', 'value' => $this->money($bills->sum('tax'))],
        ];
    }

    public function tables(): array
    {
        $bills = $this->bills();

        $register = $bills->map(fn (object $bill): array => [
            $bill->number,
            $bill->date,
            $bill->account,
            $bill->customer,
            $bill->zone,
            $bill->description,
            $bill->usage,
            $bill->water,
            $bill->charges,
            $bill->surcharge,
            $bill->adjustment,
            $bill->tax,
            $bill->total,
            $bill->status,
        ])->values()->all();

        $groupRows = fn (string $key): array => $bills->groupBy($key)
            ->map(fn (Collection $group, string $name): array => [$name, $group->count(), $group->sum('usage'), $group->sum('total')])
            ->sortKeys()
            ->values()
            ->all();
        $byZone = $groupRows('zone');
        $byAccountType = $groupRows('accountType');

        $groupColumns = fn (string $label): array => [
            ReportColumn::text($label),
            ReportColumn::count('Bills'),
            ReportColumn::quantity('Usage (m³)'),
            ReportColumn::amount('Amount'),
        ];

        return [
            new ReportTable('By zone', $groupColumns('Zone'), $byZone, ReportTable::totalsOf($byZone, 4, [1, 2, 3]), 'No bills dated on these days.'),
            new ReportTable('By account type', $groupColumns('Account Type'), $byAccountType, ReportTable::totalsOf($byAccountType, 4, [1, 2, 3]), 'No bills dated on these days.'),
            new ReportTable('Bills', [
                ReportColumn::text('Invoice No'),
                ReportColumn::date('Date'),
                ReportColumn::text('Account No'),
                ReportColumn::text('Customer', wide: true),
                ReportColumn::text('Zone'),
                ReportColumn::text('Bill For'),
                ReportColumn::quantity('Usage (m³)'),
                ReportColumn::amount('Water'),
                ReportColumn::amount('Charges & Fees'),
                ReportColumn::amount('Surcharges'),
                ReportColumn::amount('Memos'),
                ReportColumn::amount('Tax'),
                ReportColumn::amount('Total'),
                ReportColumn::text('Status'),
            ], $register, ReportTable::totalsOf($register, 14, range(6, 12)), 'No bills dated on these days.'),
        ];
    }

    public function note(): ?string
    {
        return 'Voided bills are left out; see Voids & Adjustments. Memos are the credit and debit memos applied to each bill. Where prices include tax, the tax is part of the water and fee amounts.';
    }

    /**
     * The bills with their parts in cents.
     *
     * @return Collection<int, object{number: string, date: string, account: string, customer: string, zone: string, accountType: string, description: string, usage: float|null, water: int, charges: int, surcharge: int, adjustment: int, tax: int, total: int, status: string}>
     */
    private function bills(): Collection
    {
        if ($this->bills !== null) {
            return $this->bills;
        }

        $rows = $this->forChosenCustomers(
            $this->onChosenDates(Invoice::query()->where('invoices.company_id', $this->company->id), 'invoices.invoice_date'),
            'invoices.customer_id',
        )
            ->where('invoices.status', '!=', InvoiceStatus::Void->value)
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoin('cluster_types', 'cluster_types.id', '=', 'customers.cluster_type_id')
            ->leftJoin('account_types', 'account_types.id', '=', 'customers.account_type_id')
            ->leftJoin('meter_readings', 'meter_readings.id', '=', 'invoices.meter_reading_id')
            ->orderBy('invoices.invoice_date')
            ->orderBy('invoices.invoice_number')
            ->toBase()
            ->get([
                'invoices.id', 'invoices.invoice_number', 'invoices.invoice_date', 'invoices.kind', 'invoices.billing_period',
                'invoices.total_amount', 'invoices.status', 'customers.account_number', 'customers.first_name',
                'customers.last_name', 'cluster_types.name AS zone', 'account_types.name AS account_type',
                'meter_readings.consumption', 'meter_readings.actual_consumption',
            ]);

        $parts = InvoiceLine::query()
            ->whereIn('invoice_id', $rows->pluck('id'))
            ->select('invoice_id', 'kind')
            ->selectRaw('SUM(amount) AS total')
            ->groupBy('invoice_id', 'kind')
            ->toBase()
            ->get()
            ->groupBy('invoice_id');

        return $this->bills = $rows->map(function (object $row) use ($parts): object {
            $lines = ($parts[$row->id] ?? collect())->mapWithKeys(fn (object $line): array => [$line->kind => Money::toCents($line->total)]);
            $kind = InvoiceKind::tryFrom($row->kind);
            $usage = $row->actual_consumption ?? $row->consumption;

            return (object) [
                'number' => $row->invoice_number,
                'date' => substr((string) $row->invoice_date, 0, 10),
                'account' => $row->account_number ?? '',
                'customer' => trim(($row->first_name ?? '').' '.($row->last_name ?? '')),
                'zone' => $row->zone ?? 'No zone',
                'accountType' => $row->account_type ?? 'No account type',
                'description' => $kind === InvoiceKind::Monthly && $row->billing_period
                    ? BillingRun::periodLabel($row->billing_period)
                    : ($kind?->label() ?? $row->kind),
                'usage' => $usage === null ? null : (float) $usage,
                'water' => $lines[InvoiceLineKind::Water->value] ?? 0,
                'charges' => ($lines[InvoiceLineKind::Charge->value] ?? 0) + ($lines[InvoiceLineKind::WorkOrderFee->value] ?? 0),
                'surcharge' => $lines[InvoiceLineKind::Surcharge->value] ?? 0,
                'adjustment' => $lines[InvoiceLineKind::Adjustment->value] ?? 0,
                'tax' => $lines[InvoiceLineKind::Tax->value] ?? 0,
                'total' => Money::toCents($row->total_amount),
                'status' => InvoiceStatus::tryFrom($row->status)?->label() ?? $row->status,
            ];
        });
    }
}
