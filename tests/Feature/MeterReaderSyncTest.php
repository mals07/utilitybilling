<?php

namespace Tests\Feature;

use App\Enums\Access;
use App\Enums\DiscrepancySeverity;
use App\Enums\MeterReadingStatus;
use App\Enums\NumberGapReason;
use App\Enums\Page;
use App\Enums\PaymentMethod;
use App\Enums\ReadingCode;
use App\Enums\SyncCheck;
use App\Enums\SyncItemStatus;
use App\Enums\SyncResolution;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\InvoiceNumberGap;
use App\Models\MeterReading;
use App\Models\SyncItem;
use App\Support\Billing\BillingException;
use App\Support\Billing\BillingRun;
use App\Support\Billing\Ledger;
use App\Support\Billing\MeterReadings;
use App\Support\Reader\SyncItems;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\UsesMeterReaderApp;
use Tests\TestCase;

class MeterReaderSyncTest extends TestCase
{
    use RefreshDatabase, UsesMeterReaderApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMeterReaderApp();
    }

    public function test_a_matching_reading_is_saved_and_billed_with_the_printed_invoice_number(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $item = $this->readingItem($package, $customer, '15', '000003');

        $this->sync($token, $package, [$item])
            ->assertOk()
            ->assertJsonPath('items.0.status', 'applied')
            ->assertJsonPath('items.0.invoice_number', '000003');

        $invoice = Invoice::query()->sole();
        $this->assertSame('000003', $invoice->invoice_number);
        $this->assertSame('225.00', $invoice->total_amount);
        $this->assertSame(Device::query()->sole()->id, $invoice->device_id);
        $this->assertNotNull($invoice->printed_at);
        $reading = MeterReading::query()->sole();
        $this->assertSame(MeterReadingStatus::Billed, $reading->status);
        $this->assertSame($this->reader->id, $reading->created_by_id);
        $this->assertSame('15.000', $reading->present_reading);
    }

    public function test_committing_or_uploading_the_same_items_again_saves_nothing_twice(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $item = $this->readingItem($package, $customer, '15', '000001');

        $first = $this->sync($token, $package, [$item])->assertOk()->json('items');
        $second = $this->sync($token, $package, [$item])->assertOk()->json('items');

        $this->assertSame($first, $second);
        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(1, MeterReading::query()->count());
    }

    public function test_commit_returns_422_until_every_item_has_arrived(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $item = $this->readingItem($package, $customer, '15', '000001');

        $batchId = $this->withToken($token)->postJson(route('reader.sync.store'), [
            'package_id' => $package['package_id'], 'item_count' => 2, 'checksum' => str_repeat('a', 64), 'device_time' => now()->toIso8601String(),
        ])->json('batch_id');
        $this->withToken($token)->putJson(route('reader.sync.items', $batchId), ['items' => [$item]])->assertOk();

        $this->withToken($token)->postJson(route('reader.sync.commit', $batchId))
            ->assertUnprocessable()
            ->assertJson(['message' => 'Received 1 of 2 items. Upload the rest, then commit again.']);
        $this->assertSame(0, MeterReading::query()->count());
    }

    public function test_commit_returns_422_when_the_checksum_does_not_match(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);

        $batchId = $this->withToken($token)->postJson(route('reader.sync.store'), [
            'package_id' => $package['package_id'], 'item_count' => 1, 'checksum' => str_repeat('a', 64), 'device_time' => now()->toIso8601String(),
        ])->json('batch_id');
        $this->withToken($token)->putJson(route('reader.sync.items', $batchId), ['items' => [$this->readingItem($package, $customer, '15', '000001')]]);

        $this->withToken($token)->postJson(route('reader.sync.commit', $batchId))
            ->assertUnprocessable()
            ->assertJson(['message' => 'The batch checksum doesn\'t match the items received. Upload the batch again.']);
    }

    public function test_another_devices_batch_returns_404(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $batchId = $this->withToken($token)->postJson(route('reader.sync.store'), [
            'package_id' => $package['package_id'], 'item_count' => 1, 'checksum' => str_repeat('a', 64), 'device_time' => now()->toIso8601String(),
        ])->json('batch_id');
        $otherToken = $this->registerDevice($this->memberWithAccess($this->company, Page::MeterReadings, [Access::Add]));

        $this->withToken($otherToken)->putJson(route('reader.sync.items', $batchId), ['items' => [$this->readingItem($package, $customer, '15', '000001')]])
            ->assertNotFound();
    }

    public function test_a_reading_entered_in_the_office_meanwhile_is_held_and_saves_nothing(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $item = $this->readingItem($package, $customer, '15', '000001');
        MeterReadings::save($customer, $this->period, '14', ReadingCode::Normal, $this->today, $this->owner);

        $this->sync($token, $package, [$item])
            ->assertOk()
            ->assertJsonPath('items.0.status', 'held')
            ->assertJsonPath('items.0.discrepancies.0.check', SyncCheck::DuplicateReading->value);

        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame('14.000', MeterReading::query()->sole()->present_reading);
    }

    public function test_rejecting_a_held_reading_records_its_number_as_rejected(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $item = $this->readingItem($package, $customer, '15', '000001');
        MeterReadings::save($customer, $this->period, '14', ReadingCode::Normal, $this->today, $this->owner);
        $this->sync($token, $package, [$item]);

        SyncItems::resolve(SyncItem::query()->sole(), SyncResolution::Rejected, 'Office reading kept.', $this->owner);

        $this->assertSame(SyncItemStatus::Rejected, SyncItem::query()->sole()->status);
        $gap = InvoiceNumberGap::query()->sole();
        $this->assertSame(['000001', NumberGapReason::Rejected], [$gap->invoice_number, $gap->reason]);
    }

    public function test_a_printed_bill_that_no_longer_matches_the_rates_is_held(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $item = $this->readingItem($package, $customer, '15', '000001');
        $this->rate->ranges()->where('range_from', 11)->update(['rate' => '30.00']);

        $this->sync($token, $package, [$item])->assertOk()->assertJsonPath('items.0.status', 'held');

        $amount = SyncItem::query()->sole()->discrepancies()->where('check', SyncCheck::Amount)->sole();
        $this->assertSame(['225.00', '250.00'], [$amount->device_value, $amount->server_value]);
        $this->assertSame(1, SyncItem::query()->sole()->discrepancies()->where('check', SyncCheck::Config)->count());
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_re_billing_a_held_bill_issues_the_server_figures_under_the_printed_number(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $item = $this->readingItem($package, $customer, '15', '000001');
        $this->rate->ranges()->where('range_from', 11)->update(['rate' => '30.00']);
        $this->sync($token, $package, [$item]);

        SyncItems::resolve(SyncItem::query()->sole(), SyncResolution::Rebilled, 'Rate changed on the 1st.', $this->owner);

        $invoice = Invoice::query()->sole();
        $this->assertSame(['000001', '250.00', true], [$invoice->invoice_number, $invoice->total_amount, $invoice->is_corrected]);
        $this->assertSame(SyncItemStatus::Applied, SyncItem::query()->sole()->status);
    }

    public function test_accepting_a_held_bill_as_printed_issues_the_printed_figures(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $item = $this->readingItem($package, $customer, '15', '000001');
        $this->rate->ranges()->where('range_from', 11)->update(['rate' => '30.00']);
        $this->sync($token, $package, [$item]);

        SyncItems::resolve(SyncItem::query()->sole(), SyncResolution::AcceptedAsPrinted, 'Honour the printed bill.', $this->owner);

        $invoice = Invoice::query()->sole();
        $this->assertSame(['000001', '225.00', false], [$invoice->invoice_number, $invoice->total_amount, $invoice->is_corrected]);
        $this->assertSame('125.00', $invoice->lines()->where('description', '11-20 cu.m.')->value('amount'));
    }

    public function test_resolving_fails_when_the_customer_was_billed_meanwhile(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $item = $this->readingItem($package, $customer, '15', '000001');
        $this->rate->ranges()->where('range_from', 11)->update(['rate' => '30.00']);
        $this->sync($token, $package, [$item]);
        BillingRun::generate($customer, $this->period, '16', false, $this->today, $this->today, $this->owner);

        $this->expectException(BillingException::class);

        SyncItems::resolve(SyncItem::query()->sole(), SyncResolution::Rebilled, 'Rate changed.', $this->owner);
    }

    public function test_a_payment_posted_after_the_download_is_logged_and_the_reading_still_applied(): void
    {
        $customer = $this->routeCustomer();
        $lastMonth = now()->subMonthNoOverflow()->format('Y-m');
        $earlier = BillingRun::generate($customer, $lastMonth, '10', false, $this->today, $this->today, $this->owner);
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $item = $this->readingItem($package, $customer, '25', '000002');
        Ledger::recordPayment($customer, ['paid_on' => $this->today, 'amount' => $earlier->total_amount, 'method' => PaymentMethod::Cash], $this->owner);

        $this->sync($token, $package, [$item])->assertOk()->assertJsonPath('items.0.status', 'applied');

        $finding = SyncItem::query()->sole()->discrepancies()->sole();
        $this->assertSame([SyncCheck::PreviousBalance, DiscrepancySeverity::Info], [$finding->check, $finding->severity]);
        $this->assertSame('0.00', Invoice::query()->where('invoice_number', '000002')->value('previous_balance'));
    }

    public function test_an_invoice_number_outside_the_devices_block_is_held(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);

        $this->sync($token, $package, [$this->readingItem($package, $customer, '15', '000999')])
            ->assertOk()
            ->assertJsonPath('items.0.status', 'held')
            ->assertJsonPath('items.0.discrepancies.0.check', SyncCheck::InvoiceNumber->value);
    }

    public function test_a_flagged_reading_printed_without_the_readers_confirmation_is_held(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        // Usage 0 needs review (no usage since the last reading).
        $item = $this->readingItem($package, $customer, '0', '000001', ['flags' => ['No usage since the last reading.']]);

        $this->sync($token, $package, [$item])->assertOk()->assertJsonPath('items.0.status', 'held');

        $this->assertSame(SyncCheck::Flags, SyncItem::query()->sole()->discrepancies()->sole()->check);
    }

    public function test_a_flagged_reading_the_reader_confirmed_is_billed(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $item = $this->readingItem($package, $customer, '0', '000001', ['flags' => ['No usage since the last reading.'], 'confirmed_by_reader' => true]);

        $this->sync($token, $package, [$item])->assertOk()->assertJsonPath('items.0.status', 'applied');

        $reading = MeterReading::query()->sole();
        $this->assertTrue($reading->confirmed_by_reader);
        $this->assertSame(MeterReadingStatus::Billed, $reading->status);
    }

    public function test_a_reading_of_a_cluster_no_longer_assigned_to_the_reader_is_held(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $item = $this->readingItem($package, $customer, '15', '000001');
        $this->company->readingAssignments()->update(['user_id' => $this->owner->id]);

        $this->sync($token, $package, [$item])->assertOk()->assertJsonPath('items.0.discrepancies.0.check', SyncCheck::Assignment->value);
    }

    public function test_a_skipped_number_is_recorded_as_a_gap(): void
    {
        $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);

        $this->sync($token, $package, [['client_uuid' => (string) Str::uuid(), 'type' => 'skipped_number', 'invoice_number' => '000004', 'note' => 'Printer jammed']])
            ->assertOk()
            ->assertJsonPath('items.0.status', 'applied');

        $gap = InvoiceNumberGap::query()->sole();
        $this->assertSame(['000004', NumberGapReason::Skipped, 'Printer jammed'], [$gap->invoice_number, $gap->reason, $gap->note]);
    }

    public function test_a_signed_out_device_can_still_upload_but_its_readings_are_held(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $item = $this->readingItem($package, $customer, '15', '000001');
        $device = Device::query()->sole();
        $device->update(['revoked_at' => now(), 'token_expires_at' => now()->addDays(3)]);

        $this->sync($token, $package, [$item])
            ->assertOk()
            ->assertJsonPath('items.0.status', 'held')
            ->assertJsonPath('items.0.discrepancies.0.check', SyncCheck::DeviceSignedOut->value);

        $this->assertSame(0, MeterReading::query()->count());
        $this->assertEqualsWithDelta(now()->addDays(3)->timestamp, $device->fresh()->token_expires_at->timestamp, 5);
    }

    public function test_a_signed_out_device_cannot_download_or_release_numbers(): void
    {
        $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        Device::query()->update(['revoked_at' => now()]);

        $this->withToken($token)->getJson(route('reader.packages.changes', $package['package_id']))->assertUnauthorized();
        $this->withToken($token)->postJson(route('reader.blocks.release'), ['release_from' => '000001'])->assertUnauthorized();
    }

    public function test_the_reprint_count_is_saved_on_the_invoice(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $item = $this->readingItem($package, $customer, '15', '000001');
        $item['bill']['reprint_count'] = 2;

        $this->sync($token, $package, [$item])->assertOk()->assertJsonPath('items.0.status', 'applied');

        $this->assertSame(2, Invoice::query()->sole()->reprint_count);
    }

    public function test_a_reading_item_without_its_fields_returns_422(): void
    {
        $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $batchId = $this->withToken($token)->postJson(route('reader.sync.store'), [
            'package_id' => $package['package_id'], 'item_count' => 1, 'checksum' => str_repeat('a', 64), 'device_time' => now()->toIso8601String(),
        ])->json('batch_id');

        $this->withToken($token)->putJson(route('reader.sync.items', $batchId), ['items' => [['client_uuid' => (string) Str::uuid(), 'type' => 'reading']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.customer_id', 'items.0.billing_period', 'items.0.reading_code', 'items.0.read_at']);
    }
}
