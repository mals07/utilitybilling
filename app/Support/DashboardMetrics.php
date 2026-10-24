<?php

namespace App\Support;

use App\Enums\AdjustmentType;
use App\Enums\CustomerStatus;
use App\Enums\DisconnectionNoticeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\MeterReadingStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReadingCode;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DisconnectionNotice;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\MeterReading;
use App\Models\Payment;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The figures on the dashboard for one company and one month ("2026-10"). Amounts are in cents.
 * Figures "as of today" (balances, open orders) describe now, whichever month is chosen.
 */
final class DashboardMetrics
{
    public const TREND_MONTHS = 6;

    public const TOP_DELINQUENT = 10;

    /**
     * Readings whose consumption is settled: confirmed (Ready) or already billed.
     */
    private const CONFIRMED_READINGS = [MeterReadingStatus::Ready->value, MeterReadingStatus::Billed->value];

    /**
     * Days past due covered by each aging bucket after "Not yet due"; null is open-ended.
     *
     * @var array<string, array{0: int, 1: int|null}>
     */
    public const AGING_BUCKETS = [
        '1–30 days' => [1, 30],
        '31–60 days' => [31, 60],
        '61–90 days' => [61, 90],
        'Over 90 days' => [91, null],
    ];

    private readonly CarbonImmutable $start;

    private readonly string $today;

    public function __construct(private readonly Company $company, string $month)
    {
        $this->start = CarbonImmutable::createFromFormat('!Y-m', $month, $this->timezone());
        $this->today = $company->today();
    }

    /**
     * The chosen month, or the current month when the value isn't a month from the past or today.
     */
    public static function normalizeMonth(string $month, Company $company): string
    {
        $current = substr($company->today(), 0, 7);

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 && $month <= $current ? $month : $current;
    }

    public function monthLabel(): string
    {
        return $this->start->format('F Y');
    }

    public function previousMonthLabel(): string
    {
        return $this->start->subMonth()->format('F Y');
    }

    /**
     * Billed and collected for the month and the month before, with the collection efficiency of each.
     *
     * @return array{billed: int, collected: int, efficiency: float|null, previousBilled: int, previousCollected: int, previousEfficiency: float|null}
     */
    public function revenue(): array
    {
        $previous = $this->start->subMonth();
        $billed = $this->billedByMonth($previous, 2);
        $collected = $this->collectedByMonth($previous, 2);

        return [
            'billed' => $billed[1],
            'collected' => $collected[1],
            'efficiency' => self::efficiency($collected[1], $billed[1]),
            'previousBilled' => $billed[0],
            'previousCollected' => $collected[0],
            'previousEfficiency' => self::efficiency($collected[0], $billed[0]),
        ];
    }

    /**
     * Unpaid balances as of today, and the part of them that is past due.
     *
     * @return array{outstanding: int, overdue: int, accounts: int}
     */
    public function receivables(): array
    {
        $row = $this->openInvoices()
            ->selectRaw('COALESCE(SUM(balance), 0) AS outstanding')
            ->selectRaw('COALESCE(SUM(CASE WHEN due_date < ? THEN balance ELSE 0 END), 0) AS overdue', [$this->today])
            ->selectRaw('COUNT(DISTINCT customer_id) AS accounts')
            ->toBase()
            ->first();

        return [
            'outstanding' => Money::toCents($row->outstanding),
            'overdue' => Money::toCents($row->overdue),
            'accounts' => (int) $row->accounts,
        ];
    }

    /**
     * Unpaid balances as of today by how long they are past due.
     *
     * @return list<array{label: string, cents: int}>
     */
    public function aging(): array
    {
        $today = CarbonImmutable::parse($this->today);
        $query = $this->openInvoices()->selectRaw('COALESCE(SUM(CASE WHEN due_date >= ? THEN balance ELSE 0 END), 0) AS b0', [$this->today]);
        $index = 1;
        foreach (self::AGING_BUCKETS as [$fromDays, $toDays]) {
            // A bill is N days past due when it fell due N days before today; "before the day after"
            // keeps a due date stored with a midnight time in its bucket.
            $dueBefore = $today->subDays($fromDays - 1)->toDateString();
            if ($toDays === null) {
                $query->selectRaw("COALESCE(SUM(CASE WHEN due_date < ? THEN balance ELSE 0 END), 0) AS b{$index}", [$dueBefore]);
            } else {
                $dueFrom = $today->subDays($toDays)->toDateString();
                $query->selectRaw("COALESCE(SUM(CASE WHEN due_date < ? AND due_date >= ? THEN balance ELSE 0 END), 0) AS b{$index}", [$dueBefore, $dueFrom]);
            }
            $index++;
        }
        $row = (array) $query->toBase()->first();

        $buckets = [['label' => 'Not yet due', 'cents' => Money::toCents($row['b0'])]];
        $index = 1;
        foreach (array_keys(self::AGING_BUCKETS) as $label) {
            $buckets[] = ['label' => $label, 'cents' => Money::toCents($row["b{$index}"])];
            $index++;
        }

        return $buckets;
    }

