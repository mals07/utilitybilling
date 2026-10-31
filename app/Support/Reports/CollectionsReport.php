<?php

namespace App\Support\Reports;

use App\Enums\Page;
use App\Enums\ReportFilter;
use App\Models\Payment;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Money received over a range of days, receipt by receipt, with totals by payment method and by the
 * staff member who received it, for the end-of-day cash count. Voided receipts are listed apart.
 */
class CollectionsReport extends Report
{
    /**
     * @var Collection<int, Payment>|null
     */
    private ?Collection $payments = null;

    public static function page(): Page
    {
        return Page::CollectionsReport;
    }

    public static function filters(): array
    {
        return [ReportFilter::Dates, ReportFilter::Zone, ReportFilter::Cashier];
    }

    protected static function defaultDates(string $today): array
    {
        return [$today, $today];
    }

    public function subtitle(): string
    {
        return 'Payments received '.$this->datesLabel();
    }

    public function summary(): array
    {
        $posted = $this->posted();
        $voided = $this->voided();

        return [
            ['label' => 'Collected', 'value' => $this->money($this->cents($posted))],
            ['label' => 'Receipts', 'value' => Format::integer($posted->count())],
            ['label' => 'Kept as advance credit', 'value' => $this->money($posted->sum(fn (Payment $payment): int => Money::toCents($payment->unapplied_amount)))],
            ['label' => 'Voided receipts', 'value' => Format::integer($voided->count()).' · '.$this->money($this->cents($voided))],
        ];
    }

    public function tables(): array
    {
        $posted = $this->posted();
        $customers = $this->customerDetails($this->payments()->pluck('customer_id'));

        $receipts = $posted->map(fn (Payment $payment): array => [
            $payment->receipt_number,
            $payment->paid_on->toDateString(),
            $customers[$payment->customer_id]['account'] ?? '',
            $customers[$payment->customer_id]['name'] ?? '',
            $customers[$payment->customer_id]['zone'] ?? '',
            $payment->method->label(),
            $payment->reference_number,
            $payment->receivedBy?->name,
            Money::toCents($payment->amount),
        ])->values()->all();

        $byMethod = $posted->groupBy(fn (Payment $payment): string => $payment->method->label())
            ->map(fn (Collection $payments, string $method): array => [$method, $payments->count(), $this->cents($payments)])
            ->sortByDesc(fn (array $row): int => $row[2])->values()->all();

        $byCashier = $posted->groupBy(fn (Payment $payment): string => $payment->receivedBy?->name ?? 'Not recorded')
            ->map(fn (Collection $payments, string $name): array => [$name, $payments->count(), $this->cents($payments)])
            ->sortBy(fn (array $row): string => $row[0])->values()->all();

        $voided = $this->voided()->map(fn (Payment $payment): array => [
            $payment->receipt_number,
            $payment->paid_on->toDateString(),
            $customers[$payment->customer_id]['name'] ?? '',
            Money::toCents($payment->amount),
            $payment->void_reason,
            $payment->voidedBy?->name,
        ])->values()->all();

        return [
            new ReportTable('Receipts', [
                ReportColumn::text('OR No'),
                ReportColumn::date('Paid On'),
                ReportColumn::text('Account No'),
                ReportColumn::text('Customer', wide: true),
                ReportColumn::text('Zone'),
                ReportColumn::text('Method'),
                ReportColumn::text('Reference'),
                ReportColumn::text('Received By'),
                ReportColumn::amount('Amount'),
            ], $receipts, ReportTable::totalsOf($receipts, 9, [8]), 'No payments received on these days.'),
            new ReportTable('By payment method', [
                ReportColumn::text('Method'),
                ReportColumn::count('Receipts'),
                ReportColumn::amount('Amount'),
            ], $byMethod, ReportTable::totalsOf($byMethod, 3, [1, 2]), 'No payments received on these days.'),
            new ReportTable('By cashier', [
                ReportColumn::text('Received By'),
                ReportColumn::count('Receipts'),
                ReportColumn::amount('Amount'),
            ], $byCashier, ReportTable::totalsOf($byCashier, 3, [1, 2]), 'No payments received on these days.'),
            new ReportTable('Voided receipts', [
                ReportColumn::text('OR No'),
                ReportColumn::date('Paid On'),
                ReportColumn::text('Customer', wide: true),
                ReportColumn::amount('Amount'),
                ReportColumn::text('Reason', wide: true),
                ReportColumn::text('Voided By'),
            ], $voided, $voided === [] ? null : ReportTable::totalsOf($voided, 6, [3]), 'No receipts from these days were voided.'),
        ];
    }

    public function note(): ?string
    {
        return 'Voided receipts are left out of the totals. Payments count on the date they were received.';
    }

    /**
     * @return Collection<int, Payment> Payments received on the chosen days, posted and voided.
     */
    private function payments(): Collection
    {
        return $this->payments ??= $this->forChosenCustomers(
            $this->onChosenDates(Payment::query()->where('company_id', $this->company->id), 'paid_on'),
        )
            ->when($this->filters->cashierId !== null, fn (Builder $query) => $query->where('received_by_id', $this->filters->cashierId))
            ->with(['receivedBy:id,name', 'voidedBy:id,name'])
            ->orderBy('paid_on')
            ->orderBy('receipt_number')
            ->get();
    }

    /**
     * @return Collection<int, Payment>
     */
    private function posted(): Collection
    {
        return $this->payments()->filter(fn (Payment $payment): bool => ! $payment->isVoid())->values();
    }

    /**
     * @return Collection<int, Payment>
     */
    private function voided(): Collection
    {
        return $this->payments()->filter(fn (Payment $payment): bool => $payment->isVoid())->values();
    }

    /**
     * @param  Collection<int, Payment>  $payments
     */
    private function cents(Collection $payments): int
    {
        return $payments->sum(fn (Payment $payment): int => Money::toCents($payment->amount));
    }
}
