<?php

namespace App\Support\Reports;

/**
 * The filters a report runs with, already checked: dates are real days no later than today, months
 * are "2026-10", and the chosen zone, account type and cashier belong to the company (else null).
 */
final readonly class ReportFilters
{
    public function __construct(
        public string $from,
        public string $to,
        public string $fromPeriod,
        public string $period,
        public ?int $zoneId = null,
        public ?string $zoneName = null,
        public ?int $accountTypeId = null,
        public ?string $accountTypeName = null,
        public ?int $cashierId = null,
        public ?string $cashierName = null,
    ) {}

    /**
     * The filters as URL query values, e.g. for the Print and Export links.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return array_filter([
            'from' => $this->from,
            'to' => $this->to,
            'from_period' => $this->fromPeriod,
            'period' => $this->period,
            'zone' => $this->zoneId === null ? '' : (string) $this->zoneId,
            'account_type' => $this->accountTypeId === null ? '' : (string) $this->accountTypeId,
            'cashier' => $this->cashierId === null ? '' : (string) $this->cashierId,
        ], fn (string $value): bool => $value !== '');
    }
}
