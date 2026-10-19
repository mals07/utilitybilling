<?php

namespace Tests\Feature;

use App\Enums\Access;
use App\Enums\CustomerStatus;
use App\Enums\MeterReadingStatus;
use App\Enums\Page;
use App\Enums\ReadingCode;
use App\Enums\ReadingKind;
use App\Enums\WorkOrderType;
use App\Livewire\MeterReadings\MeterReadingsPage;
use App\Models\ChargeType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RateType;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderReading;
use App\Support\Billing\BillingException;
use App\Support\Billing\BillingRun;
use App\Support\Billing\Ledger;
use App\Support\Billing\MeterReadings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MeterReadingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    private RateType $rate;

    private ChargeType $chargeType;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->company] = $this->ownerWithCompany();

        // 0–10 @ 10.00, 11+ @ 25.00, minimum 10 cu.m.; meters that can't be read are estimated.
        $this->rate = RateType::factory()->for($this->company)->create(['name' => 'Residential']);
        $this->rate->ranges()->createMany([
            ['range_from' => 0, 'range_to' => 10, 'rate' => '10.00', 'description' => '0-10 cu.m.'],
            ['range_from' => 11, 'range_to' => 99999, 'rate' => '25.00', 'description' => 'Above 10 cu.m.'],
        ]);
        $this->rate->setup()->create([
            'minimum_consumption' => 10,
            'defective_uses_minimum_consumption' => true,
            'defective_uses_three_month_average' => true,
        ]);
        $this->chargeType = ChargeType::factory()->for($this->company)->create();
    }

    private function customer(array $attributes = []): Customer
    {
        $customer = Customer::factory()->for($this->company)->connected()->create([
            'rate_type_id' => $this->rate->id,
            'charge_type_id' => $this->chargeType->id,
            ...$attributes,
        ]);

        $this->workOrderReading($customer, ReadingKind::Initial, '0', now()->subMonths(6)->toDateString());

        return $customer;
    }

    private function workOrderReading(Customer $customer, ReadingKind $kind, string $reading, string $readOn): void
    {
        $order = WorkOrder::factory()->forCustomer($customer, WorkOrderType::NewInstallation)->completed()->create();
        WorkOrderReading::query()->create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id, 'work_order_id' => $order->id,
            'meter_number' => $customer->meter_number, 'kind' => $kind, 'reading' => $reading, 'read_on' => $readOn,
        ]);
    }

    private function bill(Customer $customer, int $monthsAgo, string $present): Invoice
    {
        $date = now()->subMonths($monthsAgo)->toDateString();

        return BillingRun::generate($customer, now()->subMonths($monthsAgo)->format('Y-m'), $present, false, $date, $date, $this->owner);
    }

    private function period(): string
    {
        return now()->format('Y-m');
    }

    public function test_the_page_lists_the_meters_to_read_with_progress(): void
    {
        $read = $this->customer(['first_name' => 'Maria', 'last_name' => 'Santos']);
        $unread = $this->customer(['first_name' => 'Pedro', 'last_name' => 'Reyes']);
        $this->customer(['status' => CustomerStatus::Pending, 'meter_number' => null, 'first_name' => 'Pending', 'last_name' => 'Person']);
        MeterReadings::save($read, $this->period(), '12', ReadingCode::Normal, now()->toDateString(), $this->owner);

        $this->actingAs($this->owner)->get(route('meter-readings'))
            ->assertOk()
            ->assertSee('Maria Santos')
            ->assertSee('Pedro Reyes')
            ->assertDontSee('Pending Person');

        Livewire::actingAs($this->owner)
            ->test(MeterReadingsPage::class)
            ->assertViewHas('stats', fn (array $stats): bool => $stats['total'] === 2 && $stats['read'] === 1 && $stats['ready'] === 1)
            ->set('filter', 'unread')
            ->assertSee('Pedro Reyes')
            ->assertDontSee('Maria Santos')
            ->call('select', $read->id)
            ->assertSee('Bill preview')
            ->assertSee('150.00');

        $this->assertNotNull($unread);
    }

    public function test_typed_readings_are_saved_and_unusual_ones_need_review(): void
    {
        $customer = $this->customer();
        $this->bill($customer, 3, '10');
        $this->bill($customer, 2, '20');
        $this->bill($customer, 1, '30');
        $new = $this->customer();

        $page = Livewire::actingAs($this->owner)
            ->test(MeterReadingsPage::class)
            ->call('save', $customer->id, '40');

        $reading = $customer->meterReadings()->where('billing_period', $this->period())->sole();
        $this->assertSame(MeterReadingStatus::Ready, $reading->status);
        $this->assertSame('10.000', $reading->actual_consumption);
        $this->assertSame('30.000', $reading->previous_reading);

        // 70 cu.m. against a 10 cu.m. average.
        $page->call('save', $customer->id, '100');
        $reading->refresh();
        $this->assertSame(MeterReadingStatus::Review, $reading->status);
        $this->assertStringContainsString('7.0× the 3-month average of 10 cu.m.', $reading->review_reason);

        $page->call('save', $new->id, '0');
        $this->assertSame('No usage since the last reading.', $new->meterReadings()->sole()->review_reason);

        $page->call('confirm', $customer->id)->assertDispatched('flash', message: 'Reading confirmed. It is ready to bill.');
        $reading->refresh();
        $this->assertSame(MeterReadingStatus::Ready, $reading->status);
        $this->assertSame($this->owner->id, $reading->confirmed_by_id);

        $page->call('reread', $new->id);
        $this->assertSame(0, $new->meterReadings()->count());
    }

    public function test_invalid_readings_show_why_and_are_not_saved(): void
    {
        $customer = $this->customer();

        Livewire::actingAs($this->owner)
            ->test(MeterReadingsPage::class)
            ->call('save', $customer->id, 'abc')
            ->assertSet("rowErrors.{$customer->id}", 'Enter the reading as a number, such as 1234 or 1234.5.')
            ->assertSet("entries.{$customer->id}", 'abc')
            ->set('readOn', now()->addDay()->toDateString())
            ->call('save', $customer->id, '5')
            ->assertSet("rowErrors.{$customer->id}", "The reading date can't be in the future.");

        $this->assertSame(0, $customer->meterReadings()->count());
    }

    public function test_a_lower_reading_is_rejected_unless_the_meter_rolled_over(): void
    {
        $customer = $this->customer();
        $this->bill($customer, 1, '9990');

        $page = Livewire::actingAs($this->owner)
            ->test(MeterReadingsPage::class)
            ->call('save', $customer->id, '5');
        $this->assertStringContainsString('is lower than the previous reading (9990). Check the reading, or mark the meter Defective.', $page->get("rowErrors.{$customer->id}"));

        // A 4-dial meter wraps from 9999 to 0: 9990 → 9999 → 0 → 5 is 15 cu.m.
        $customer->meterType->update(['dial_digits' => 4]);
        $page->call('saveRollover', $customer->id);

        $reading = $customer->meterReadings()->where('billing_period', $this->period())->sole();
        $this->assertSame(ReadingCode::Rollover, $reading->reading_code);
        $this->assertSame('15.000', $reading->actual_consumption);
        $this->assertSame(MeterReadingStatus::Review, $reading->status);

        $this->expectExceptionMessage("A 4-dial meter can't read above 9,999.");
        MeterReadings::evaluate($customer->fresh(), $this->period(), '12000', ReadingCode::Normal);
    }

    public function test_an_estimated_month_is_deducted_from_the_next_actual_reading(): void
    {
        $customer = $this->customer();
        $this->bill($customer, 3, '10');
        $this->bill($customer, 2, '30');

        // No access last month: billed on the average of 10 and 20 cu.m.
        Livewire::actingAs($this->owner)->test(MeterReadingsPage::class)
            ->set('period', now()->subMonth()->format('Y-m'))
            ->call('markEstimated', $customer->id, ReadingCode::NoAccess->value);
        $estimate = $customer->meterReadings()->where('billing_period', now()->subMonth()->format('Y-m'))->sole();
        $this->assertSame('15.000', $estimate->actual_consumption);
        $this->assertNull($estimate->present_reading);
        BillingRun::generateFor($estimate, now()->toDateString(), $this->owner);

        // The meter moved 30 cu.m. over both months; 15 were billed already.
        $reading = MeterReadings::save($customer, $this->period(), '60', ReadingCode::Normal, now()->toDateString(), $this->owner);
        $this->assertSame('30.000', $reading->previous_reading);
        $this->assertSame('15.000', $reading->estimate_deducted);
        $this->assertSame('15.000', $reading->actual_consumption);
    }

    public function test_months_are_read_and_billed_in_order(): void
    {
        $customer = $this->customer();
        MeterReadings::save($customer, now()->subMonth()->format('Y-m'), '10', ReadingCode::Normal, now()->toDateString(), $this->owner);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('reading isn\'t billed yet. Bill it on Generate Bills first.');
        MeterReadings::save($customer, $this->period(), '20', ReadingCode::Normal, now()->toDateString(), $this->owner);
    }

    public function test_readings_need_review_before_billing_and_a_voided_bill_frees_its_reading(): void
    {
        $customer = $this->customer();
        $reading = MeterReadings::save($customer, $this->period(), '0', ReadingCode::Normal, now()->toDateString(), $this->owner);

        try {
            BillingRun::generateFor($reading, now()->toDateString(), $this->owner);
            $this->fail('A reading that needs review was billed.');
        } catch (BillingException $exception) {
            $this->assertStringContainsString('needs review', $exception->getMessage());
        }

        MeterReadings::confirm($reading, $this->owner);
        $invoice = BillingRun::generateFor($reading, now()->toDateString(), $this->owner);
        $this->assertSame('100.00', $invoice->total_amount);

        Ledger::voidInvoice($invoice, 'Wrong reading', $this->owner);
        $reading->refresh();
        $this->assertSame(MeterReadingStatus::Ready, $reading->status);
        $this->assertNull($reading->invoice_id);

        $corrected = MeterReadings::save($customer, $this->period(), '12', ReadingCode::Normal, now()->toDateString(), $this->owner);
        $this->assertSame($reading->id, $corrected->id);
        $this->assertSame('12.000', $corrected->actual_consumption);
    }

    public function test_a_disconnected_meter_starts_from_its_final_reading(): void
    {
        $customer = $this->customer(['status' => CustomerStatus::Disconnected]);
        $this->workOrderReading($customer, ReadingKind::Final, '50', now()->toDateString());

        Livewire::actingAs($this->owner)
            ->test(MeterReadingsPage::class)
            ->assertSet("entries.{$customer->id}", '50')
            ->call('save', $customer->id, '50');

        $this->assertSame('50.000', $customer->meterReadings()->sole()->actual_consumption);
    }

    public function test_users_need_access_to_enter_and_confirm_readings(): void
    {
        $customer = $this->customer();
        $viewer = $this->memberWithAccess($this->company, Page::MeterReadings, [Access::View]);
        $reader = $this->memberWithAccess($this->company, Page::MeterReadings, [Access::View, Access::Add]);
        $outsider = $this->memberWithAccess($this->company, Page::Invoices, [Access::View]);

        $this->actingAs($outsider)->get(route('meter-readings'))->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(MeterReadingsPage::class)
            ->call('save', $customer->id, '5')
            ->assertDispatched('flash', message: Access::Add->deniedMessage(), tone: 'error');
        $this->assertSame(0, $customer->meterReadings()->count());

        // Readers enter readings; confirming one that needs review takes Edit access.
        Livewire::actingAs($reader)
            ->test(MeterReadingsPage::class)
            ->call('save', $customer->id, '0')
            ->call('confirm', $customer->id)
            ->assertDispatched('flash', message: Access::Edit->deniedMessage(), tone: 'error')
            ->call('save', $customer->id, '5')
            ->assertDispatched('flash', message: Access::Edit->deniedMessage(), tone: 'error');

        $this->assertSame(MeterReadingStatus::Review, $customer->meterReadings()->sole()->status);
    }
}
