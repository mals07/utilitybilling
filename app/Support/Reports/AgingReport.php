<?php

namespace App\Support\Reports;

use App\Enums\InvoiceStatus;
use App\Enums\Page;
use App\Enums\ReportFilter;
use App\Models\Invoice;
use App\Support\DashboardMetrics;
use App\Support\Format;
use App\Support\Money;

/**
 * Unpaid balances as of today, per account and per zone, by how long they are past due. Advance
 * credit isn't taken off: it is applied to the next bill.
 */
class AgingReport extends Report
{
    /**
     * Each account's zone and cents per bucket by customer id, worked out once.
     *
     * @var array<int, array{zone: string, buckets: list<int>}>|null
     */
    private ?array $accounts = null;

    public static function page(): Page
    {
        return Page::AgingReport;
    }

    public static function filters(): array
    {
        return [ReportFilter::Zone, ReportFilter::AccountType];
    }

    public function subtitle(): string
    {
        return 'Unpaid balances as of '.Format::isoDate($this->company->today());
    }

    public function summary(): array
    {
        $accounts = $this->accounts();
        $total = array_sum(array_map(fn (array $account): int => array_sum($account['buckets']), $accounts));
        $notDue = array_sum(array_map(fn (array $account): int => $account['buckets'][0], $accounts));
        $overNinety = array_sum(array_map(fn (array $account): int => $account['buckets'][count(DashboardMetrics::AGING_BUCKETS)], $accounts));

        return [
            ['label' => 'Total unpaid', 'value' => $this->money($total)],
            ['label' => 'Past due', 'value' => $this->money($total - $notDue)],
            ['label' => 'Over 90 days', 'value' => $this->money($overNinety)],
            ['label' => 'Accounts with a balance', 'value' => Format::integer(count($accounts))],
        ];
    }

    public function tables(): array
    {
        $accounts = $this->accounts();
        $bucketColumns = array_map(fn (string $label): ReportColumn => ReportColumn::amount($label), $this->bucketLabels());
        $bucketCount = count($bucketColumns);
        $customers = $this->customerDetails(array_keys($accounts));

        $accountRows = [];
        foreach ($accounts as $customerId => $account) {
            $accountRows[] = [
                $customers[$customerId]['account'] ?? '',
                $customers[$customerId]['name'] ?? '',
                $account['zone'],
                ...$account['buckets'],
                array_sum($account['buckets']),
            ];
        }
        usort($accountRows, fn (array $a, array $b): int => $b[3 + $bucketCount] <=> $a[3 + $bucketCount] ?: strcmp($a[0], $b[0]));

        $zones = [];
        foreach ($accounts as $account) {
            $zones[$account['zone']] ??= ['accounts' => 0, 'buckets' => array_fill(0, $bucketCount, 0)];
            $zones[$account['zone']]['accounts']++;
            foreach ($account['buckets'] as $index => $cents) {
                $zones[$account['zone']]['buckets'][$index] += $cents;
            }
        }
        ksort($zones);
        $zoneRows = array_map(
            fn (string $zone, array $totals): array => [$zone, $totals['accounts'], ...$totals['buckets'], array_sum($totals['buckets'])],
            array_keys($zones),
            $zones,
        );

        return [
            new ReportTable('By zone', [
                ReportColumn::text('Zone'),
                ReportColumn::count('Accounts'),
                ...$bucketColumns,
                ReportColumn::amount('Total'),
            ], $zoneRows, ReportTable::totalsOf($zoneRows, $bucketCount + 3, range(1, $bucketCount + 2)), 'No unpaid balances.'),
            new ReportTable('By account', [
                ReportColumn::text('Account No'),
                ReportColumn::text('Customer', wide: true),
                ReportColumn::text('Zone'),
                ...$bucketColumns,
                ReportColumn::amount('Total'),
            ], $accountRows, ReportTable::totalsOf($accountRows, $bucketCount + 4, range(3, $bucketCount + 3)), 'No unpaid balances.'),
        ];
    }

    public function note(): ?string
    {
        return 'Days past due count from each bill\'s due date. Advance credit isn\'t taken off; it is applied to the next bill.';
    }

    /**
     * @return list<string> "Not yet due", "1–30 days", ...
     */
    private function bucketLabels(): array
    {
        return ['Not yet due', ...array_keys(DashboardMetrics::AGING_BUCKETS)];
    }

    /**
     * Each account's unpaid balance split by days past due.
     *
     * @return array<int, array{zone: string, buckets: list<int>}>
     */
    private function accounts(): array
    {
        if ($this->accounts !== null) {
            return $this->accounts;
        }

        $today = $this->company->today();
        $invoices = $this->forChosenCustomers(Invoice::query()->where('invoices.company_id', $this->company->id), 'invoices.customer_id')
            ->whereIn('invoices.status', InvoiceStatus::openValues())
            ->where('invoices.balance', '>', 0)
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoin('cluster_types', 'cluster_types.id', '=', 'customers.cluster_type_id')
            ->toBase()
            ->get(['invoices.customer_id', 'invoices.due_date', 'invoices.balance', 'cluster_types.name AS zone']);

        $accounts = [];
        foreach ($invoices as $invoice) {
            $customerId = (int) $invoice->customer_id;
            $accounts[$customerId] ??= ['zone' => $invoice->zone ?? 'No zone', 'buckets' => array_fill(0, count($this->bucketLabels()), 0)];
            $accounts[$customerId]['buckets'][$this->bucketOf(substr((string) $invoice->due_date, 0, 10), $today)] += Money::toCents($invoice->balance);
        }

        return $this->accounts = $accounts;
    }

    /**
     * The bucket a bill falls in: 0 when not yet due, else the matching DashboardMetrics::AGING_BUCKETS entry.
     */
    private function bucketOf(string $dueDate, string $today): int
    {
        if ($dueDate >= $today) {
            return 0;
        }

        $daysPastDue = self::daysBetween($dueDate, $today);
        $index = 1;
        foreach (DashboardMetrics::AGING_BUCKETS as [$fromDays, $toDays]) {
            if ($daysPastDue >= $fromDays && ($toDays === null || $daysPastDue <= $toDays)) {
                return $index;
            }
            $index++;
        }

        return $index - 1;
    }
}
