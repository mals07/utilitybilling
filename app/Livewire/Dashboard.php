<?php

namespace App\Livewire;

use App\Enums\Page;
use App\Models\User;
use App\Support\DashboardMetrics;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The company's performance for a month: money billed and collected, receivables, water used,
 * customers and field work. Each section shows only to users who may open the page it comes from.
 */
#[Layout('layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public const BILLED_COLOR = '#0369a1';

    public const COLLECTED_COLOR = '#eb6834';

    /**
     * The month shown, as "2026-10"; empty is the current month.
     */
    #[Url(as: 'month', except: '')]
    public string $month = '';

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $company = $user->company()->with(['city:id,name', 'province:id,name', 'country:id,name'])->firstOrFail();

        $currentMonth = substr($company->today(), 0, 7);
        $month = DashboardMetrics::normalizeMonth($this->month, $company);
        $metrics = new DashboardMetrics($company, $month);

        $sees = [
            'invoices' => $user->canAccessPage(Page::Invoices),
            'payments' => $user->canAccessPage(Page::Payments),
            'customers' => $user->canAccessPage(Page::Customers),
            'readings' => $user->canAccessPage(Page::MeterReadings),
            'workOrders' => $user->canAccessPage(Page::WorkOrders),
            'disconnections' => $user->canAccessPage(Page::Disconnections),
        ];

        return view('livewire.dashboard', [
            'company' => $company,
            'canEditCompany' => $user->canAccessPage(Page::CompanySetup),
            'currency' => $company->currency ? $company->currency.' ' : '',
            'sees' => $sees,
            'shownMonth' => $month,
            'currentMonth' => $currentMonth,
            'isCurrentMonth' => $month === $currentMonth,
            'monthLabel' => $metrics->monthLabel(),
            'previousMonthLabel' => $metrics->previousMonthLabel(),
            'revenue' => $sees['invoices'] || $sees['payments'] ? $metrics->revenue() : null,
            'receivables' => $sees['invoices'] ? $metrics->receivables() : null,
            'aging' => $sees['invoices'] ? $metrics->aging() : [],
            'topDelinquent' => $sees['invoices'] ? $metrics->topDelinquent() : [],
            'billedByAccountType' => $sees['invoices'] ? $metrics->billedByAccountType() : [],
            'adjustments' => $sees['invoices'] ? $metrics->adjustments() : null,
            'collectionsByMethod' => $sees['payments'] ? $metrics->collectionsByMethod() : [],
            'customers' => $sees['customers'] ? $metrics->customers() : null,
            'water' => $sees['readings'] ? $metrics->water() : null,
            'readingProgress' => $sees['readings'] ? $metrics->readingProgress() : null,
            'workOrders' => $sees['workOrders'] ? $metrics->workOrders() : null,
            'disconnections' => $sees['disconnections'] ? $metrics->disconnections() : null,
            'trendMonths' => $metrics->trendMonths(),
            'trendMeasures' => $this->trendMeasures($metrics, $sees),
        ]);
    }

    /**
     * The views of the six-month chart the user may see; the series at "highlight" gets its high and low months marked.
     *
     * @param  array<string, bool>  $sees
     * @return list<array{key: string, label: string, format: string, highlight: int, series: list<array{label: string, color: string, values: list<float|int|null>}>}>
     */
    private function trendMeasures(DashboardMetrics $metrics, array $sees): array
    {
        $toAmounts = fn (array $cents): array => array_map(fn (int $value): float => (float) Money::fromCents($value), $cents);
        $billed = $sees['invoices'] ? $metrics->billedTrend() : null;
        $collected = $sees['payments'] ? $metrics->collectedTrend() : null;
        $measures = [];

        $moneySeries = [];
        if ($billed !== null) {
            $moneySeries[] = ['label' => 'Billed', 'color' => self::BILLED_COLOR, 'values' => $toAmounts($billed)];
        }
        if ($collected !== null) {
            $moneySeries[] = ['label' => 'Collected', 'color' => self::COLLECTED_COLOR, 'values' => $toAmounts($collected)];
        }
        if ($moneySeries !== []) {
            $label = match (true) {
                $billed !== null && $collected !== null => 'Billed vs Collected',
                $billed !== null => 'Billed',
                default => 'Collected',
            };
            // Collections are the money the company actually took in, so their highs and lows are marked.
            $measures[] = ['key' => 'revenue', 'label' => $label, 'format' => 'money', 'highlight' => count($moneySeries) - 1, 'series' => $moneySeries];
        }

        if ($billed !== null && $collected !== null) {
            $efficiency = array_map(fn (int $paid, int $bill): ?float => DashboardMetrics::efficiency($paid, $bill), $collected, $billed);
            $measures[] = ['key' => 'efficiency', 'label' => 'Collection efficiency', 'format' => 'percent', 'highlight' => 0, 'series' => [
                ['label' => 'Collection efficiency', 'color' => self::BILLED_COLOR, 'values' => $efficiency],
            ]];
        }

        if ($sees['readings']) {
            $measures[] = ['key' => 'water', 'label' => 'Water consumption', 'format' => 'quantity', 'highlight' => 0, 'series' => [
                ['label' => 'Water consumption', 'color' => self::BILLED_COLOR, 'values' => $metrics->consumptionTrend()],
            ]];
        }

        if ($sees['customers']) {
            $measures[] = ['key' => 'connections', 'label' => 'New connections', 'format' => 'count', 'highlight' => 0, 'series' => [
                ['label' => 'New connections', 'color' => self::BILLED_COLOR, 'values' => $metrics->newConnectionsTrend()],
            ]];
        }

        return $measures;
    }
}
