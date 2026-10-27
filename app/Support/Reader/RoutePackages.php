<?php

namespace App\Support\Reader;

use App\Enums\InvoiceStatus;
use App\Enums\WorkOrderStatus;
use App\Models\AccountType;
use App\Models\ChargeType;
use App\Models\ChargeTypeElement;
use App\Models\ClusterType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MeterReading;
use App\Models\MeterType;
use App\Models\RateRange;
use App\Models\RateType;
use App\Models\ReadingAssignment;
use App\Models\RoutePackage;
use App\Models\WorkOrder;
use App\Support\Billing\BillCalculator;
use App\Support\Billing\MeterHistory;
use App\Support\Billing\MeterReadings;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The route package: everything a meter reader's device needs to read its assigned customers and
 * print their bills offline, taken in one snapshot.
 *
 * - config: the company's billing settings, rates, charge types, account and meter types. Its hash
 *   (config_hash) tells a later sync whether any of it changed.
 * - customers: each assigned customer with their meter history (as App\Support\Billing\MeterHistory
 *   works it out), open invoices, unbilled work order fees and any reading already saved for the
 *   period. Each carries a version hash, so a sync can tell which customers changed since.
 * - number_block: the invoice numbers the device prints bills with (App\Support\Reader\NumberBlocks).
 */
final class RoutePackages
{
    /**
     * Raised when the billing rules on the device must change with the server's, so a device on an
     * older version downloads a new package before it prints again.
     */
    public const CALC_VERSION = 1;

    /**
     * Makes a new package for the device and reserves its invoice numbers.
     *
     * @param  ?int  $releaseFrom  The first number of the device's current block it hasn't used.
     * @return array<string, mixed>
     */
    public static function create(Device $device, string $period, ?int $releaseFrom): array
    {
        $company = $device->company;
        $customers = self::assignedCustomers($device, $period)->orderBy('account_number')->get();
        $payloads = self::customerPayloads($company, $customers, $period);
        $config = self::config($company, $device, $period);

        return DB::transaction(function () use ($device, $company, $period, $payloads, $config, $releaseFrom): array {
            $package = RoutePackage::query()->create([
                'company_id' => $company->id,
                'device_id' => $device->id,
                'billing_period' => $period,
                'as_of' => now(),
                'config_hash' => self::hash($config),
                'customer_versions' => array_map(fn (array $customer): string => $customer['version'], $payloads),
            ]);

            $unread = count(array_filter($payloads, fn (array $customer): bool => $customer['reading'] === null));
            $block = NumberBlocks::forPackage($device, $package, $unread, $releaseFrom);

            return [
                'package_id' => $package->id,
                'billing_period' => $period,
                'as_of' => $package->as_of->toIso8601String(),
                'calc_version' => self::CALC_VERSION,
                'config_hash' => $package->config_hash,
                'config' => $config,
                'customers' => array_values($payloads),
                'number_block' => $block,
            ];
        });
    }

    /**
     * Customers of the clusters assigned to the device's reader for the period, the same ones the
     * Meter Readings page lists for those clusters.
     *
     * @return Builder<Customer>
     */
    public static function assignedCustomers(Device $device, string $period): Builder
    {
        return MeterReadings::customers($device->company, $period, null, '')
            ->whereIn('cluster_type_id', self::assignedClusterIds($device, $period));
    }

    /**
     * @return Collection<int, int>
     */
    private static function assignedClusterIds(Device $device, string $period): Collection
    {
        return ReadingAssignment::query()
            ->where('user_id', $device->user_id)
            ->where('billing_period', $period)
            ->pluck('cluster_type_id');
    }

    /**
     * What changed since the package: customers whose version differs (or who were added to or
     * removed from the route), and the config when it changed.
     *
     * @return array{as_of: string, config_hash: string, config: ?array<string, mixed>, customers: list<array<string, mixed>>, removed: list<int>}
     */
    public static function changesSince(RoutePackage $package): array
    {
        $device = $package->device;
        $company = $device->company;
        $customers = self::assignedCustomers($device, $package->billing_period)->get();
        $payloads = self::customerPayloads($company, $customers, $package->billing_period);
        $versions = $package->customer_versions;
        $config = self::config($company, $device, $package->billing_period);
        $configHash = self::hash($config);

        $changed = array_filter($payloads, fn (array $customer): bool => ($versions[$customer['id']] ?? null) !== $customer['version']);
        $removed = array_values(array_diff(array_map('intval', array_keys($versions)), array_keys($payloads)));

        return [
            'as_of' => now()->toIso8601String(),
            'config_hash' => $configHash,
            'config' => $configHash === $package->config_hash ? null : $config,
            'customers' => array_values($changed),
            'removed' => $removed,
        ];
    }

    /**
     * The customer's version now, to compare with the one in a package.
     */
    public static function customerVersion(Customer $customer, string $period): string
    {
        return self::customerPayloads($customer->company, collect([$customer]), $period)[$customer->id]['version'];
    }