    /**
     * The accounts with the most money past due as of today.
     *
     * @return list<array{customerId: int, accountNumber: string, name: string, bills: int, cents: int, oldestDue: string}>
     */
    public function topDelinquent(): array
    {
        $rows = $this->openInvoices()
            ->where('due_date', '<', $this->today)
            ->select('customer_id')
            ->selectRaw('SUM(balance) AS overdue, COUNT(*) AS bills, MIN(due_date) AS oldest_due')
            ->groupBy('customer_id')
            ->orderByDesc('overdue')
            ->limit(self::TOP_DELINQUENT)
            ->toBase()
            ->get();

        $customers = Customer::query()
            ->whereIn('id', $rows->pluck('customer_id'))
            ->get(['id', 'account_number', 'first_name', 'last_name', 'company_name'])
            ->keyBy('id');

        return $rows->map(fn (object $row): array => [
            'customerId' => (int) $row->customer_id,
            'accountNumber' => $customers[$row->customer_id]?->account_number ?? '',
            'name' => $customers[$row->customer_id]?->fullName() ?? '',
            'bills' => (int) $row->bills,
            'cents' => Money::toCents($row->overdue),
            'oldestDue' => Format::shortDate(CarbonImmutable::parse($row->oldest_due)),
        ])->all();
    }

