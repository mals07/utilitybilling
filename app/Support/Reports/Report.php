<?php

namespace App\Support\Reports;

use App\Enums\Page;
use App\Enums\ReportFilter;
use App\Models\Company;
use App\Models\Customer;
use App\Support\Billing\BillingRun;
use App\Support\Format;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A report: figures and tables worked out for one company with the chosen filters. The same report
 * is shown on its page, printed, and exported as CSV.
 */
abstract class Report
{
    /**
     * The longest date range a report covers, so a typo in a year can't load years of records.
     */
    public const MAX_DAYS = 366;

    /**
     * The most billing months a month range covers.
     */
    public const MAX_MONTHS = 24;

    public function __construct(protected readonly Company $company, protected readonly ReportFilters $filters) {}

    abstract public static function page(): Page;

    /**
     * The filters the report offers, in display order.
     *
     * @return list<ReportFilter>
     */
    abstract public static function filters(): array;

    /**
     * What the report covers, e.g. "October 1 2026 to October 31 2026", shown under its title.
     */
    abstract public function subtitle(): string;

    /**
     * @return list<ReportTable>
     */
    abstract public function tables(): array;

    /**
     * Headline figures shown above the tables, already formatted.
     *
     * @return list<array{label: string, value: string}>
     */
    public function summary(): array
    {
        return [];
    }

    /**
     * How the figures are worked out, shown under the tables.
     */
    public function note(): ?string
    {
        return null;
    }

    public function title(): string
    {
        return static::page()->label();
    }

    public function company(): Company
    {
        return $this->company;
    }

    public function appliedFilters(): ReportFilters
    {
        return $this->filters;
    }

    /**
     * The day range used when none is chosen: this month so far.
     *
     * @return array{0: string, 1: string}
     */
    protected static function defaultDates(string $today): array
    {
        return [substr($today, 0, 8).'01', $today];
    }

    /**
     * The first billing month of the month range used when none is chosen.
     */
    protected static function defaultFromPeriod(string $period): string
    {
        return $period;
    }

    /**
     * Checks the raw filter values (from the URL) and fills in the defaults.
     *
     * @param  array<string, mixed>  $input  Keyed like ReportFilters::query().
     */
    public static function filtersFrom(Company $company, array $input): ReportFilters
    {
        $today = $company->today();
        $currentPeriod = substr($today, 0, 7);
        $value = fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';

        [$defaultFrom, $defaultTo] = static::defaultDates($today);
        $to = self::validDate($value('to'), $today) ?? $defaultTo;
        $from = self::validDate($value('from'), $today) ?? $defaultFrom;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $from = max($from, CarbonImmutable::parse($to)->subDays(self::MAX_DAYS - 1)->toDateString());

        $period = self::validPeriod($value('period'), $currentPeriod) ?? $currentPeriod;
        $fromPeriod = self::validPeriod($value('from_period'), $currentPeriod) ?? static::defaultFromPeriod($period);
        if ($fromPeriod > $period) {
            [$fromPeriod, $period] = [$period, $fromPeriod];
        }
        $fromPeriod = max($fromPeriod, CarbonImmutable::parse($period.'-01')->subMonths(self::MAX_MONTHS - 1)->format('Y-m'));

        $uses = static::filters();
        $pick = function (ReportFilter $filter, string $key, callable $options) use ($uses, $value): array {
            if (! in_array($filter, $uses, true)) {
                return [null, null];
            }
            $id = filter_var($value($key), FILTER_VALIDATE_INT);
            $names = $id === false ? [] : $options();

            return isset($names[$id]) ? [$id, $names[$id]] : [null, null];
        };
        [$zoneId, $zoneName] = $pick(ReportFilter::Zone, 'zone', fn (): array => self::zoneOptions($company));
        [$accountTypeId, $accountTypeName] = $pick(ReportFilter::AccountType, 'account_type', fn (): array => self::accountTypeOptions($company));
        [$cashierId, $cashierName] = $pick(ReportFilter::Cashier, 'cashier', fn (): array => self::cashierOptions($company));

        return new ReportFilters($from, $to, $fromPeriod, $period, $zoneId, $zoneName, $accountTypeId, $accountTypeName, $cashierId, $cashierName);
    }