    /**
     * The hash of the company's billing config as a device would download it now.
     */
    public static function configHash(Company $company, Device $device, string $period): string
    {
        return self::hash(self::config($company, $device, $period));
    }

    /**
     * Billing settings, rates and charges: what the device's billing code needs besides the customers.
     *
     * @return array<string, mixed>
     */
    public static function config(Company $company, Device $device, string $period): array
    {
        return self::configFor($company, self::assignedClusterIds($device, $period));
    }

    /**
     * The config with the given clusters, e.g. for test vectors that belong to no device.
     *
     * @param  Collection<int, int>  $clusterIds
     * @return array<string, mixed>
     */
    public static function configFor(Company $company, Collection $clusterIds): array
    {
        return [
            'calc_version' => self::CALC_VERSION,
            'company' => [
                'name' => $company->company_name,
                'address' => $company->location(),
                'tax_id_number' => $company->tax_id_number,
                'timezone' => $company->timezone,
                'currency' => $company->currency,
                'billing_headers' => array_values(array_filter([$company->billing_header_1, $company->billing_header_2, $company->billing_header_3])),
                'billing_footers' => array_values(array_filter([$company->billing_footer_1, $company->billing_footer_2, $company->billing_footer_3])),
                'due_date_days' => (int) ($company->due_date_days ?? 0),
                'overdue_handling' => $company->overdue_handling?->value,
                'tax_enabled' => (bool) $company->tax_enabled,
                'tax_name' => $company->tax_name,
                'tax_rate' => (string) $company->tax_rate,
                'tax_inclusive' => (bool) $company->tax_inclusive,
                'tax_on_water' => (bool) $company->tax_on_water,
                'tax_on_fees' => (bool) $company->tax_on_fees,
                'tax_on_surcharges' => (bool) $company->tax_on_surcharges,
                'review_usage_multiplier' => (string) $company->review_usage_multiplier,
                'review_zero_usage' => (bool) $company->review_zero_usage,
                'readers_confirm_flagged' => (bool) $company->readers_confirm_flagged,
            ],
            'rate_types' => RateType::query()->where('company_id', $company->id)->with(['ranges', 'setup'])->orderBy('id')->get()
                ->map(fn (RateType $rate): array => [
                    'id' => $rate->id,
                    'name' => $rate->name,
                    'minimum_consumption' => $rate->setup?->minimum_consumption === null ? null : (string) $rate->setup->minimum_consumption,
                    'defective_uses_minimum_consumption' => (bool) $rate->setup?->defective_uses_minimum_consumption,
                    'defective_uses_three_month_average' => (bool) $rate->setup?->defective_uses_three_month_average,
                    'ranges' => $rate->ranges->map(fn (RateRange $range): array => [
                        'range_from' => (int) $range->range_from,
                        'range_to' => (int) $range->range_to,
                        'rate' => (string) $range->rate,
                        'description' => $range->description,
                    ])->values()->all(),
                ])->all(),
            'charge_types' => ChargeType::query()->where('company_id', $company->id)->with('elements.chargeElement')->orderBy('id')->get()
                ->map(fn (ChargeType $type): array => [
                    'id' => $type->id,
                    'name' => $type->name,
                    'elements' => $type->elements->filter(fn (ChargeTypeElement $row): bool => $row->chargeElement !== null)
                        ->map(fn (ChargeTypeElement $row): array => [
                            'charge_element_id' => $row->chargeElement->id,
                            'name' => $row->chargeElement->name,
                            'distribution_type' => $row->chargeElement->distribution_type->value,
                            'is_after_due' => (bool) $row->chargeElement->is_after_due,
                            'is_taxable' => (bool) $row->chargeElement->is_taxable,
                            'value_type' => $row->value_type->value,
                            'value' => (string) $row->value,
                        ])->values()->all(),
                ])->all(),
            'account_types' => AccountType::query()->where('company_id', $company->id)->orderBy('id')->get()
                ->map(fn (AccountType $type): array => ['id' => $type->id, 'name' => $type->name, 'is_tax_exempt' => (bool) $type->is_tax_exempt])->all(),
            'meter_types' => MeterType::query()->where('company_id', $company->id)->orderBy('id')->get()
                ->map(fn (MeterType $type): array => ['id' => $type->id, 'name' => $type->name, 'dial_digits' => $type->dial_digits])->all(),
            'clusters' => ClusterType::query()->whereIn('id', $clusterIds)->orderBy('name')->get()
                ->map(fn (ClusterType $cluster): array => ['id' => $cluster->id, 'name' => $cluster->name])->all(),
        ];
    }

