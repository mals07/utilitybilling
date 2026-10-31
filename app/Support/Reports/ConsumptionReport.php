<?php

namespace App\Support\Reports;

use App\Enums\MeterReadingStatus;
use App\Enums\Page;
use App\Enums\ReadingCode;
use App\Enums\ReportFilter;
use App\Models\MeterReading;
use App\Support\DashboardMetrics;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Water used in a billing month from confirmed readings (Ready or Billed): by zone and account type,
 * the largest users against the month before, and the meters that used nothing.
 */
class ConsumptionReport extends Report
{
    public const TOP_USERS = 20;

    /**
     * Readings that count: confirmed, or already billed.
     */
    private const CONFIRMED = [MeterReadingStatus::Ready->value, MeterReadingStatus::Billed->value];

    /**
     * @var Collection<int, object>|null
     */
    private ?Collection $readings = null;

    public static function page(): Page
    {
        return Page::ConsumptionReport;
    }

    public static function filters(): array
    {
        return [ReportFilter::Period, ReportFilter::Zone, ReportFilter::AccountType];
    }

    public function subtitle(): string
    {
        return 'Confirmed readings for '.$this->periodLabel();
    }

    public function summary(): array
    {
        $readings = $this->readings();
        $total = (float) $readings->sum('usage');

        return [
            ['label' => 'Water used (m³)', 'value' => Format::quantity($total)],
            ['label' => 'Meters read', 'value' => Format::integer($readings->count())],
            ['label' => 'Average per meter (m³)', 'value' => $readings->isEmpty() ? '—' : Format::quantity(round($total / $readings->count(), 1))],
            ['label' => 'Meters with no usage', 'value' => Format::integer($readings->filter(fn (object $reading): bool => $reading->usage <= 0)->count())],
        ];
    }

    public function tables(): array
    {
        $readings = $this->readings();
        $total = (float) $readings->sum('usage');

        $groupRows = fn (string $key): array => $readings->groupBy($key)
            ->map(function (Collection $group, string $name) use ($total): array {
                $usage = (float) $group->sum('usage');

                return [$name, $group->count(), $usage, round($usage / $group->count(), 1), $total > 0 ? round($usage / $total * 100, 1) : null];
            })
            ->sortKeys()
            ->values()
            ->all();
        $groupColumns = fn (string $label): array => [
            ReportColumn::text($label),
            ReportColumn::count('Meters'),
            ReportColumn::quantity('Usage (m³)'),
            ReportColumn::quantity('Average (m³)'),
            ReportColumn::percent('Share'),
        ];
        $groupTotals = fn (): array => [
            'Total',
            $readings->count(),
            $total,
            $readings->isEmpty() ? null : round($total / $readings->count(), 1),
            $readings->isEmpty() ? null : 100.0,
        ];
        $byZone = $groupRows('zone');
        $byAccountType = $groupRows('accountType');

        $top = $readings->filter(fn (object $reading): bool => $reading->usage > 0)->sortByDesc('usage')->take(self::TOP_USERS)->values();
        $previous = $this->previousUsage($top->pluck('customerId'));
        $topRows = $top->map(fn (object $reading): array => [
            $reading->account,
            $reading->customer,
            $reading->zone,
            $reading->usage,
            $previous[$reading->customerId] ?? null,
            DashboardMetrics::change($reading->usage, $previous[$reading->customerId] ?? 0),
        ])->all();

        $zeroRows = $readings->filter(fn (object $reading): bool => $reading->usage <= 0)
            ->sortBy('account')
            ->map(fn (object $reading): array => [$reading->account, $reading->customer, $reading->zone, $reading->meter, $reading->code])
            ->values()
            ->all();

        return [
            new ReportTable('By zone', $groupColumns('Zone'), $byZone, $readings->isEmpty() ? null : $groupTotals(), 'No confirmed readings for this month.'),
            new ReportTable('By account type', $groupColumns('Account Type'), $byAccountType, $readings->isEmpty() ? null : $groupTotals(), 'No confirmed readings for this month.'),
            new ReportTable('Largest users', [
                ReportColumn::text('Account No'),
                ReportColumn::text('Customer', wide: true),
                ReportColumn::text('Zone'),
                ReportColumn::quantity('Usage (m³)'),
                ReportColumn::quantity('Month Before (m³)'),
                ReportColumn::percent('Change'),
            ], $topRows, null, 'No confirmed readings for this month.', 'The '.self::TOP_USERS.' accounts that used the most water.'),
            new ReportTable('Meters with no usage', [
                ReportColumn::text('Account No'),
                ReportColumn::text('Customer', wide: true),
                ReportColumn::text('Zone'),
                ReportColumn::text('Meter No'),
                ReportColumn::text('Reading Code'),
            ], $zeroRows, null, 'Every meter read used some water.', 'Worth a check for stopped meters, vacant homes or wrong readings.'),
        ];
    }

