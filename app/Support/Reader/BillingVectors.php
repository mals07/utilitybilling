<?php

namespace App\Support\Reader;

use App\Enums\ReadingCode;
use App\Models\Company;
use App\Models\Customer;
use App\Support\Billing\BillingException;
use App\Support\Billing\BillingRun;
use App\Support\Billing\MeterReadings;

/**
 * Test cases for the meter reader app's billing code: real customers of a company in the route
 * package's shape, a few readings for each, and the bill the server works out for every one. The
 * app's tests run each case through its own billing code and fail on any difference, so the phone
 * and the server keep billing alike to the centavo.
 */
final class BillingVectors
{
    /**
     * Usage added to each customer's previous reading for the normal-reading cases.
     */
    public const USAGES = [0, 7, 15, 40];

    /**
     * @return array<string, mixed>
     */
    public static function export(Company $company, string $period, int $limit): array
    {
        $invoiceDate = BillingRun::today($company);
        $customers = MeterReadings::customers($company, $period, null, '')
            ->whereNotNull('rate_type_id')
            ->orderBy('account_number')
            ->limit($limit)
            ->get();
        $payloads = RoutePackages::customerPayloads($company, $customers, $period);

        $cases = [];
        foreach ($customers as $customer) {
            $previous = (float) $payloads[$customer->id]['history']['previous'];

            foreach (self::USAGES as $usage) {
                $cases[] = self::case($customer, $payloads[$customer->id], $period, $invoiceDate, ReadingCode::Normal, (string) ($previous + $usage));
            }
            foreach ([ReadingCode::NoAccess, ReadingCode::Defective] as $code) {
                $cases[] = self::case($customer, $payloads[$customer->id], $period, $invoiceDate, $code, '');
            }
        }

        return [
            'calc_version' => RoutePackages::CALC_VERSION,
            'generated_at' => now()->toIso8601String(),
            'billing_period' => $period,
            'invoice_date' => $invoiceDate,
            'config' => RoutePackages::configFor($company, $customers->pluck('cluster_type_id')->unique()->values()),
            'cases' => $cases,
        ];
    }

    /**
     * One reading and the outcome the device must reproduce: the bill, or the error it must show.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function case(Customer $customer, array $payload, string $period, string $invoiceDate, ReadingCode $code, string $present): array
    {
        $input = ['customer' => $payload, 'reading_code' => $code->value, 'present_reading' => $present === '' ? null : $present];

        try {
            $reading = MeterReadings::evaluate($customer, $period, $present, $code);
            $bill = BillingRun::bill($customer, $reading['usage'], $invoiceDate);
        } catch (BillingException $exception) {
            return [...$input, 'expected' => ['error' => $exception->getMessage()]];
        }

        return [...$input, 'expected' => [
            'previous' => $reading['previous'],
            'usage' => $reading['usage'],
            'estimated' => $reading['estimated'],
            'flags' => $reading['flags'],
            'billed' => $bill['billed'],
            'minimum_applied' => $bill['minimumApplied'],
            'lines' => array_map(fn (array $line): array => [
                'kind' => $line['kind']->value,
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'rate' => $line['rate'],
                'cents' => $line['cents'],
            ], $bill['lines']),
            'tax' => $bill['tax'],
            'total_cents' => $bill['totalCents'],
            'previous_balance_cents' => $bill['previousBalanceCents'],
        ]];
    }
}
