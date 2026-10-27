<?php

namespace Tests\Feature;

use App\Enums\Access;
use App\Enums\InvoiceStatus;
use App\Enums\NumberGapReason;
use App\Enums\Page;
use App\Enums\ReadingCode;
use App\Models\ClusterType;
use App\Models\Customer;
use App\Models\Device;
use App\Models\InvoiceNumberBlock;
use App\Models\InvoiceNumberGap;
use App\Support\Billing\BillingRun;
use App\Support\Billing\MeterReadings;
use App\Support\Reader\RoutePackages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\UsesMeterReaderApp;
use Tests\TestCase;

class MeterReaderApiTest extends TestCase
{
    use RefreshDatabase, UsesMeterReaderApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMeterReaderApp();
    }

    public function test_registering_returns_201_with_a_token_stored_only_as_a_hash(): void
    {
        $token = $this->registerDevice();

        $device = Device::query()->sole();
        $this->assertSame($this->reader->id, $device->user_id);
        $this->assertSame(hash('sha256', $token), $device->token_hash);
        $this->assertNotSame($token, $device->token_hash);
    }

    public function test_registering_with_a_wrong_password_returns_401(): void
    {
        $this->postJson(route('reader.devices.register'), ['login' => $this->reader->email, 'password' => 'wrong', 'device_name' => 'RDR-03'])
            ->assertUnauthorized()
            ->assertJson(['message' => 'Incorrect email address, username or password.']);

        $this->assertSame(0, Device::query()->withoutGlobalScopes()->count());
    }

    public function test_registering_returns_403_when_the_role_cannot_enter_readings(): void
    {
        $viewer = $this->memberWithAccess($this->company, Page::MeterReadings, [Access::View]);

        $this->postJson(route('reader.devices.register'), ['login' => $viewer->email, 'password' => 'password1', 'device_name' => 'RDR-03'])
            ->assertForbidden()
            ->assertJson(['message' => 'Your role doesn\'t allow entering meter readings. Ask your administrator for access.']);
    }

    public function test_returns_401_without_a_token_or_with_a_revoked_one(): void
    {
        $this->postJson(route('reader.packages.store'))->assertUnauthorized();

        $token = $this->registerDevice();
        Device::query()->withoutGlobalScopes()->update(['revoked_at' => now()]);

        $this->withToken($token)->postJson(route('reader.packages.store'))->assertUnauthorized();
    }

    public function test_returns_401_once_the_reader_is_deactivated(): void
    {
        $token = $this->registerDevice();
        $this->reader->update(['deactivated_at' => now()]);

        $this->withToken($token)->postJson(route('reader.packages.store'))->assertUnauthorized();
    }

    public function test_the_package_holds_only_customers_of_the_assigned_clusters_with_their_history(): void
    {
        $onRoute = $this->routeCustomer();
        $this->routeCustomer(['cluster_type_id' => ClusterType::factory()->create(['company_id' => $this->company->id])->id]);
        $token = $this->registerDevice();

        $package = $this->downloadPackage($token);

        $this->assertSame([$onRoute->id], array_column($package['customers'], 'id'));
        $this->assertSame('0.000', $package['customers'][0]['history']['previous']);
        $this->assertSame([['id' => $this->cluster->id, 'name' => 'Purok 1']], $package['config']['clusters']);
        $this->assertSame($this->period, $package['billing_period']);
    }

    public function test_the_package_reserves_numbers_that_office_invoices_skip_past(): void
    {
        $this->routeCustomer();
        $office = $this->routeCustomer(['cluster_type_id' => ClusterType::factory()->create(['company_id' => $this->company->id])->id]);
        $token = $this->registerDevice();

        $block = $this->downloadPackage($token)['number_block'];

        $this->assertSame(['first' => '000001', 'last' => '000010', 'next' => '000001'], $block);
        $invoice = BillingRun::generate($office, $this->period, '12', false, $this->today, $this->today, $this->owner);
        $this->assertSame('000011', $invoice->invoice_number);
    }

    public function test_a_new_package_keeps_the_block_while_enough_numbers_are_left(): void
    {
        $this->routeCustomer();
        $token = $this->registerDevice();
        $this->downloadPackage($token);

        $block = $this->downloadPackage($token, '000002')['number_block'];

        $this->assertSame(['first' => '000001', 'last' => '000010', 'next' => '000002'], $block);
        $this->assertSame(0, InvoiceNumberGap::query()->count());
    }

    public function test_a_new_package_hands_back_the_unused_rest_of_a_block_that_is_running_out(): void
    {
        $this->routeCustomer();
        $token = $this->registerDevice();
        $this->downloadPackage($token);

        $this->routeCustomer();
        $this->routeCustomer();

        // 3 unread meters + 10% need 4 numbers; only 000009 and 000010 are left.
        $block = $this->downloadPackage($token, '000009')['number_block'];

        $this->assertSame(['first' => '000011', 'last' => '000020', 'next' => '000011'], $block);
        $this->assertSame(['000009', '000010'], InvoiceNumberGap::query()->orderBy('invoice_number')->pluck('invoice_number')->all());
        $this->assertSame(NumberGapReason::Unused, InvoiceNumberGap::query()->first()->reason);
    }

    public function test_changes_lists_only_customers_that_changed_since_the_package(): void
    {
        $changed = $this->routeCustomer();
        $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);

        MeterReadings::save($changed, $this->period, '14', ReadingCode::Normal, $this->today, $this->owner);

        $changes = $this->withToken($token)->getJson(route('reader.packages.changes', $package['package_id']))->assertOk()->json();
        $this->assertSame([$changed->id], array_column($changes['customers'], 'id'));
        $this->assertNull($changes['config']);
        $this->assertSame('14.000', $changes['customers'][0]['reading']['present_reading']);
    }

    public function test_the_package_carries_the_periods_bill_as_the_office_issued_it(): void
    {
        $customer = $this->routeCustomer();
        $invoice = BillingRun::generate($customer, $this->period, '15', false, $this->today, $this->today, $this->owner);

        $reading = $this->downloadPackage($this->registerDevice())['customers'][0]['reading'];

        $this->assertSame($invoice->invoice_number, $reading['invoice_number']);
        $this->assertSame($invoice->invoice_number, $reading['bill']['invoice_number']);
        $this->assertSame('0.000', $reading['bill']['previous_reading']);
        $this->assertSame('15.000', $reading['bill']['usage']);
        $this->assertSame([
            ['kind' => 'water', 'description' => '0-10 cu.m.', 'quantity' => '10', 'rate' => '10.00', 'cents' => 10000],
            ['kind' => 'water', 'description' => '11-20 cu.m.', 'quantity' => '5', 'rate' => '25.00', 'cents' => 12500],
        ], $reading['bill']['lines']);
        $this->assertSame(22500, $reading['bill']['total_cents']);
        $this->assertSame($invoice->due_date->toDateString(), $reading['bill']['due_date']);
    }

    public function test_the_package_leaves_out_a_voided_bill(): void
    {
        $customer = $this->routeCustomer();
        BillingRun::generate($customer, $this->period, '15', false, $this->today, $this->today, $this->owner)
            ->update(['status' => InvoiceStatus::Void]);

        $reading = $this->downloadPackage($this->registerDevice())['customers'][0]['reading'];

        $this->assertNull($reading['bill']);
        $this->assertNull($reading['invoice_number']);
    }

    public function test_an_office_reading_not_billed_yet_has_no_bill_in_the_package(): void
    {
        $customer = $this->routeCustomer();
        MeterReadings::save($customer, $this->period, '14', ReadingCode::Normal, $this->today, $this->owner);

        $reading = $this->downloadPackage($this->registerDevice())['customers'][0]['reading'];

        $this->assertSame('14.000', $reading['present_reading']);
        $this->assertNull($reading['bill']);
    }

    public function test_changes_of_another_devices_package_returns_404(): void
    {
        $this->routeCustomer();
        $package = $this->downloadPackage($this->registerDevice());
        $otherToken = $this->registerDevice($this->memberWithAccess($this->company, Page::MeterReadings, [Access::Add]));

        $this->withToken($otherToken)->getJson(route('reader.packages.changes', $package['package_id']))->assertNotFound();
    }

    public function test_status_tells_an_app_older_than_the_minimum_to_update_and_stores_its_version(): void
    {
        config(['reader.minimum_app_version' => '1.2.0']);
        $token = $this->registerDevice();

        $this->withToken($token)->getJson(route('reader.status', ['app_version' => '1.1.9']))
            ->assertOk()
            ->assertJsonPath('update_required', true)
            ->assertJsonPath('signed_out', false)
            ->assertJsonPath('calc_version', RoutePackages::CALC_VERSION)
            ->assertJsonPath('clock_drift_minutes', 10);

        $this->assertSame('1.1.9', Device::query()->sole()->app_version);
        $this->withToken($token)->getJson(route('reader.status', ['app_version' => '1.2.0']))->assertJsonPath('update_required', false);
    }

    public function test_status_tells_a_signed_out_device_it_was_signed_out(): void
    {
        $token = $this->registerDevice();
        Device::query()->update(['revoked_at' => now()]);

        $this->withToken($token)->getJson(route('reader.status'))->assertOk()->assertJsonPath('signed_out', true);
    }

    public function test_releasing_a_block_records_the_unused_rest_and_closes_it(): void
    {
        $this->routeCustomer();
        $token = $this->registerDevice();
        $this->downloadPackage($token);

        $this->withToken($token)->postJson(route('reader.blocks.release'), ['release_from' => '000008'])
            ->assertOk()
            ->assertExactJson(['first' => '000001', 'last' => '000010', 'unused' => 3]);

        $this->assertSame(['000008', '000009', '000010'], InvoiceNumberGap::query()->orderBy('invoice_number')->pluck('invoice_number')->all());
        $this->assertSame(NumberGapReason::Unused, InvoiceNumberGap::query()->first()->reason);
        $this->assertNotNull(InvoiceNumberBlock::query()->sole()->closed_at);
    }

    public function test_releasing_a_block_whose_numbers_were_all_used_records_no_gaps(): void
    {
        $this->routeCustomer();
        $token = $this->registerDevice();
        $this->downloadPackage($token);

        $this->withToken($token)->postJson(route('reader.blocks.release'), ['release_from' => '000011'])
            ->assertOk()
            ->assertJsonPath('unused', 0);

        $this->assertSame(0, InvoiceNumberGap::query()->count());
        $this->assertNotNull(InvoiceNumberBlock::query()->sole()->closed_at);
    }

    public function test_releasing_a_number_outside_the_open_block_returns_422(): void
    {
        $this->routeCustomer();
        $token = $this->registerDevice();
        $this->downloadPackage($token);

        $this->withToken($token)->postJson(route('reader.blocks.release'), ['release_from' => '000050'])
            ->assertUnprocessable()
            ->assertJson(['message' => 'That number isn\'t in an open block of this device. Download the route again to see its numbers.']);

        $this->assertNull(InvoiceNumberBlock::query()->sole()->closed_at);
    }

    public function test_releasing_another_devices_block_returns_422(): void
    {
        $this->routeCustomer();
        $this->downloadPackage($this->registerDevice());
        $otherToken = $this->registerDevice($this->memberWithAccess($this->company, Page::MeterReadings, [Access::Add]));

        $this->withToken($otherToken)->postJson(route('reader.blocks.release'), ['release_from' => '000001'])->assertUnprocessable();

        $this->assertNull(InvoiceNumberBlock::query()->sole()->closed_at);
    }

    public function test_the_package_leaves_out_customers_of_another_company(): void
    {
        $this->routeCustomer();
        [, $otherCompany] = $this->ownerWithCompany();
        Customer::factory()->for($otherCompany)->connected()->create(['cluster_type_id' => $this->cluster->id]);

        $package = $this->downloadPackage($this->registerDevice());

        $this->assertCount(1, $package['customers']);
    }
}
