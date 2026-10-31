<?php

namespace App\Support\Reports;

use App\Enums\Page;
use App\Enums\ReportFilter;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Models\WorkOrder;
use App\Support\Format;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Field work over a range of days by type of order: requested, completed, cancelled, how long the
 * completed ones took and the fees they carried, plus every order still open today and how long it
 * has waited.
 */
class WorkOrdersReport extends Report
{
    /**
     * @var array{requested: Collection<int, WorkOrder>, completed: Collection<int, WorkOrder>, open: Collection<int, WorkOrder>}|null
     */
    private ?array $records = null;

    public static function page(): Page
    {
        return Page::WorkOrdersReport;
    }

    public static function filters(): array
    {
        return [ReportFilter::Dates, ReportFilter::Zone];
    }

    public function subtitle(): string
    {
        return 'Orders requested or completed '.$this->datesLabel();
    }

    public function summary(): array
    {
        ['requested' => $requested, 'completed' => $completed, 'open' => $open] = $this->records();
        $averageDays = $this->averageDays($completed);

        return [
            ['label' => 'Requested', 'value' => Format::integer($requested->count())],
            ['label' => 'Completed', 'value' => Format::integer($completed->count())],
            ['label' => 'Average days to complete', 'value' => $averageDays === null ? '—' : number_format($averageDays, 1)],
            ['label' => 'Open today', 'value' => Format::integer($open->count()).' · '.Format::integer($open->filter(fn (WorkOrder $order): bool => $order->isOverdue($this->company->timezone))->count()).' overdue'],
        ];
    }

    public function tables(): array
    {
        ['requested' => $requested, 'completed' => $completed, 'open' => $open] = $this->records();
        $ofType = fn (Collection $orders, WorkOrderType $type): Collection => $orders->where('order_type', $type);

        $typeRows = collect(WorkOrderType::cases())
            ->map(fn (WorkOrderType $type): array => [
                $type->label(),
                $ofType($requested, $type)->count(),
                $ofType($completed, $type)->count(),
                $ofType($requested, $type)->where('status', WorkOrderStatus::Cancelled)->count(),
                $ofType($open, $type)->count(),
                $this->averageDays($ofType($completed, $type)),
                $ofType($completed, $type)->sum(fn (WorkOrder $order): int => Money::toCents($order->fee_amount)),
            ])
            ->filter(fn (array $row): bool => $row[1] + $row[2] + $row[4] > 0)
            ->values()
            ->all();
        $typeTotals = ReportTable::totalsOf($typeRows, 7, [1, 2, 3, 4, 6]);
        $typeTotals[5] = $this->averageDays($completed);

        $customers = $this->customerDetails($open->pluck('customer_id'));
        $today = $this->company->today();
        $openRows = $open->map(fn (WorkOrder $order): array => [
            $order->order_number,
            $order->order_type->label(),
            $this->localDate($order->getRawOriginal('requested_at')),
            self::daysBetween($this->localDate($order->getRawOriginal('requested_at')), $today),
            $order->status->label().($order->isOverdue($this->company->timezone) ? ' · Overdue' : ''),
            $customers[$order->customer_id]['account'] ?? '',
            $customers[$order->customer_id]['name'] ?? '',
            $customers[$order->customer_id]['zone'] ?? 'No zone',
            $order->assignedTo?->name,
            $order->hold_reason,
        ])->values()->all();

        return [
            new ReportTable('By type of order', [
                ReportColumn::text('Type'),
                ReportColumn::count('Requested'),
                ReportColumn::count('Completed'),
                ReportColumn::count('Cancelled'),
                ReportColumn::count('Open Today'),
                ReportColumn::quantity('Avg Days'),
                ReportColumn::amount('Fees'),
            ], $typeRows, $typeRows === [] ? null : $typeTotals, 'No work orders on these days.'),
            new ReportTable('Open orders today', [
                ReportColumn::text('Order No'),
                ReportColumn::text('Type'),
                ReportColumn::date('Requested'),
                ReportColumn::count('Days Open'),
                ReportColumn::text('Status'),
                ReportColumn::text('Account No'),
                ReportColumn::text('Customer', wide: true),
                ReportColumn::text('Zone'),
                ReportColumn::text('Assigned To'),
                ReportColumn::text('Hold Reason', wide: true),
            ], $openRows, null, 'No open work orders.'),
        ];
    }

    public function note(): ?string
    {
        return 'Requested and Cancelled count the orders requested on these days; Completed, Avg Days and Fees count the orders completed on these days. Open orders are as of today, oldest first.';
    }

    /**
     * Average days from request to completion, or null when there are none.
     *
     * @param  Collection<int, WorkOrder>  $orders
     */
    private function averageDays(Collection $orders): ?float
    {
        if ($orders->isEmpty()) {
            return null;
        }

        return round($orders->avg(fn (WorkOrder $order): float => CarbonImmutable::parse($order->requested_at)->diffInHours(CarbonImmutable::parse($order->completed_at)) / 24), 1);
    }

    /**
     * @return array{requested: Collection<int, WorkOrder>, completed: Collection<int, WorkOrder>, open: Collection<int, WorkOrder>}
     */
    private function records(): array
    {
        if ($this->records !== null) {
            return $this->records;
        }

        $orders = fn () => $this->forChosenCustomers(WorkOrder::query()->where('company_id', $this->company->id));
        $moments = $this->chosenMoments();

        return $this->records = [
            'requested' => $orders()->whereBetween('requested_at', $moments)->get(['id', 'order_type', 'status']),
            'completed' => $orders()
                ->where('status', WorkOrderStatus::Completed->value)
                ->whereBetween('completed_at', $moments)
                ->get(['id', 'order_type', 'requested_at', 'completed_at', 'fee_amount']),
            'open' => $orders()
                ->whereIn('status', WorkOrderStatus::openValues())
                ->with('assignedTo:id,name')
                ->orderBy('requested_at')
                ->get(['id', 'order_number', 'order_type', 'status', 'hold_reason', 'customer_id', 'assigned_to_id', 'requested_at']),
        ];
    }
}
