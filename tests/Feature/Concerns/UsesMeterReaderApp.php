<?php

namespace Tests\Feature\Concerns;

use App\Enums\Access;
use App\Enums\Page;
use App\Enums\ReadingKind;
use App\Enums\WorkOrderType;
use App\Models\ChargeType;
use App\Models\ClusterType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\RateType;
use App\Models\ReadingAssignment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderReading;
use App\Support\Billing\BillingRun;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A company with a meter reader assigned to one cluster, and helpers that act as the reader's phone:
 * register, download the route, and upload readings with the bills a correct device prints.
 */
trait UsesMeterReaderApp
{
    protected User $owner;

    protected Company $company;

    protected User $reader;

    protected ClusterType $cluster;

    protected RateType $rate;

    protected ChargeType $chargeType;

    protected string $period;

    protected string $today;

    protected function setUpMeterReaderApp(): void
    {
        [$this->owner, $this->company] = $this->ownerWithCompany();
        // The tests' own setup and checks run as the owner, so they see only this company's records.
        // Each API request still signs in as its device's reader.
        $this->actingAs($this->owner);
        $this->company->update(['due_date_days' => 15]);
        $this->today = BillingRun::today($this->company);
        $this->period = substr($this->today, 0, 7);

        // 0–10 @ 10.00, 11–20 @ 25.00, 21+ @ 35.00, minimum 10 cu.m.
        $this->rate = RateType::factory()->for($this->company)->create(['name' => 'Residential']);
        $this->rate->ranges()->createMany([
            ['range_from' => 0, 'range_to' => 10, 'rate' => '10.00', 'description' => '0-10 cu.m.'],
            ['range_from' => 11, 'range_to' => 20, 'rate' => '25.00', 'description' => '11-20 cu.m.'],
            ['range_from' => 21, 'range_to' => 99999, 'rate' => '35.00', 'description' => 'Above 20 cu.m.'],
        ]);
        $this->rate->setup()->create(['minimum_consumption' => 10, 'defective_uses_minimum_consumption' => true]);
        $this->chargeType = ChargeType::factory()->for($this->company)->create();

        $this->cluster = ClusterType::factory()->create(['company_id' => $this->company->id, 'name' => 'Purok 1']);
        $this->reader = $this->memberWithAccess($this->company, Page::MeterReadings, [Access::Add, Access::View]);
        ReadingAssignment::query()->create([
            'company_id' => $this->company->id,
            'billing_period' => $this->period,
            'cluster_type_id' => $this->cluster->id,
            'user_id' => $this->reader->id,
        ]);
    }

    /**
     * A connected customer in the reader's cluster whose meter was installed at 0.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function routeCustomer(array $attributes = []): Customer
    {
        $customer = Customer::factory()->for($this->company)->connected()->create([
            'rate_type_id' => $this->rate->id,
            'charge_type_id' => $this->chargeType->id,
            'cluster_type_id' => $this->cluster->id,
            ...$attributes,
        ]);

        $installation = WorkOrder::factory()->forCustomer($customer, WorkOrderType::NewInstallation)->completed()->create();
        WorkOrderReading::query()->create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id, 'work_order_id' => $installation->id,
            'meter_number' => $customer->meter_number, 'kind' => ReadingKind::Initial, 'reading' => '0', 'read_on' => now()->subMonths(4)->toDateString(),
        ]);

        return $customer;
    }

    /**
     * Registers a phone for the reader and returns its token.
     */
    protected function registerDevice(?User $user = null): string
    {
        return $this->postJson(route('reader.devices.register'), [
            'login' => ($user ?? $this->reader)->email,
            'password' => 'password1',
            'device_name' => 'RDR-03',
        ])->assertCreated()->json('token');
    }

    /**
     * Downloads the route package.
     *
     * @return array<string, mixed>
     */
    protected function downloadPackage(string $token, ?string $releaseFrom = null): array
    {
        return $this->withToken($token)
            ->postJson(route('reader.packages.store'), array_filter(['release_from' => $releaseFrom]))
            ->assertCreated()
            ->json();
    }

    /**
     * A reading item with the bill a correct device prints: the server's own figures as of now.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function readingItem(array $package, Customer $customer, string $present, string $invoiceNumber, array $overrides = []): array
    {
        $bill = BillingRun::compute($customer->fresh(), $this->period, $present, false, $this->today);

        return [
            'client_uuid' => (string) Str::uuid(),
            'type' => 'reading',
            'customer_id' => $customer->id,
            'billing_period' => $this->period,
            'meter_number' => $customer->meter_number,
            'reading_code' => 'normal',
            'present_reading' => $present,
            'previous_reading' => (string) $bill['previous'],
            'flags' => [],
            'confirmed_by_reader' => false,
            'read_at' => now()->toIso8601String(),
            'customer_version' => collect($package['customers'])->firstWhere('id', $customer->id)['version'] ?? null,
            'config_hash' => $package['config_hash'],
            'bill' => [
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $this->today,
                'lines' => array_map(fn (array $line): array => [
                    'kind' => $line['kind']->value,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'cents' => $line['cents'],
                    'charge_element_id' => $line['charge_element_id'] ?? null,
                    'work_order_id' => $line['work_order_id'] ?? null,
                    'source_invoice_id' => $line['source_invoice_id'] ?? null,
                ], $bill['lines']),
                'tax' => $bill['tax'],
                'total_cents' => $bill['totalCents'],
                'previous_balance_cents' => $bill['previousBalanceCents'],
                'printed_at' => now()->toIso8601String(),
            ],
            ...$overrides,
        ];
    }

    /**
     * Uploads the items as one batch and commits it.
     *
     * @param  list<array<string, mixed>>  $items
     */
    protected function sync(string $token, array $package, array $items): TestResponse
    {
        $uuids = array_column($items, 'client_uuid');
        sort($uuids);

        $batchId = $this->withToken($token)->postJson(route('reader.sync.store'), [
            'package_id' => $package['package_id'],
            'item_count' => count($items),
            'checksum' => hash('sha256', implode("\n", $uuids)),
            'device_time' => now()->toIso8601String(),
        ])->assertCreated()->json('batch_id');

        $this->withToken($token)->putJson(route('reader.sync.items', $batchId), ['items' => $items])->assertOk();

        return $this->withToken($token)->postJson(route('reader.sync.commit', $batchId));
    }
}