    public function note(): ?string
    {
        return 'Only confirmed readings count; readings waiting for review are left out. Estimated readings (no access, defective meter) count at their estimate.';
    }

    /**
     * @return Collection<int, object{customerId: int, account: string, customer: string, zone: string, accountType: string, meter: string|null, code: string, usage: float}>
     */
    private function readings(): Collection
    {
        if ($this->readings !== null) {
            return $this->readings;
        }

        return $this->readings = $this->confirmedReadings($this->filters->period)
            ->leftJoin('customers', 'customers.id', '=', 'meter_readings.customer_id')
            ->leftJoin('cluster_types', 'cluster_types.id', '=', 'customers.cluster_type_id')
            ->leftJoin('account_types', 'account_types.id', '=', 'customers.account_type_id')
            ->toBase()
            ->get([
                'meter_readings.customer_id', 'meter_readings.meter_number', 'meter_readings.reading_code',
                'meter_readings.consumption', 'meter_readings.actual_consumption', 'customers.account_number',
                'customers.first_name', 'customers.last_name', 'cluster_types.name AS zone', 'account_types.name AS account_type',
            ])
            ->map(fn (object $row): object => (object) [
                'customerId' => (int) $row->customer_id,
                'account' => $row->account_number ?? '',
                'customer' => trim(($row->first_name ?? '').' '.($row->last_name ?? '')),
                'zone' => $row->zone ?? 'No zone',
                'accountType' => $row->account_type ?? 'No account type',
                'meter' => $row->meter_number,
                'code' => ReadingCode::tryFrom($row->reading_code)?->label() ?? $row->reading_code,
                'usage' => (float) ($row->actual_consumption ?? $row->consumption),
            ]);
    }

    /**
     * Usage in the month before the chosen one, by customer id.
     *
     * @param  Collection<int, int>  $customerIds
     * @return array<int, float>
     */
    private function previousUsage(Collection $customerIds): array
    {
        if ($customerIds->isEmpty()) {
            return [];
        }

        $previous = CarbonImmutable::parse($this->filters->period.'-01')->subMonth()->format('Y-m');

        return $this->confirmedReadings($previous)
            ->whereIn('meter_readings.customer_id', $customerIds)
            ->toBase()
            ->get(['meter_readings.customer_id', 'meter_readings.consumption', 'meter_readings.actual_consumption'])
            ->mapWithKeys(fn (object $row): array => [(int) $row->customer_id => (float) ($row->actual_consumption ?? $row->consumption)])
            ->all();
    }

    /**
     * @return Builder<MeterReading>
     */
    private function confirmedReadings(string $period): Builder
    {
        return $this->forChosenCustomers(MeterReading::query()->where('meter_readings.company_id', $this->company->id), 'meter_readings.customer_id')
            ->where('meter_readings.billing_period', $period)
            ->whereIn('meter_readings.status', self::CONFIRMED);
    }
}