    /**
     * Each customer as the device stores it, keyed by customer id.
     *
     * @param  Collection<int, Customer>  $customers
     * @return array<int, array<string, mixed>>
     */
    public static function customerPayloads(Company $company, Collection $customers, string $period): array
    {
        $ids = $customers->pluck('id');

        $openInvoices = Invoice::query()
            ->whereIn('customer_id', $ids)
            ->whereIn('status', InvoiceStatus::openValues())
            ->orderBy('due_date')->orderBy('id')
            ->get()->groupBy('customer_id');

        $fees = WorkOrder::query()
            ->whereIn('customer_id', $ids)
            ->where('status', WorkOrderStatus::Completed)
            ->whereNotNull('fee_amount')
            ->where('fee_amount', '>', 0)
            ->whereNull('fee_invoice_id')
            ->orderBy('id')
            ->get()->groupBy('customer_id');

        $readings = MeterReading::query()
            ->whereIn('customer_id', $ids)
            ->where('billing_period', $period)
            ->with('invoice.lines')
            ->get()->keyBy('customer_id');

        $payloads = [];
        foreach ($customers as $customer) {
            $customer->setRelation('company', $company);
            $history = MeterHistory::for($customer, $period);
            $reading = $readings->get($customer->id);

            $payload = [
                'id' => $customer->id,
                'account_number' => $customer->account_number,
                'name' => $customer->company_name ?: $customer->fullName(),
                'address' => $customer->home_address,
                'cluster_type_id' => $customer->cluster_type_id,
                'status' => $customer->status->value,
                'meter_number' => $customer->meter_number,
                'rate_type_id' => $customer->rate_type_id,
                'charge_type_id' => $customer->charge_type_id,
                'account_type_id' => $customer->account_type_id,
                'meter_type_id' => $customer->meter_type_id,
                'latitude' => $customer->latitude,
                'longitude' => $customer->longitude,
                'history' => [
                    'previous' => self::quantity($history['previous']),
                    'replaced_consumption' => self::quantity($history['replacedConsumption']),
                    'final_reading' => $history['finalReading'] === null ? null : self::quantity($history['finalReading']),
                    'recent_consumptions' => array_map(self::quantity(...), $history['recentConsumptions']),
                    'estimated_since' => self::quantity($history['estimatedSince']),
                ],
                'open_invoices' => ($openInvoices->get($customer->id) ?? collect())->map(fn (Invoice $invoice): array => [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'due_date' => $invoice->due_date->toDateString(),
                    'balance_cents' => Money::toCents($invoice->balance),
                    'surcharged' => $invoice->surcharged_at !== null,
                ])->values()->all(),
                'unbilled_fees' => ($fees->get($customer->id) ?? collect())->map(fn (WorkOrder $order): array => [
                    'work_order_id' => $order->id,
                    'description' => "{$order->order_type->label()} fee (work order {$order->order_number})",
                    'cents' => Money::toCents($order->fee_amount),
                ])->values()->all(),
                'reading' => $reading === null ? null : [
                    'status' => $reading->status->value,
                    'reading_code' => $reading->reading_code->value,
                    'present_reading' => $reading->present_reading,
                    'invoice_number' => self::issuedBill($reading)?->invoice_number,
                    'bill' => self::billPayload($reading),
                ],
            ];
            $payload['open_balance_cents'] = array_sum(array_column($payload['open_invoices'], 'balance_cents'));
            $payload['version'] = self::hash($payload);

            $payloads[$customer->id] = $payload;
        }

        return $payloads;
    }

    /**
     * The reading's bill, unless it was voided.
     */
    private static function issuedBill(MeterReading $reading): ?Invoice
    {
        return $reading->invoice !== null && $reading->invoice->status !== InvoiceStatus::Void ? $reading->invoice : null;
    }

    /**
     * The period's bill exactly as it was issued, so a reader can show or reprint it, whether the
     * office or a phone issued it.
     *
     * @return ?array<string, mixed>
     */
    private static function billPayload(MeterReading $reading): ?array
    {
        $invoice = self::issuedBill($reading);
        if ($invoice === null) {
            return null;
        }

        return [
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'due_date' => $invoice->due_date->toDateString(),
            'previous_reading' => $reading->previous_reading === null ? null : self::quantity((float) $reading->previous_reading),
            'usage' => self::quantity($reading->usage()),
            'lines' => $invoice->lines->map(fn (InvoiceLine $line): array => [
                'kind' => $line->kind->value,
                'description' => $line->description,
                'quantity' => $line->quantity === null ? null : BillCalculator::quantity((float) $line->quantity),
                'rate' => $line->rate === null ? null : (string) $line->rate,
                'cents' => Money::toCents($line->amount),
            ])->values()->all(),
            'tax' => [
                'name' => $invoice->tax_name,
                'rate' => $invoice->tax_rate === null ? null : (string) $invoice->tax_rate,
                'inclusive' => (bool) $invoice->tax_inclusive,
                'taxableCents' => Money::toCents($invoice->taxable_amount),
                'taxCents' => Money::toCents($invoice->tax_amount),
                'exemptCents' => Money::toCents($invoice->tax_exempt_amount),
            ],
            'total_cents' => Money::toCents($invoice->total_amount),
            'previous_balance_cents' => Money::toCents($invoice->previous_balance),
        ];
    }

    /**
     * Cubic meters as text with three decimals, so the device and the server read the same number.
     */
    private static function quantity(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