    /**
     * Money received in the month by payment method, largest first.
     *
     * @return list<array{label: string, cents: int, count: int}>
     */
    public function collectionsByMethod(): array
    {
        return $this->payments($this->start, $this->start->endOfMonth())
            ->select('method')
            ->selectRaw('SUM(amount) AS total, COUNT(*) AS payments')
            ->groupBy('method')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'label' => PaymentMethod::tryFrom($row->method)?->label() ?? $row->method,
                'cents' => Money::toCents($row->total),
                'count' => (int) $row->payments,
            ])
            ->sortByDesc('cents')
            ->values()
            ->all();
    }

    /**
     * Amounts billed in the month by the customers' account type, largest first.
     *
     * @return list<array{label: string, cents: int}>
     */
    public function billedByAccountType(): array
    {
        return $this->invoices($this->start, $this->start->endOfMonth())
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoin('account_types', 'account_types.id', '=', 'customers.account_type_id')
            ->selectRaw('account_types.name AS account_type, SUM(invoices.total_amount) AS total')
            ->groupBy('account_types.name')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'label' => $row->account_type ?? 'No account type',
                'cents' => Money::toCents($row->total),
            ])
            ->sortByDesc('cents')
            ->values()
            ->all();
    }

    /**
     * Credit and debit memos issued in the month (not voided).
     *
     * @return array{credits: int, creditCount: int, debits: int, debitCount: int}
     */
    public function adjustments(): array
    {
        $rows = InvoiceAdjustment::query()
            ->where('company_id', $this->company->id)
            ->whereNull('voided_at')
            ->where($this->onDates('issued_on', $this->start, $this->start->endOfMonth()))
            ->select('type')
            ->selectRaw('SUM(amount) AS total, COUNT(*) AS memos')
            ->groupBy('type')
            ->toBase()
            ->get()
            ->keyBy('type');

        $credit = $rows[AdjustmentType::Credit->value] ?? null;
        $debit = $rows[AdjustmentType::Debit->value] ?? null;

        return [
            'credits' => Money::toCents($credit?->total),
            'creditCount' => (int) ($credit?->memos ?? 0),
            'debits' => Money::toCents($debit?->total),
            'debitCount' => (int) ($debit?->memos ?? 0),
        ];
    }

    /**
     * Customers by status today, and the connections made, cut and restored in the month.
     *
     * @return array{connected: int, disconnected: int, pending: int, newInstallations: int, disconnections: int, reconnections: int}
     */
    public function customers(): array
    {
        $byStatus = Customer::query()
            ->where('company_id', $this->company->id)
            ->select('status')
            ->selectRaw('COUNT(*) AS customers')
            ->groupBy('status')
            ->toBase()
            ->pluck('customers', 'status');

        $completed = $this->completedOrders($this->start, $this->start->endOfMonth())
            ->whereIn('order_type', [WorkOrderType::NewInstallation->value, WorkOrderType::Disconnection->value, WorkOrderType::Reconnection->value])
            ->select('order_type')
            ->selectRaw('COUNT(*) AS orders')
            ->groupBy('order_type')
            ->toBase()
            ->pluck('orders', 'order_type');

        return [
            'connected' => (int) ($byStatus[CustomerStatus::Connected->value] ?? 0),
            'disconnected' => (int) ($byStatus[CustomerStatus::Disconnected->value] ?? 0),
            'pending' => (int) ($byStatus[CustomerStatus::Pending->value] ?? 0),
            'newInstallations' => (int) ($completed[WorkOrderType::NewInstallation->value] ?? 0),
            'disconnections' => (int) ($completed[WorkOrderType::Disconnection->value] ?? 0),
            'reconnections' => (int) ($completed[WorkOrderType::Reconnection->value] ?? 0),
        ];
    }

    /**
     * Cubic meters used in the month and the month before, from confirmed readings (Ready or Billed).
     *
     * @return array{consumption: float, previousConsumption: float, readings: int, averagePerConnection: float|null}
     */
    public function water(): array
    {
        $previous = $this->start->subMonth();
        $consumption = $this->consumptionByMonth($previous, 2);
        $readings = $this->readings($this->start)->whereIn('status', self::CONFIRMED_READINGS)->count();

        return [
            'consumption' => $consumption[1],
            'previousConsumption' => $consumption[0],
            'readings' => $readings,
            'averagePerConnection' => $readings > 0 ? round($consumption[1] / $readings, 1) : null,
        ];
    }

    /**
     * Readings taken for the month against the connected customers with a meter, and the readings that need attention.
     *
     * @return array{expected: int, notRead: int, review: int, ready: int, billed: int, noAccess: int, defective: int}
     */
    public function readingProgress(): array
    {
        $byStatus = $this->readings($this->start)
            ->select('status')
            ->selectRaw('COUNT(*) AS readings')
            ->groupBy('status')
            ->toBase()
            ->pluck('readings', 'status');

        $byCode = $this->readings($this->start)
            ->whereIn('reading_code', [ReadingCode::NoAccess->value, ReadingCode::Defective->value])
            ->select('reading_code')
            ->selectRaw('COUNT(*) AS readings')
            ->groupBy('reading_code')
            ->toBase()
            ->pluck('readings', 'reading_code');

        $expected = Customer::query()
            ->where('company_id', $this->company->id)
            ->where('status', CustomerStatus::Connected)
            ->whereNotNull('meter_number')
            ->count();

        return [
            'expected' => $expected,
            'notRead' => max(0, $expected - (int) $byStatus->sum()),
            'review' => (int) ($byStatus[MeterReadingStatus::Review->value] ?? 0),
            'ready' => (int) ($byStatus[MeterReadingStatus::Ready->value] ?? 0),
            'billed' => (int) ($byStatus[MeterReadingStatus::Billed->value] ?? 0),
            'noAccess' => (int) ($byCode[ReadingCode::NoAccess->value] ?? 0),
            'defective' => (int) ($byCode[ReadingCode::Defective->value] ?? 0),
        ];
    }

    /**
     * Open orders as of today, and the orders completed in the month with how long they took.
     *
     * @return array{inProgress: int, onHold: int, overdue: int, completed: int, averageDays: float|null, openByType: list<array{label: string, count: int}>}
     */
    public function workOrders(): array
    {
        $open = WorkOrder::query()
            ->where('company_id', $this->company->id)
            ->whereIn('status', [WorkOrderStatus::InProgress->value, WorkOrderStatus::OnHold->value]);

        $byStatus = (clone $open)->select('status')->selectRaw('COUNT(*) AS orders')->groupBy('status')->toBase()->pluck('orders', 'status');
        $overdue = (clone $open)->where('requested_at', '<', WorkOrder::overdueCutoff($this->company->timezone))->count();
        $openByType = (clone $open)
            ->select('order_type')
            ->selectRaw('COUNT(*) AS orders')
            ->groupBy('order_type')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'label' => WorkOrderType::tryFrom($row->order_type)?->label() ?? $row->order_type,
                'count' => (int) $row->orders,
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        $durations = $this->completedOrders($this->start, $this->start->endOfMonth())
            ->toBase()
            ->get(['requested_at', 'completed_at'])
            ->map(fn (object $row): float => CarbonImmutable::parse($row->requested_at)->diffInHours(CarbonImmutable::parse($row->completed_at)) / 24);

        return [
            'inProgress' => (int) ($byStatus[WorkOrderStatus::InProgress->value] ?? 0),
            'onHold' => (int) ($byStatus[WorkOrderStatus::OnHold->value] ?? 0),
            'overdue' => $overdue,
            'completed' => $durations->count(),
            'averageDays' => $durations->isEmpty() ? null : round($durations->avg(), 1),
            'openByType' => $openByType,
        ];
    }

    /**
     * Notices still running as of today and the money on them, and how the notices closed in the month ended.
     *
     * @return array{pending: int, scheduled: int, atRisk: int, settled: int, disconnected: int, settledRate: float|null}
     */
    public function disconnections(): array
    {
        $active = DisconnectionNotice::query()
            ->where('company_id', $this->company->id)
            ->whereIn('status', [DisconnectionNoticeStatus::Pending->value, DisconnectionNoticeStatus::Scheduled->value])
            ->select('status')
            ->selectRaw('COUNT(*) AS notices, SUM(overdue_amount) AS amount')
            ->groupBy('status')
            ->toBase()
            ->get()
            ->keyBy('status');

        [$from, $to] = $this->utcRange($this->start, $this->start->endOfMonth());
        $closed = DisconnectionNotice::query()
            ->where('company_id', $this->company->id)
            ->whereIn('status', [DisconnectionNoticeStatus::Settled->value, DisconnectionNoticeStatus::Disconnected->value])
            ->whereBetween('closed_at', [$from, $to])
            ->select('status')
            ->selectRaw('COUNT(*) AS notices')
            ->groupBy('status')
            ->toBase()
            ->pluck('notices', 'status');

        $settled = (int) ($closed[DisconnectionNoticeStatus::Settled->value] ?? 0);
        $disconnected = (int) ($closed[DisconnectionNoticeStatus::Disconnected->value] ?? 0);

        return [
            'pending' => (int) ($active[DisconnectionNoticeStatus::Pending->value]?->notices ?? 0),
            'scheduled' => (int) ($active[DisconnectionNoticeStatus::Scheduled->value]?->notices ?? 0),
            'atRisk' => $active->sum(fn (object $row): int => Money::toCents($row->amount)),
            'settled' => $settled,
            'disconnected' => $disconnected,
            'settledRate' => $settled + $disconnected > 0 ? round($settled / ($settled + $disconnected) * 100, 1) : null,
        ];
    }

    /**
     * Labels of the six months ending with the chosen one: "May 2026", ..., "Oct 2026".
     *
     * @return list<string>
     */
    public function trendMonths(): array
    {
        $first = $this->start->subMonths(self::TREND_MONTHS - 1);

        return array_map(fn (int $offset): string => $first->addMonths($offset)->format('M Y'), range(0, self::TREND_MONTHS - 1));
    }

    /**
     * @return list<int> Cents billed in each of the six months.
     */
    public function billedTrend(): array
    {
        return $this->billedByMonth($this->start->subMonths(self::TREND_MONTHS - 1), self::TREND_MONTHS);
    }

    /**
     * @return list<int> Cents collected in each of the six months.
     */
    public function collectedTrend(): array
    {
        return $this->collectedByMonth($this->start->subMonths(self::TREND_MONTHS - 1), self::TREND_MONTHS);
    }

    /**
     * @return list<float> Cubic meters used in each of the six months.
     */
    public function consumptionTrend(): array
    {
        return $this->consumptionByMonth($this->start->subMonths(self::TREND_MONTHS - 1), self::TREND_MONTHS);
    }

    /**
     * @return list<int> New installations completed in each of the six months.
     */
    public function newConnectionsTrend(): array
    {
        $first = $this->start->subMonths(self::TREND_MONTHS - 1);

        return array_map(
            fn (int $offset): int => $this->completedOrders($first->addMonths($offset), $first->addMonths($offset)->endOfMonth())
                ->where('order_type', WorkOrderType::NewInstallation->value)
                ->count(),
            range(0, self::TREND_MONTHS - 1),
        );
    }

    /**
     * The percent change from $previous to $current, or null when there is nothing to compare with.
     */
    public static function change(int|float $current, int|float $previous): ?float
    {
        return $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;
    }

    /**
     * Collected as a percentage of billed, or null when nothing was billed.
     */
    public static function efficiency(int $collected, int $billed): ?float
    {
        return $billed > 0 ? round($collected / $billed * 100, 1) : null;
    }

    /**
     * @return list<int> Cents billed in each of $count months from $first.
     */
    private function billedByMonth(CarbonImmutable $first, int $count): array
    {
        $totals = $this->invoices($first, $first->addMonths($count - 1)->endOfMonth())
            ->selectRaw('SUBSTR(invoice_date, 1, 7) AS month, SUM(total_amount) AS total')
            ->groupByRaw('SUBSTR(invoice_date, 1, 7)')
            ->toBase()
            ->pluck('total', 'month');

        return $this->spread($first, $count, fn (string $month): int => Money::toCents($totals[$month] ?? 0));
    }

    /**
     * @return list<int> Cents collected in each of $count months from $first.
     */
    private function collectedByMonth(CarbonImmutable $first, int $count): array
    {
        $totals = $this->payments($first, $first->addMonths($count - 1)->endOfMonth())
            ->selectRaw('SUBSTR(paid_on, 1, 7) AS month, SUM(amount) AS total')
            ->groupByRaw('SUBSTR(paid_on, 1, 7)')
            ->toBase()
            ->pluck('total', 'month');

        return $this->spread($first, $count, fn (string $month): int => Money::toCents($totals[$month] ?? 0));
    }

    /**
     * @return list<float> Cubic meters used in each of $count months from $first.
     */
    private function consumptionByMonth(CarbonImmutable $first, int $count): array
    {
        $totals = MeterReading::query()
            ->where('company_id', $this->company->id)
            ->whereBetween('billing_period', [$first->format('Y-m'), $first->addMonths($count - 1)->format('Y-m')])
            ->whereIn('status', self::CONFIRMED_READINGS)
            ->select('billing_period')
            ->selectRaw('SUM(COALESCE(actual_consumption, consumption)) AS total')
            ->groupBy('billing_period')
            ->toBase()
            ->pluck('total', 'billing_period');

        return $this->spread($first, $count, fn (string $month): float => round((float) ($totals[$month] ?? 0), 1));
    }

    /**
     * @template T
     *
     * @param  callable(string): T  $valueOf  Given "2026-10".
     * @return list<T>
     */
    private function spread(CarbonImmutable $first, int $count, callable $valueOf): array
    {
        return array_map(fn (int $offset) => $valueOf($first->addMonths($offset)->format('Y-m')), range(0, $count - 1));
    }

    /**
     * @return Builder<Invoice> Bills (not voided) dated from $from to $to.
     */
    private function invoices(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Invoice::query()
            ->where('invoices.company_id', $this->company->id)
            ->where('invoices.status', '!=', InvoiceStatus::Void->value)
            ->where($this->onDates('invoices.invoice_date', $from, $to));
    }

    /**
     * @return Builder<Invoice> Bills with a balance left.
     */
    private function openInvoices(): Builder
    {
        return Invoice::query()
            ->where('company_id', $this->company->id)
            ->whereIn('status', InvoiceStatus::openValues());
    }

    /**
     * @return Builder<Payment> Payments (not voided) received from $from to $to.
     */
    private function payments(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Payment::query()
            ->where('company_id', $this->company->id)
            ->whereNull('voided_at')
            ->where($this->onDates('paid_on', $from, $to));
    }

    /**
     * A date column from $from's day through $to's day. The end is "before the next day", so a date
     * stored with a midnight time still counts on its last day.
     *
     * @return Closure(Builder): void
     */
    private function onDates(string $column, CarbonImmutable $from, CarbonImmutable $to): Closure
    {
        return fn (Builder $query) => $query
            ->where($column, '>=', $from->toDateString())
            ->where($column, '<', $to->addDay()->toDateString());
    }

    /**
     * @return Builder<MeterReading> Readings for the billing period of $month.
     */
    private function readings(CarbonImmutable $month): Builder
    {
        return MeterReading::query()
            ->where('company_id', $this->company->id)
            ->where('billing_period', $month->format('Y-m'));
    }

    /**
     * @return Builder<WorkOrder> Orders completed from $from to $to (whole days in the company's time zone).
     */
    private function completedOrders(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return WorkOrder::query()
            ->where('company_id', $this->company->id)
            ->where('status', WorkOrderStatus::Completed->value)
            ->whereBetween('completed_at', $this->utcRange($from, $to));
    }

    /**
     * @return array{0: string, 1: string} The start of $from's day and the end of $to's day, in UTC.
     */
    private function utcRange(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            $from->startOfDay()->utc()->toDateTimeString(),
            $to->endOfDay()->utc()->toDateTimeString(),
        ];
    }

    private function timezone(): string
    {
        return $this->company->timezone ?? Format::DEFAULT_TIMEZONE;
    }
}
