<?php

namespace App\Support\Reports;

use App\Enums\AdjustmentType;
use App\Enums\InvoiceStatus;
use App\Enums\Page;
use App\Enums\ReportFilter;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\Payment;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The changes made to bills and payments over a range of days: credit and debit memos issued, and
 * the bills, receipts and memos voided, each with who did it and why.
 */
class AdjustmentsReport extends Report
{
    /**
     * @var array{memos: Collection<int, InvoiceAdjustment>, voidedBills: Collection<int, Invoice>, voidedPayments: Collection<int, Payment>, voidedMemos: Collection<int, InvoiceAdjustment>}|null
     */
    private ?array $records = null;

    public static function page(): Page
    {
        return Page::AdjustmentsReport;
    }

    public static function filters(): array
    {
        return [ReportFilter::Dates, ReportFilter::Zone];
    }

    public function subtitle(): string
    {
        return 'Memos issued and records voided '.$this->datesLabel();
    }

    public function summary(): array
    {
        $records = $this->records();
        $active = $records['memos']->reject(fn (InvoiceAdjustment $memo): bool => $memo->isVoid());
        $memoFigure = function (AdjustmentType $type) use ($active): string {
            $memos = $active->where('type', $type);

            return Format::integer($memos->count()).' · '.$this->money($memos->sum(fn (InvoiceAdjustment $memo): int => Money::toCents($memo->amount)));
        };
        $voidFigure = fn (Collection $records, string $column): string => Format::integer($records->count()).' · '
            .$this->money($records->sum(fn ($record): int => Money::toCents($record->{$column})));

        return [
            ['label' => 'Credit memos', 'value' => $memoFigure(AdjustmentType::Credit)],
            ['label' => 'Debit memos', 'value' => $memoFigure(AdjustmentType::Debit)],
            ['label' => 'Voided bills', 'value' => $voidFigure($records['voidedBills'], 'total_amount')],
            ['label' => 'Voided receipts', 'value' => $voidFigure($records['voidedPayments'], 'amount')],
        ];
    }

