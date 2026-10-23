<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Enums\ReadingKind;
use App\Enums\WorkOrderFlag;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Livewire\WorkOrders\WorkOrdersPage;
use App\Models\Company;
use App\Models\Customer;
use App\Models\MeterType;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class WorkOrderCompletionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->company] = $this->ownerWithCompany();
    }

    private function order(Customer $customer, WorkOrderType $type): WorkOrder
    {
        return WorkOrder::factory()->forCustomer($customer, $type)->create(['assigned_to_id' => $this->owner->id]);
    }

    /**
     * Opens the Complete modal, fills the type's fields, ticks the checklist and, when asked, adds a photo.
     *
     * @param  array<string, string>  $fields
     */
    private function complete(WorkOrder $order, array $fields, bool $withPhoto = true, bool $tickChecklist = true, bool $submit = true): Testable
    {
        $page = Livewire::actingAs($this->owner)
            ->test(WorkOrdersPage::class)
            ->call('openComplete', $order->id)
            ->assertSee("Complete Work Order {$order->order_number}");

        foreach ($fields as $key => $value) {
            $page->set("completion.fields.{$key}", $value);
        }
        if ($tickChecklist) {
            $page->set('completion.checked', array_fill_keys(array_keys($order->order_type->checklist()), true));
        }
        if ($withPhoto) {
            $page->set('newPhotos', [UploadedFile::fake()->image('proof.jpg', 60, 60)]);
        }

        return $submit ? $page->call('complete') : $page;
    }

    public function test_new_installation_connects_the_customer_and_sets_their_meter(): void
    {
        $customer = Customer::factory()->for($this->company)->create();
        $meterType = MeterType::factory()->for($this->company)->create();
        $order = $this->order($customer, WorkOrderType::NewInstallation);
        $this->company->workOrderFees()->create(['order_type' => WorkOrderType::NewInstallation, 'amount' => '1500']);

        $this->complete($order, [
            'meter_number' => 'MTR-100',
            'serial_number' => 'SN-100',
            'meter_type_id' => (string) $meterType->id,
            'initial_reading' => '0',
        ])->assertHasNoErrors()->assertSet('completingId', '');

        $customer->refresh();
        $this->assertSame(CustomerStatus::Connected, $customer->status);
        $this->assertSame('MTR-100', $customer->meter_number);
        $this->assertSame('SN-100', $customer->meter_serial_number);
        $this->assertSame($meterType->id, $customer->meter_type_id);
        $this->assertNotNull($customer->meter_installed_on);

        $order->refresh();
        $this->assertSame(WorkOrderStatus::Completed, $order->status);
        $this->assertSame($this->owner->id, $order->completed_by_id);
        $this->assertSame('1500.00', $order->fee_amount);
        // New Installation fees are billed on their own invoice, paid upfront.
        $this->assertNotNull($order->fee_billed_at);
        $this->assertSame('1500.00', $order->feeInvoice->total_amount);
        $this->assertSame($customer->id, $order->feeInvoice->customer_id);
        $this->assertCount(4, $order->completion_data['checklist']);
        $this->assertSame(1, $order->photos()->count());

        $reading = $order->readings()->sole();
        $this->assertSame(ReadingKind::Initial, $reading->kind);
        $this->assertSame('MTR-100', $reading->meter_number);

        $timeline = $order->activities()->value('description');
        $this->assertStringContainsString('status: Pending → Connected', $timeline);
        $this->assertStringContainsString('meter no.: (none) → MTR-100', $timeline);

        $photo = $order->photos()->sole();
        $this->actingAs($this->owner)->get($photo->url())->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_a_large_completion_photo_is_stored_shrunk_as_a_jpeg(): void
    {
        $customer = Customer::factory()->for($this->company)->create();
        $meterType = MeterType::factory()->for($this->company)->create();
        $order = $this->order($customer, WorkOrderType::NewInstallation);

        $this->complete($order, [
            'meter_number' => 'MTR-100',
            'serial_number' => 'SN-100',
            'meter_type_id' => (string) $meterType->id,
            'initial_reading' => '0',
        ], withPhoto: false, submit: false)
            ->set('newPhotos', [UploadedFile::fake()->image('meter.png', 1800, 900)])
            ->call('complete')
            ->assertHasNoErrors();

        $photo = $order->photos()->sole();
        $stored = $this->actingAs($this->owner)->get($photo->url())->assertHeader('Content-Type', 'image/jpeg')->getContent();
        $this->assertSame([1600, 800], array_slice(getimagesizefromstring($stored), 0, 2));
        $this->assertSame('meter.png', $photo->file_name);
    }

    public function test_a_photo_and_every_checklist_item_are_required_where_the_type_asks(): void
    {
        $customer = Customer::factory()->for($this->company)->create();
        $order = $this->order($customer, WorkOrderType::NewInstallation);

        $this->complete($order, ['initial_reading' => '0'], withPhoto: false, tickChecklist: false)
            ->assertHasErrors([
                'photos', 'completion.checked', 'completion.fields.meter_number', 'completion.fields.serial_number',
                'completion.fields.meter_type_id',
            ]);

        $this->assertSame(WorkOrderStatus::InProgress, $order->fresh()->status);
        $this->assertSame(CustomerStatus::Pending, $customer->fresh()->status);
    }

    public function test_the_completion_date_cannot_be_in_the_future_or_before_the_request(): void
    {
        $customer = Customer::factory()->for($this->company)->connected()->create();
        $order = WorkOrder::factory()->forCustomer($customer, WorkOrderType::PipeRepair)->requestedDaysAgo(2)->create(['assigned_to_id' => $this->owner->id]);
        $fields = ['pipe_size' => '1/2"', 'cause' => 'Old pipe'];

        $this->complete($order, $fields, withPhoto: false, submit: false)
            ->set('completion.completedOn', now()->addDays(2)->toDateString())
            ->call('complete')
            ->assertHasErrors(['completion.completedOn'])
            ->assertSee("The completion date can't be in the future.")
            ->set('completion.completedOn', now()->subDays(10)->toDateString())
            ->call('complete')
            ->assertHasErrors(['completion.completedOn']);

        $this->assertSame(WorkOrderStatus::InProgress, $order->fresh()->status);
    }

    public function test_disconnection_then_reconnection_with_a_different_reading_is_flagged(): void
    {
        $customer = Customer::factory()->for($this->company)->connected()->create();

        $this->complete($this->order($customer, WorkOrderType::Disconnection), [
            'reason' => 'non_payment', 'final_reading' => '1520.5', 'seal_number' => 'SEAL-9',
        ])->assertHasNoErrors();

        $this->assertSame(CustomerStatus::Disconnected, $customer->fresh()->status);

        $reconnection = $this->order($customer->fresh(), WorkOrderType::Reconnection);
        $this->complete($reconnection, ['reconnection_reading' => '1600'])
            ->assertHasNoErrors()
            ->assertDispatched('flash', fn (string $name, array $params): bool => str_contains($params['message'], 'differs from the final reading at disconnection'));

        $this->assertSame(CustomerStatus::Connected, $customer->fresh()->status);
        $this->assertSame(WorkOrderFlag::ReadingMismatch, $reconnection->fresh()->review_flag);
    }

    public function test_meter_replacement_swaps_the_meter_and_records_both_readings(): void
    {
        $customer = Customer::factory()->for($this->company)->connected()->create(['meter_number' => 'OLD-1']);
        $newType = MeterType::factory()->for($this->company)->create();
        $order = $this->order($customer, WorkOrderType::MeterReplacement);

        $this->complete($order, [
            'reason' => 'defective', 'old_final_reading' => '880', 'new_meter_number' => 'old-1',
            'new_serial_number' => 'SN-NEW', 'new_meter_type_id' => (string) $newType->id, 'new_initial_reading' => '0',
        ])->assertHasErrors(['completion.fields.new_meter_number' => 'The new meter no. is the same as the current one.'])
            ->set('completion.fields.new_meter_number', 'NEW-2')
            ->call('complete')
            ->assertHasNoErrors();

        $customer->refresh();
        $this->assertSame('NEW-2', $customer->meter_number);
        $this->assertSame('SN-NEW', $customer->meter_serial_number);
        $this->assertSame($newType->id, $customer->meter_type_id);

        $readings = $order->readings()->get()->keyBy(fn ($reading) => $reading->kind->value);
        $this->assertSame('OLD-1', $readings['final']->meter_number);
        $this->assertSame('NEW-2', $readings['initial']->meter_number);
    }

    public function test_a_meter_that_cannot_be_repaired_creates_a_linked_meter_replacement(): void
    {
        $customer = Customer::factory()->for($this->company)->connected()->create();
        $order = $this->order($customer, WorkOrderType::MeterRepair);

        $this->complete($order, [
            'findings' => 'Register gear broken', 'action_taken' => 'Inspected', 'outcome' => 'repaired',
            'reading_before' => '500', 'reading_after' => '499',
        ], withPhoto: false)->assertHasErrors(['completion.fields.reading_after' => "The reading after can't be lower than the reading before."])
            ->set('completion.fields.outcome', 'not_repairable')
            ->call('complete')
            ->assertHasNoErrors();

        $followUp = WorkOrder::query()->where('parent_work_order_id', $order->id)->sole();
        $this->assertSame(WorkOrderType::MeterReplacement, $followUp->order_type);
        $this->assertSame(WorkOrderStatus::InProgress, $followUp->status);
        $this->assertSame($this->owner->id, $followUp->assigned_to_id);
        $this->assertSame($customer->meter_number, $followUp->meter_number);
        // Only the "before" reading applies when the meter wasn't repaired.
        $this->assertSame([ReadingKind::Before], $order->readings()->pluck('kind')->all());
    }

    public function test_meter_relocation_moves_the_map_pin(): void
    {
        $customer = Customer::factory()->for($this->company)->connected()->create();
        $order = $this->order($customer, WorkOrderType::MeterRelocation);

        $this->complete($order, [
            'new_location' => 'Left of the gate', 'latitude' => '14.6', 'reading_before' => '10', 'reading_after' => '10',
        ], withPhoto: false)->assertHasErrors(['completion.fields.longitude'])
            ->set('completion.fields.longitude', '121.05')
            ->call('complete')
            ->assertHasNoErrors();

        $this->assertSame(14.6, $customer->fresh()->latitude);
        $this->assertSame(121.05, $customer->fresh()->longitude);
    }

    public function test_reading_verification_outcomes_flag_billing_or_create_a_repair(): void
    {
        $customer = Customer::factory()->for($this->company)->connected()->create();
        $wrong = $this->order($customer, WorkOrderType::MeterReadingVerification);

        $this->complete($wrong, ['disputed_reading' => '900', 'verified_reading' => '850', 'outcome' => 'wrong'], withPhoto: false)
            ->assertHasNoErrors();
        $this->assertSame(WorkOrderFlag::BillingAdjustment, $wrong->fresh()->review_flag);

        $faulty = $this->order($customer, WorkOrderType::MeterReadingVerification);
        $this->complete($faulty, ['disputed_reading' => '900', 'verified_reading' => '851', 'outcome' => 'meter_faulty'], withPhoto: false)
            ->assertHasNoErrors();
        $this->assertSame(WorkOrderType::MeterRepair, $faulty->followUps()->sole()->order_type);
    }

    public function test_a_leak_after_the_meter_is_flagged_and_customers_without_a_meter_skip_readings(): void
    {
        $customer = Customer::factory()->for($this->company)->create();
        $order = $this->order($customer, WorkOrderType::LeakRepair);

        $page = Livewire::actingAs($this->owner)->test(WorkOrdersPage::class)->call('openComplete', $order->id);
        $page->assertDontSee('Reading before');

        $this->complete($order, [
            'leak_location' => 'after_meter', 'cause' => 'Loose fitting', 'action_taken' => 'Tightened the fitting',
        ], withPhoto: false)->assertHasNoErrors();

        $this->assertSame(WorkOrderFlag::LeakAdjustment, $order->fresh()->review_flag);
        $this->assertSame(0, $order->readings()->count());
    }

    public function test_pipe_repair_completes_as_a_record(): void
    {
        $customer = Customer::factory()->for($this->company)->connected()->create();
        $order = $this->order($customer, WorkOrderType::PipeRepair);

        $this->complete($order, ['pipe_size' => '3/4"', 'length_replaced' => '2.5', 'cause' => 'Corrosion'], withPhoto: false)
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame(WorkOrderStatus::Completed, $order->status);
        $this->assertSame('2.5', $order->completion_data['fields']['length_replaced']);
        $this->assertSame(CustomerStatus::Connected, $customer->fresh()->status);
    }

    public function test_unresolved_low_pressure_creates_the_chosen_follow_up(): void
    {
        $customer = Customer::factory()->for($this->company)->connected()->create();
        $order = $this->order($customer, WorkOrderType::LowWaterPressure);

        $this->complete($order, [
            'measured_pressure' => '8', 'cause' => 'Suspected main leak', 'action_taken' => 'Checked valves', 'resolved' => 'no',
        ], withPhoto: false)->assertHasErrors(['completion.fields.follow_up'])
            ->set('completion.fields.follow_up', WorkOrderType::PipeRepair->value)
            ->call('complete')
            ->assertHasNoErrors();

        $this->assertSame(WorkOrderType::PipeRepair, $order->followUps()->sole()->order_type);
    }

    public function test_a_failed_water_sample_is_flagged_and_needs_a_sample_id(): void
    {
        $customer = Customer::factory()->for($this->company)->connected()->create();
        $order = $this->order($customer, WorkOrderType::WaterQualityComplaint);

        $this->complete($order, [
            'complaint_type' => 'color', 'sample_taken' => 'yes', 'result' => 'fail', 'action_taken' => 'Flushed the line',
        ], withPhoto: false)->assertHasErrors(['completion.fields.sample_id'])
            ->set('completion.fields.sample_id', 'LAB-77')
            ->call('complete')
            ->assertHasNoErrors();

        $this->assertSame(WorkOrderFlag::QualityFail, $order->fresh()->review_flag);
    }

    public function test_billing_complaint_outcomes(): void
    {
        $withoutMeter = Customer::factory()->for($this->company)->create();
        $order = $this->order($withoutMeter, WorkOrderType::BillingComplaint);
        $fields = ['bill_period' => '2026-09', 'issue' => 'wrong_charges', 'findings' => 'Charged twice'];

        $this->complete($order, [...$fields, 'outcome' => 'reread_needed'], withPhoto: false)
            ->assertHasErrors(['completion.fields.outcome' => 'This customer has no meter to re-read. Choose another outcome.'])
            ->set('completion.fields.outcome', 'adjustment_needed')
            ->call('complete')
            ->assertHasErrors(['completion.fields.adjustment_amount'])
            ->set('completion.fields.adjustment_amount', '320.75')
            ->call('complete')
            ->assertHasNoErrors();

        $this->assertSame(WorkOrderFlag::BillingAdjustment, $order->fresh()->review_flag);

        $withMeter = Customer::factory()->for($this->company)->connected()->create();
        $reread = $this->order($withMeter, WorkOrderType::BillingComplaint);
        $this->complete($reread, [...$fields, 'outcome' => 'reread_needed'], withPhoto: false)->assertHasNoErrors();
        $this->assertSame(WorkOrderType::MeterReadingVerification, $reread->followUps()->sole()->order_type);
    }

    public function test_change_of_ownership_updates_the_customer_and_keeps_the_previous_owner_on_record(): void
    {
        $customer = Customer::factory()->for($this->company)->connected()->create([
            'first_name' => 'Rosa', 'last_name' => 'Lim', 'email' => 'rosa@example.com',
        ]);
        $order = $this->order($customer, WorkOrderType::ChangeOfOwnership);

        $this->complete($order, [
            'new_first_name' => 'Ben', 'new_last_name' => 'Tan', 'new_email' => 'not-an-email',
            'effective_date' => now()->toDateString(), 'final_reading' => '2001',
        ], withPhoto: false)->assertHasErrors(['completion.fields.new_email'])
            ->set('completion.fields.new_email', '')
            ->call('complete')
            ->assertHasNoErrors();

        $customer->refresh();
        $this->assertSame('Ben', $customer->first_name);
        $this->assertSame('Tan', $customer->last_name);
        $this->assertNull($customer->email);
        $this->assertSame($order->customer->account_number, $customer->account_number);
        $this->assertStringContainsString('first name: Rosa → Ben', $order->activities()->value('description'));
        $this->assertSame(ReadingKind::Final, $order->readings()->sole()->kind);
    }

    public function test_completion_is_refused_when_the_customer_no_longer_qualifies_and_completed_orders_are_locked(): void
    {
        $customer = Customer::factory()->for($this->company)->connected()->create();
        $order = $this->order($customer, WorkOrderType::Disconnection);
        $customer->update(['status' => CustomerStatus::Disconnected]);

        $fields = ['reason' => 'customer_request', 'final_reading' => '10', 'seal_number' => 'S-1'];
        $this->complete($order, $fields)
            ->assertHasErrors(['completion'])
            ->assertSee("This order can't be completed: customer is not connected.");
        $this->assertSame(WorkOrderStatus::InProgress, $order->fresh()->status);

        $customer->update(['status' => CustomerStatus::Connected]);
        $this->complete($order, $fields)->assertHasNoErrors();

        // Completed is final.
        Livewire::actingAs($this->owner)
            ->test(WorkOrdersPage::class)
            ->call('openComplete', $order->id)
            ->assertSet('completingId', '')
            ->assertSet('viewingId', (string) $order->id)
            ->assertSee('Notice left with the customer')
            ->assertSee('Timeline');
    }
}