    /**
     * @return array<int, string> Zone (cluster type) names by id.
     */
    public static function zoneOptions(Company $company): array
    {
        return $company->clusterTypes()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    public static function accountTypeOptions(Company $company): array
    {
        return $company->accountTypes()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string> Everyone in the company, since anyone allowed may record a payment.
     */
    public static function cashierOptions(Company $company): array
    {
        return $company->members()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * The chosen zone, account type and cashier, e.g. "Zone: Zone 1 · Account type: Residential".
     */
    public function filterLabel(): ?string
    {
        $parts = array_filter([
            $this->filters->zoneName === null ? null : 'Zone: '.$this->filters->zoneName,
            $this->filters->accountTypeName === null ? null : 'Account type: '.$this->filters->accountTypeName,
            $this->filters->cashierName === null ? null : 'Received by: '.$this->filters->cashierName,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * Limits a query to the customers in the chosen zone and account type.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function forChosenCustomers(Builder $query, string $column = 'customer_id'): Builder
    {
        if ($this->filters->zoneId === null && $this->filters->accountTypeId === null) {
            return $query;
        }

        return $query->whereIn($column, $this->chosenCustomers()->select('customers.id'));
    }

    /**
     * @return Builder<Customer> The company's customers in the chosen zone and account type.
     */
    protected function chosenCustomers(): Builder
    {
        return Customer::query()
            ->where('customers.company_id', $this->company->id)
            ->when($this->filters->zoneId !== null, fn (Builder $query) => $query->where('customers.cluster_type_id', $this->filters->zoneId))
            ->when($this->filters->accountTypeId !== null, fn (Builder $query) => $query->where('customers.account_type_id', $this->filters->accountTypeId));
    }

    /**
     * Account number, name and zone of the customers in $customerIds, by customer id.
     *
     * @param  iterable<int>  $customerIds
     * @return array<int, array{account: string, name: string, zone: string|null}>
     */
    protected function customerDetails(iterable $customerIds): array
    {
        $ids = collect($customerIds)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return Customer::query()
            ->whereIn('id', $ids)
            ->with('clusterType:id,name')
            ->get(['id', 'account_number', 'first_name', 'last_name', 'company_name', 'cluster_type_id'])
            ->mapWithKeys(fn (Customer $customer): array => [$customer->id => [
                'account' => $customer->account_number,
                'name' => $customer->fullName(),
                'zone' => $customer->clusterType?->name,
            ]])
            ->all();
    }

    /**
     * "October 1 2026 to October 31 2026", or one date when the range is a single day.
     */
    protected function datesLabel(): string
    {
        $from = Format::isoDate($this->filters->from);

        return $this->filters->from === $this->filters->to ? $from : $from.' to '.Format::isoDate($this->filters->to);
    }

    protected function periodLabel(): string
    {
        return BillingRun::periodLabel($this->filters->period);
    }

    /**
     * A date column from the first chosen day through the last. The end is "before the next day", so a
     * date stored with a midnight time still counts on its last day.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function onChosenDates(Builder $query, string $column): Builder
    {
        return $query
            ->where($column, '>=', $this->filters->from)
            ->where($column, '<', CarbonImmutable::parse($this->filters->to)->addDay()->toDateString());
    }

    /**
     * The start of the first chosen day and the end of the last, in UTC, for timestamp columns.
     *
     * @return array{0: string, 1: string}
     */
    protected function chosenMoments(): array
    {
        $timezone = $this->timezone();

        return [
            CarbonImmutable::parse($this->filters->from, $timezone)->startOfDay()->utc()->toDateTimeString(),
            CarbonImmutable::parse($this->filters->to, $timezone)->endOfDay()->utc()->toDateTimeString(),
        ];
    }

    /**
     * The calendar date of a stored moment (UTC) in the company's time zone, as "2026-10-05".
     */
    protected function localDate(mixed $moment): ?string
    {
        if ($moment === null) {
            return null;
        }

        return CarbonImmutable::parse($moment, 'UTC')->setTimezone($this->timezone())->toDateString();
    }

    /**
     * Whole days from $from to $to (both "2026-10-05").
     */
    protected static function daysBetween(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to));
    }

    /**
     * "PHP 1,234.50", for the headline figures.
     */
    protected function money(int $cents): string
    {
        return ($this->company->currency ? $this->company->currency.' ' : '').Format::amount(Money::fromCents($cents));
    }

    protected function timezone(): string
    {
        return $this->company->timezone ?? Format::DEFAULT_TIMEZONE;
    }

    private static function validDate(string $value, string $today): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return min($value, $today);
    }

    private static function validPeriod(string $value, string $currentPeriod): ?string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1 ? min($value, $currentPeriod) : null;
    }
}