    public function tables(): array
    {
        $records = $this->records();
        $customers = $this->customerDetails(collect([
            ...$records['memos']->pluck('customer_id'),
            ...$records['voidedBills']->pluck('customer_id'),
            ...$records['voidedPayments']->pluck('customer_id'),
            ...$records['voidedMemos']->pluck('customer_id'),
        ]));
        $name = fn (int $customerId): string => trim(($customers[$customerId]['account'] ?? '').' · '.($customers[$customerId]['name'] ?? ''), ' ·');
        $voidedOn = fn ($record): ?string => $this->localDate($record->getRawOriginal('voided_at'));

        $memos = $records['memos']->map(fn (InvoiceAdjustment $memo): array => [
            $memo->label(),
            $memo->issued_on->toDateString(),
            $memo->invoice?->invoice_number,
            $name($memo->customer_id),
            $memo->reason_category->label(),
            $memo->reason,
            $memo->createdBy?->name,
            $memo->type === AdjustmentType::Credit ? Money::toCents($memo->amount) : null,
            $memo->type === AdjustmentType::Debit ? Money::toCents($memo->amount) : null,
            $memo->isVoid() ? 'Void' : 'Active',
        ])->values()->all();
        $activeMemos = array_values(array_filter($memos, fn (array $row): bool => $row[9] === 'Active'));

        $voidedBills = $records['voidedBills']->map(fn (Invoice $invoice): array => [
            $invoice->invoice_number,
            $invoice->invoice_date->toDateString(),
            $name($invoice->customer_id),
            Money::toCents($invoice->total_amount),
            $invoice->void_reason,
            $invoice->voidedBy?->name,
            $voidedOn($invoice),
        ])->values()->all();

        $voidedPayments = $records['voidedPayments']->map(fn (Payment $payment): array => [
            $payment->receipt_number,
            $payment->paid_on->toDateString(),
            $name($payment->customer_id),
            Money::toCents($payment->amount),
            $payment->void_reason,
            $payment->voidedBy?->name,
            $voidedOn($payment),
        ])->values()->all();

        $voidedMemos = $records['voidedMemos']->map(fn (InvoiceAdjustment $memo): array => [
            $memo->label(),
            $memo->issued_on->toDateString(),
            $name($memo->customer_id),
            Money::toCents($memo->amount),
            $memo->void_reason,
            $memo->voidedBy?->name,
            $voidedOn($memo),
        ])->values()->all();

        $voidColumns = fn (string $number, string $date): array => [
            ReportColumn::text($number),
            ReportColumn::date($date),
            ReportColumn::text('Customer', wide: true),
            ReportColumn::amount('Amount'),
            ReportColumn::text('Reason', wide: true),
            ReportColumn::text('Voided By'),
            ReportColumn::date('Voided On'),
        ];
        $voidTotals = fn (array $rows): ?array => $rows === [] ? null : ReportTable::totalsOf($rows, 7, [3]);

        return [
            new ReportTable('Credit and debit memos issued', [
                ReportColumn::text('Memo No'),
                ReportColumn::date('Issued'),
                ReportColumn::text('Invoice No'),
                ReportColumn::text('Customer', wide: true),
                ReportColumn::text('Reason'),
                ReportColumn::text('Details', wide: true),
                ReportColumn::text('Issued By'),
                ReportColumn::amount('Credit'),
                ReportColumn::amount('Debit'),
                ReportColumn::text('Status'),
            ], $memos, $memos === [] ? null : ReportTable::totalsOf($activeMemos, 10, [7, 8], 'Total (active memos)'), 'No memos issued on these days.'),
            new ReportTable('Voided bills', $voidColumns('Invoice No', 'Bill Date'), $voidedBills, $voidTotals($voidedBills), 'No bills voided on these days.'),
            new ReportTable('Voided receipts', $voidColumns('OR No', 'Paid On'), $voidedPayments, $voidTotals($voidedPayments), 'No receipts voided on these days.'),
            new ReportTable('Voided memos', $voidColumns('Memo No', 'Issued'), $voidedMemos, $voidTotals($voidedMemos), 'No memos voided on these days.'),
        ];
    }

    public function note(): ?string
    {
        return 'Memos count on the day they were issued; voided records on the day they were voided.';
    }

    /**
     * @return array{memos: Collection<int, InvoiceAdjustment>, voidedBills: Collection<int, Invoice>, voidedPayments: Collection<int, Payment>, voidedMemos: Collection<int, InvoiceAdjustment>}
     */
    private function records(): array
    {
        if ($this->records !== null) {
            return $this->records;
        }

        $moments = $this->chosenMoments();

        return $this->records = [
            'memos' => $this->forChosenCustomers($this->onChosenDates(InvoiceAdjustment::query()->where('company_id', $this->company->id), 'issued_on'))
                ->with(['invoice:id,invoice_number', 'createdBy:id,name'])
                ->orderBy('issued_on')->orderBy('id')
                ->get(),
            'voidedBills' => $this->forChosenCustomers(Invoice::query()->where('company_id', $this->company->id))
                ->where('status', InvoiceStatus::Void->value)
                ->whereBetween('voided_at', $moments)
                ->with('voidedBy:id,name')
                ->orderBy('voided_at')
                ->get(),
            'voidedPayments' => $this->forChosenCustomers(Payment::query()->where('company_id', $this->company->id))
                ->whereBetween('voided_at', $moments)
                ->with('voidedBy:id,name')
                ->orderBy('voided_at')
                ->get(),
            'voidedMemos' => $this->forChosenCustomers(InvoiceAdjustment::query()->where('company_id', $this->company->id))
                ->whereBetween('voided_at', $moments)
                ->with('voidedBy:id,name')
                ->orderBy('voided_at')
                ->get(),
        ];
    }
}
