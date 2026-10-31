<?php

namespace App\Support\Reports;

use App\Enums\CustomerStatus;
use App\Enums\MeterReadingStatus;
use App\Enums\Page;
use App\Enums\ReadingCode;
use App\Enums\ReportFilter;
use App\Models\Customer;
use App\Models\MeterReading;
use App\Support\Billing\BillingRun;
use App\Support\Format;
use Illuminate\Support\Collection;

/**
 * How far the meter reading of a billing month has got: meters read and still to read in each zone,
 * readings waiting for review or estimated, the work of each reader, and the meters not yet read.
 */
class ReadingsReport extends Report
{
    /**
     * @var array{meters: Collection<int, Customer>, readings: Collection<int, MeterReading>}|null
     */
    private ?array $records = null;

    public static function page(): Page
    {
        return Page::ReadingsReport;
    }

    public static function filters(): array
    {
        return [ReportFilter::Period, ReportFilter::Zone];
    }

    public function subtitle(): string
    {
        return 'Readings for '.$this->periodLabel();
    }

    public function summary(): array
    {
        ['meters' => $meters, 'readings' => $readings] = $this->records();
        $read = $meters->filter(fn (Customer $customer): bool => $readings->has($customer->id))->count();

        return [
            ['label' => 'Meters to read', 'value' => Format::integer($meters->count())],
            ['label' => 'Read', 'value' => Format::integer($read).($meters->isEmpty() ? '' : ' · '.number_format($read / $meters->count() * 100, 1).'%')],
            ['label' => 'Not read yet', 'value' => Format::integer($meters->count() - $read)],
            ['label' => 'Waiting for review', 'value' => Format::integer($readings->where('status', MeterReadingStatus::Review)->count())],
        ];
    }

    public function tables(): array
    {
        ['meters' => $meters, 'readings' => $readings] = $this->records();

        $zoneRows = $meters->groupBy(fn (Customer $customer): string => $customer->clusterType?->name ?? 'No zone')
            ->map(function (Collection $customers, string $zone) use ($readings): array {
                $zoneReadings = $customers->map(fn (Customer $customer): ?MeterReading => $readings->get($customer->id))->filter();
                $read = $zoneReadings->count();

                return [
                    $zone,
                    $customers->count(),
                    $read,
                    $customers->count() - $read,
                    round($read / $customers->count() * 100, 1),
                    $zoneReadings->where('status', MeterReadingStatus::Review)->count(),
                    $zoneReadings->where('status', MeterReadingStatus::Ready)->count(),
                    $zoneReadings->where('status', MeterReadingStatus::Billed)->count(),
                    $zoneReadings->filter(fn (MeterReading $reading): bool => $reading->reading_code->isEstimated())->count(),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
        $zoneTotals = ReportTable::totalsOf($zoneRows, 9, [1, 2, 3, 5, 6, 7, 8]);
        $zoneTotals[4] = $zoneTotals[1] > 0 ? round($zoneTotals[2] / $zoneTotals[1] * 100, 1) : null;

        $readerRows = $readings->groupBy(fn (MeterReading $reading): string => $reading->createdBy?->name ?? 'Not recorded')
            ->map(fn (Collection $taken, string $reader): array => [
                $reader,
                $taken->count(),
                $taken->where('status', MeterReadingStatus::Review)->count(),
                $taken->filter(fn (MeterReading $reading): bool => $reading->reading_code === ReadingCode::NoAccess)->count(),
                $taken->filter(fn (MeterReading $reading): bool => $reading->reading_code === ReadingCode::Defective)->count(),
            ])
            ->sortKeys()
            ->values()
            ->all();

        $lastRead = $this->lastReadPeriods($meters->reject(fn (Customer $customer): bool => $readings->has($customer->id))->pluck('id'));
        $unreadRows = $meters->reject(fn (Customer $customer): bool => $readings->has($customer->id))
            ->sortBy([fn (Customer $a, Customer $b): int => strcmp($a->clusterType?->name ?? '', $b->clusterType?->name ?? ''), fn (Customer $a, Customer $b): int => strcmp($a->account_number, $b->account_number)])
            ->map(fn (Customer $customer): array => [
                $customer->account_number,
                $customer->fullName(),
                $customer->clusterType?->name ?? 'No zone',
                $customer->meter_number,
                isset($lastRead[$customer->id]) ? BillingRun::periodLabel($lastRead[$customer->id]) : 'Never',
            ])
            ->values()
            ->all();

        return [
            new ReportTable('By zone', [
                ReportColumn::text('Zone'),
                ReportColumn::count('Meters'),
                ReportColumn::count('Read'),
                ReportColumn::count('Not Read'),
                ReportColumn::percent('Done'),
                ReportColumn::count('For Review'),
                ReportColumn::count('Ready'),
                ReportColumn::count('Billed'),
                ReportColumn::count('Estimated'),
            ], $zoneRows, $zoneRows === [] ? null : $zoneTotals, 'No connected meters to read.'),
            new ReportTable('By reader', [
                ReportColumn::text('Reader'),
                ReportColumn::count('Readings'),
                ReportColumn::count('For Review'),
                ReportColumn::count('No Access'),
                ReportColumn::count('Defective'),
            ], $readerRows, $readerRows === [] ? null : ReportTable::totalsOf($readerRows, 5, [1, 2, 3, 4]), 'No readings taken for this month yet.'),
            new ReportTable('Meters not read yet', [
                ReportColumn::text('Account No'),
                ReportColumn::text('Customer', wide: true),
                ReportColumn::text('Zone'),
                ReportColumn::text('Meter No'),
                ReportColumn::text('Last Read'),
            ], $unreadRows, null, 'Every meter has been read.'),
        ];
    }

    public function note(): ?string
    {
        return 'Meters to read are the connected customers with a meter. Estimated readings are those taken as No access or Defective meter.';
    }

    /**
     * @return array{meters: Collection<int, Customer>, readings: Collection<int, MeterReading>} Readings keyed by customer id.
     */
    private function records(): array
    {
        if ($this->records !== null) {
            return $this->records;
        }

        $meters = $this->chosenCustomers()
            ->where('status', CustomerStatus::Connected)
            ->whereNotNull('meter_number')
            ->with('clusterType:id,name')
            ->get(['id', 'account_number', 'first_name', 'last_name', 'company_name', 'meter_number', 'cluster_type_id']);

        $readings = $this->forChosenCustomers(MeterReading::query()->where('company_id', $this->company->id))
            ->where('billing_period', $this->filters->period)
            ->with('createdBy:id,name')
            ->get(['id', 'customer_id', 'status', 'reading_code', 'created_by_id'])
            ->keyBy('customer_id');

        return $this->records = ['meters' => $meters, 'readings' => $readings];
    }

    /**
     * The latest billing month before the chosen one that each customer was read for.
     *
     * @param  Collection<int, int>  $customerIds
     * @return array<int, string>
     */
    private function lastReadPeriods(Collection $customerIds): array
    {
        if ($customerIds->isEmpty()) {
            return [];
        }

        return MeterReading::query()
            ->where('company_id', $this->company->id)
            ->whereIn('customer_id', $customerIds)
            ->where('billing_period', '<', $this->filters->period)
            ->selectRaw('customer_id, MAX(billing_period) AS period')
            ->groupBy('customer_id')
            ->toBase()
            ->pluck('period', 'customer_id')
            ->all();
    }
}
