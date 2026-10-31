<?php

namespace App\Support\Reports;

use App\Enums\DisconnectionNoticeStatus;
use App\Enums\Page;
use App\Enums\ReportFilter;
use App\Models\DisconnectionNotice;
use App\Support\Disconnections;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Notices of disconnection issued over a range of days and how they ended, the customers eligible
 * for a notice today, and the customers exempt from disconnection.
 */
class DisconnectionsReport extends Report
{
    /**
     * @var Collection<int, DisconnectionNotice>|null
     */
    private ?Collection $notices = null;

    public static function page(): Page
    {
        return Page::DisconnectionsReport;
    }

    public static function filters(): array
    {
        return [ReportFilter::Dates, ReportFilter::Zone];
    }

    public function subtitle(): string
    {
        return 'Notices issued '.$this->datesLabel();
    }

    public function summary(): array
    {
        $notices = $this->notices();
        $count = fn (DisconnectionNoticeStatus $status): int => $notices->where('status', $status)->count();
        $eligible = Disconnections::eligible($this->company, $this->company->today())
            ->when($this->filters->zoneId !== null, fn (Builder $query) => $query->where('customers.cluster_type_id', $this->filters->zoneId))
            ->count();

        return [
            ['label' => 'Notices issued', 'value' => Format::integer($notices->count()).' · '.$this->money($this->cents($notices))],
            ['label' => 'Settled', 'value' => Format::integer($count(DisconnectionNoticeStatus::Settled))],
            ['label' => 'Disconnected', 'value' => Format::integer($count(DisconnectionNoticeStatus::Disconnected))],
            ['label' => 'Eligible for a notice today', 'value' => Format::integer($eligible)],
        ];
    }

    public function tables(): array
    {
        $notices = $this->notices();
        $customers = $this->customerDetails($notices->pluck('customer_id'));

        $statusRows = collect(DisconnectionNoticeStatus::cases())
            ->map(fn (DisconnectionNoticeStatus $status): array => [
                $status->label(),
                $notices->where('status', $status)->count(),
                $this->cents($notices->where('status', $status)),
            ])
            ->all();

        $noticeRows = $notices->map(fn (DisconnectionNotice $notice): array => [
            $notice->notice_number,
            $notice->issued_on->toDateString(),
            $customers[$notice->customer_id]['account'] ?? '',
            $customers[$notice->customer_id]['name'] ?? '',
            $customers[$notice->customer_id]['zone'] ?? 'No zone',
            $notice->overdue_bills,
            Money::toCents($notice->overdue_amount),
            $notice->deadline->toDateString(),
            $notice->status->label(),
            $this->localDate($notice->getRawOriginal('closed_at')),
            $notice->close_reason,
        ])->values()->all();

        $today = $this->company->today();
        $exemptRows = $this->chosenCustomers()
            ->whereNotNull('disconnection_exempt_reason')
            ->where(fn (Builder $query) => $query->whereNull('disconnection_exempt_until')->orWhereDate('disconnection_exempt_until', '>=', $today))
            ->with('clusterType:id,name')
            ->orderBy('account_number')
            ->get(['id', 'account_number', 'first_name', 'last_name', 'cluster_type_id', 'disconnection_exempt_reason', 'disconnection_exempt_until'])
            ->map(fn ($customer): array => [
                $customer->account_number,
                $customer->fullName(),
                $customer->clusterType?->name ?? 'No zone',
                $customer->disconnection_exempt_reason,
                $customer->disconnection_exempt_until?->toDateString(),
            ])
            ->all();

        return [
            new ReportTable('By outcome', [
                ReportColumn::text('Status'),
                ReportColumn::count('Notices'),
                ReportColumn::amount('Overdue Amount'),
            ], $statusRows, ReportTable::totalsOf($statusRows, 3, [1, 2])),
            new ReportTable('Notices issued', [
                ReportColumn::text('Notice No'),
                ReportColumn::date('Issued'),
                ReportColumn::text('Account No'),
                ReportColumn::text('Customer', wide: true),
                ReportColumn::text('Zone'),
                ReportColumn::count('Overdue Bills'),
                ReportColumn::amount('Overdue Amount'),
                ReportColumn::date('Deadline'),
                ReportColumn::text('Status'),
                ReportColumn::date('Closed On'),
                ReportColumn::text('Closing Note', wide: true),
            ], $noticeRows, $noticeRows === [] ? null : ReportTable::totalsOf($noticeRows, 11, [6]), 'No notices issued on these days.'),
            new ReportTable('Exempt from disconnection today', [
                ReportColumn::text('Account No'),
                ReportColumn::text('Customer', wide: true),
                ReportColumn::text('Zone'),
                ReportColumn::text('Reason', wide: true),
                ReportColumn::date('Exempt Until'),
            ], $exemptRows, null, 'No customers are exempt.'),
        ];
    }

    public function note(): ?string
    {
        return 'Overdue Amount is what each notice demanded when it was issued. Status is where each notice stands today.';
    }

    /**
     * @return Collection<int, DisconnectionNotice>
     */
    private function notices(): Collection
    {
        return $this->notices ??= $this->forChosenCustomers(
            $this->onChosenDates(DisconnectionNotice::query()->where('company_id', $this->company->id), 'issued_on'),
        )
            ->orderBy('issued_on')
            ->orderBy('notice_number')
            ->get();
    }

    /**
     * @param  Collection<int, DisconnectionNotice>  $notices
     */
    private function cents(Collection $notices): int
    {
        return $notices->sum(fn (DisconnectionNotice $notice): int => Money::toCents($notice->overdue_amount));
    }
}
