<?php

namespace App\Support\Reports;

use App\Enums\InvoiceStatus;
use App\Enums\Page;
use App\Enums\ReportFilter;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Billing\BillingRun;
use App\Support\DashboardMetrics;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Billed against collected month by month, the share collected, and how much of each month's
 * bills is still unpaid today.
 */
class EfficiencyReport extends Report
{
    /**
     * @var list<array{0: string, 1: int, 2: int, 3: float|null, 4: int}>|null
     */
    private ?array $months = null;

    public static function page(): Page
    {
        return Page::EfficiencyReport;
    }

    public static function filters(): array
    {
        return [ReportFilter::Periods, ReportFilter::Zone, ReportFilter::AccountType];
    }

    protected static function defaultFromPeriod(string $period): string
    {
        return CarbonImmutable::parse($period.'-01')->subMonths(11)->format('Y-m');
    }

    public function subtitle(): string
    {
        $from = BillingRun::periodLabel($this->filters->fromPeriod);

        return $this->filters->fromPeriod === $this->filters->period ? $from : $from.' to '.$this->periodLabel();
    }

    public function summary(): array
    {
        [$billed, $collected, $unpaid] = $this->totals();
        $efficiency = DashboardMetrics::efficiency($collected, $billed);

        return [
            ['label' => 'Billed', 'value' => $this->money($billed)],
            ['label' => 'Collected', 'value' => $this->money($collected)],
            ['label' => 'Collection efficiency', 'value' => $efficiency === null ? '—' : number_format($efficiency, 1).'%'],
            ['label' => 'Still unpaid from these bills', 'value' => $this->money($unpaid)],
        ];
    }

    public function tables(): array
    {
        $months = $this->months();
        [$billed, $collected, $unpaid] = $this->totals();

        return [
            new ReportTable('By month', [
                ReportColumn::text('Month'),
                ReportColumn::amount('Billed'),
                ReportColumn::amount('Collected'),
                ReportColumn::percent('Efficiency'),
                ReportColumn::amount('Still Unpaid'),
            ], $months, ['Total', $billed, $collected, DashboardMetrics::efficiency($collected, $billed), $unpaid]),
        ];
    }

    public function note(): ?string
    {
        return 'Billed counts the bills dated in each month and Collected the payments received in it, so a payment for an older bill counts in the month it was paid. Still Unpaid is what is left today of the bills dated in each month. Voided bills and receipts are left out.';
    }

    /**
     * @return array{0: int, 1: int, 2: int} Billed, collected and still unpaid over all the months.
     */
    private function totals(): array
    {
        $months = collect($this->months());

        return [$months->sum(1), $months->sum(2), $months->sum(4)];
    }

    /**
     * @return list<array{0: string, 1: int, 2: int, 3: float|null, 4: int}> Month label, billed, collected, efficiency and still unpaid.
     */
    private function months(): array
    {
        if ($this->months !== null) {
            return $this->months;
        }

        $first = CarbonImmutable::parse($this->filters->fromPeriod.'-01');
        $last = CarbonImmutable::parse($this->filters->period.'-01');
        $from = $first->toDateString();
        $before = $last->addMonth()->toDateString();

        $bills = $this->forChosenCustomers(Invoice::query()->where('company_id', $this->company->id))
            ->where('status', '!=', InvoiceStatus::Void->value)
            ->where('invoice_date', '>=', $from)
            ->where('invoice_date', '<', $before)
            ->selectRaw('SUBSTR(invoice_date, 1, 7) AS month, SUM(total_amount) AS billed, SUM(balance) AS unpaid')
            ->groupByRaw('SUBSTR(invoice_date, 1, 7)')
            ->toBase()
            ->get()
            ->keyBy('month');

        /** @var Collection<string, string> $collected */
        $collected = $this->forChosenCustomers(Payment::query()->where('company_id', $this->company->id))
            ->whereNull('voided_at')
            ->where('paid_on', '>=', $from)
            ->where('paid_on', '<', $before)
            ->selectRaw('SUBSTR(paid_on, 1, 7) AS month, SUM(amount) AS total')
            ->groupByRaw('SUBSTR(paid_on, 1, 7)')
            ->toBase()
            ->pluck('total', 'month');

        $months = [];
        for ($month = $first; $month <= $last; $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $billed = Money::toCents($bills[$key]->billed ?? 0);
            $paid = Money::toCents($collected[$key] ?? 0);
            $months[] = [$month->format('F Y'), $billed, $paid, DashboardMetrics::efficiency($paid, $billed), Money::toCents($bills[$key]->unpaid ?? 0)];
        }

        return $this->months = $months;
    }
}
