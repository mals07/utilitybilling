<?php

namespace Tests\Feature;

use App\Enums\Access;
use App\Enums\Page;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Livewire\WorkOrders\WorkOrdersPage;
use App\Models\Customer;
use App\Models\MeterType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkOrdersPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_is_in_the_sidebar_and_loads(): void
    {
        [$owner] = $this->ownerWithCompany();

        $this->actingAs($owner)->get(route('work-orders'))
            ->assertOk()
            ->assertSee('Work Orders')
            ->assertSee('Submit Work Order')
            ->assertSee('New Installation');
    }

    public function test_searching_the_account_number_lists_customers_by_name_and_picking_one_fills_the_form(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $meterType = MeterType::factory()->for($company)->create(['name' => 'Analog 1/2"']);
        $rosa = Customer::factory()->for($company)->create([
            'account_number' => '000007', 'first_name' => 'Rosa', 'last_name' => 'Lim', 'meter_type_id' => $meterType->id,
        ]);
        Customer::factory()->for($company)->create(['first_name' => 'Ben', 'last_name' => 'Tan']);

        Livewire::actingAs($owner)
            ->test(WorkOrdersPage::class)
            ->set('customerSearch', 'rosa lim')
            ->assertSee('000007')
            ->assertSee('Rosa Lim')
            ->assertDontSee('Ben Tan')
            ->call('chooseCustomer', $rosa->id)
            ->assertSet('customerId', (string) $rosa->id)
            ->assertSet('customerSearch', '000007')
            ->assertSet('values.meterTypeId', (string) $meterType->id)
            ->assertSee('value="Rosa"', false);
    }

    public function test_a_submitted_work_order_is_in_progress_with_the_next_number(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $customer = Customer::factory()->for($company)->create();
        WorkOrder::factory()->for($company)->create(['order_number' => '000009', 'customer_id' => $customer->id]);

        Livewire::actingAs($owner)
            ->test(WorkOrdersPage::class)
            ->call('chooseCustomer', $customer->id)
            ->set('values.orderType', WorkOrderType::NewInstallation->value)
            ->set('values.meterNumber', 'MTR-1')
            ->set('values.serialNumber', 'SN-1')
            ->set('values.description', 'Install at the gate.')
            ->set('values.assignedToId', (string) $owner->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('flash', message: 'Work order 000010 submitted.')
            ->assertSet('customerId', '')
            ->assertSet('values.orderType', '');

        $order = WorkOrder::query()->where('order_number', '000010')->firstOrFail();
        $this->assertSame(WorkOrderStatus::InProgress, $order->status);
        $this->assertSame(WorkOrderType::NewInstallation, $order->order_type);
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame($owner->id, $order->assigned_to_id);
        $this->assertSame($owner->id, $order->requested_by_id);
        $this->assertSame('MTR-1', $order->meter_number);
        $this->assertNotNull($order->requested_at);
        $this->assertNull($order->completed_at);
    }

    public function test_a_customer_order_type_and_assignee_are_required(): void
    {
        [$owner] = $this->ownerWithCompany();

        Livewire::actingAs($owner)
            ->test(WorkOrdersPage::class)
            ->call('submit')
            ->assertHasErrors(['customerId', 'values.orderType', 'values.assignedToId']);

        $this->assertSame(0, WorkOrder::count());
    }

    public function test_customers_of_other_companies_cannot_be_picked(): void
    {
        [$owner] = $this->ownerWithCompany();
        $stranger = Customer::factory()->create();

        Livewire::actingAs($owner)
            ->test(WorkOrdersPage::class)
            ->call('chooseCustomer', $stranger->id)
            ->assertSet('customerId', '')
            ->set('customerId', (string) $stranger->id)
            ->set('values.orderType', WorkOrderType::LeakRepair->value)
            ->set('values.assignedToId', (string) $owner->id)
            ->call('submit')
            ->assertHasErrors(['customerId']);
    }

    public function test_assigned_to_lists_active_users_or_only_those_with_the_default_role(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $plumbers = Role::factory()->for($company)->create(['name' => 'Plumber']);
        $clerks = Role::factory()->for($company)->create(['name' => 'Clerk']);
        $plumber = User::factory()->memberOf($company, $plumbers)->create(['name' => 'Pedro Plumber']);
        $clerk = User::factory()->memberOf($company, $clerks)->create(['name' => 'Carla Clerk']);
        User::factory()->memberOf($company, $plumbers)->create(['name' => 'Gone Plumber', 'deactivated_at' => now()]);
        $customer = Customer::factory()->for($company)->create();

        $page = Livewire::actingAs($owner)
            ->test(WorkOrdersPage::class)
            ->assertSee('Pedro Plumber')
            ->assertSee('Carla Clerk')
            ->assertDontSee('Gone Plumber')
            ->call('openRoleChooser')
            ->assertSee('Default User Role for Assigned To')
            ->set('roleChoice', (string) $plumbers->id)
            ->call('saveDefaultRole')
            ->assertSet('choosingRole', false)
            ->assertSee('Pedro Plumber')
            ->assertDontSee('Carla Clerk');

        $this->assertSame($plumbers->id, $company->fresh()->work_order_assignee_role_id);

        $page->call('chooseCustomer', $customer->id)
            ->set('values.orderType', WorkOrderType::LeakRepair->value)
            ->set('values.assignedToId', (string) $clerk->id)
            ->call('submit')
            ->assertHasErrors(['values.assignedToId']);

        $page->set('values.assignedToId', (string) $plumber->id)
            ->call('submit')
            ->assertHasNoErrors();

        $page->call('openRoleChooser')
            ->assertSet('roleChoice', (string) $plumbers->id)
            ->set('roleChoice', '')
            ->call('saveDefaultRole')
            ->assertSee('Carla Clerk');

        $this->assertNull($company->fresh()->work_order_assignee_role_id);
    }

    public function test_only_users_with_edit_access_can_choose_the_default_role(): void
    {
        [, $company] = $this->ownerWithCompany();
        $adder = $this->memberWithAccess($company, Page::WorkOrders, [Access::Add, Access::View]);
        $role = Role::factory()->for($company)->create();

        Livewire::actingAs($adder)
            ->test(WorkOrdersPage::class)
            ->assertDontSee('Choose default user role for this field')
            ->set('roleChoice', (string) $role->id)
            ->call('saveDefaultRole')
            ->assertDispatched('flash', message: Access::Edit->deniedMessage(), tone: 'error');

        $this->assertNull($company->fresh()->work_order_assignee_role_id);
    }

    public function test_orders_in_progress_for_more_than_five_days_are_flagged(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $late = WorkOrder::factory()->for($company)->requestedDaysAgo(6)->create();
        WorkOrder::factory()->for($company)->requestedDaysAgo(9)->completed()->create();
        $onTime = WorkOrder::factory()->for($company)->requestedDaysAgo(4)->create();
        $heldLate = WorkOrder::factory()->for($company)->requestedDaysAgo(8)->onHold()->create();
        $this->assertTrue($heldLate->isOverdue($company->timezone));

        $timezone = $company->timezone;
        $this->assertTrue($late->isOverdue($timezone));
        $this->assertFalse($onTime->isOverdue($timezone));

        Livewire::actingAs($owner)
            ->test(WorkOrdersPage::class)
            ->assertSee('2 work orders have')
            ->assertSee('(overdue)');
    }

    public function test_the_list_finds_orders_by_customer_name_newest_first(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $found = Customer::factory()->for($company)->create(['first_name' => 'Rosalinda']);
        $other = Customer::factory()->for($company)->create(['first_name' => 'Teodoro']);
        WorkOrder::factory()->for($company)->create(['order_number' => '000101', 'customer_id' => $found->id]);
        WorkOrder::factory()->for($company)->create(['order_number' => '000102', 'customer_id' => $other->id]);
        WorkOrder::factory()->for($company)->create(['order_number' => '000103', 'customer_id' => $found->id]);

        Livewire::actingAs($owner)
            ->test(WorkOrdersPage::class)
            ->set('search', 'rosalin')
            ->assertSeeInOrder(['000103', '000101'])
            ->assertDontSee('000102');
    }

    public function test_the_pending_filter_lists_only_orders_in_progress(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        WorkOrder::factory()->for($company)->create(['order_number' => '000101']);
        WorkOrder::factory()->for($company)->completed()->create(['order_number' => '000102']);

        Livewire::actingAs($owner)
            ->test(WorkOrdersPage::class)
            ->assertSee('000102')
            ->set('pendingOnly', true)
            ->assertSee('000101')
            ->assertDontSee('000102');
    }

    public function test_editing_an_order_logs_what_changed_but_keeps_its_type(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $order = WorkOrder::factory()->for($company)->create([
            'order_type' => WorkOrderType::LeakRepair, 'meter_number' => 'A-1', 'assigned_to_id' => $owner->id,
        ]);

        Livewire::actingAs($owner)
            ->test(WorkOrdersPage::class)
            ->call('openEdit', $order->id)
            ->assertSee("Update Work Order {$order->order_number}")
            ->set('edit.meterNumber', 'B-2')
            ->set('edit.orderType', WorkOrderType::PipeRepair->value)
            ->call('update')
            ->assertHasNoErrors()
            ->assertSet('editingId', '');

        $order->refresh();
        $this->assertSame('B-2', $order->meter_number);
        $this->assertSame(WorkOrderType::LeakRepair, $order->order_type);
        $this->assertSame('Edited: Meter No: A-1 → B-2.', $order->activities()->value('description'));
    }

    public function test_orders_can_be_put_on_hold_resumed_and_cancelled_with_a_reason(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $order = WorkOrder::factory()->for($company)->create();

        $page = Livewire::actingAs($owner)
            ->test(WorkOrdersPage::class)
            ->call('openEdit', $order->id)
            ->call('askReason', 'hold')
            ->call('confirmReason')
            ->assertHasErrors(['reason'])
            ->set('reason', 'Waiting for a 1/2" meter')
            ->call('confirmReason')
            ->assertHasNoErrors();

        $this->assertSame(WorkOrderStatus::OnHold, $order->fresh()->status);
        $this->assertSame('Waiting for a 1/2" meter', $order->fresh()->hold_reason);

        $page->call('openEdit', $order->id)->call('resume');
        $this->assertSame(WorkOrderStatus::InProgress, $order->fresh()->status);

        $page->call('openEdit', $order->id)
            ->call('askReason', 'cancel')
            ->set('reason', 'Customer withdrew the request')
            ->call('confirmReason');

        $order->refresh();
        $this->assertSame(WorkOrderStatus::Cancelled, $order->status);
        $this->assertSame('Customer withdrew the request', $order->cancel_reason);
        $this->assertSame(
            ['Put on hold: Waiting for a 1/2" meter', 'Resumed.', 'Cancelled: Customer withdrew the request'],
            $order->activities()->orderBy('id')->pluck('description')->all(),
        );

        // Cancelled is final: the pencil opens the details instead.
        $page->call('openEdit', $order->id)
            ->assertSet('editingId', '')
            ->assertSet('viewingId', (string) $order->id);
    }

    public function test_types_that_do_not_apply_to_the_customer_are_refused(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $pending = Customer::factory()->for($company)->create();
        $connected = Customer::factory()->for($company)->connected()->create();
        WorkOrder::factory()->forCustomer($connected, WorkOrderType::LeakRepair)->create(['order_number' => '000050']);

        $page = Livewire::actingAs($owner)
            ->test(WorkOrdersPage::class)
            ->call('chooseCustomer', $pending->id)
            ->assertSee('Reconnection (customer is not disconnected)')
            ->assertSee('Meter Repair (customer has no meter yet)')
            ->set('values.orderType', WorkOrderType::Reconnection->value)
            ->set('values.assignedToId', (string) $owner->id)
            ->call('submit')
            ->assertHasErrors(['values.orderType'])
            ->assertSee("Reconnection can't be submitted: customer is not disconnected.");

        $page->set('customerSearch', '')
            ->call('chooseCustomer', $connected->id)
            ->assertSet('values.meterNumber', $connected->meter_number)
            ->assertSet('values.serialNumber', $connected->meter_serial_number)
            ->assertSee('New Installation (customer already has a meter)')
            ->set('values.orderType', WorkOrderType::LeakRepair->value)
            ->set('values.assignedToId', (string) $owner->id)
            ->call('submit')
            ->assertHasErrors(['values.orderType'])
            ->assertSee("Leak Repair can't be submitted: work order 000050 is already open.");

        $this->assertSame(1, WorkOrder::count());
    }

    public function test_default_fees_are_saved_and_prefilled_when_completing(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $customer = Customer::factory()->for($company)->disconnected()->create();
        $order = WorkOrder::factory()->forCustomer($customer, WorkOrderType::Reconnection)->create();

        Livewire::actingAs($owner)
            ->test(WorkOrdersPage::class)
            ->call('openFees')
            ->set('fees.'.WorkOrderType::Reconnection->value, 'abc')
            ->call('saveFees')
            ->assertHasErrors(['fees.'.WorkOrderType::Reconnection->value])
            ->set('fees.'.WorkOrderType::Reconnection->value, '250.50')
            ->call('saveFees')
            ->assertHasNoErrors()
            ->assertSet('settingFees', false)
            ->call('openComplete', $order->id)
            ->assertSet('completion.fee', '250.50');

        $this->assertSame('250.50', $company->workOrderFees()->value('amount'));
    }

    public function test_the_view_modal_shows_the_order_details(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $order = WorkOrder::factory()->for($company)->create([
            'order_type' => WorkOrderType::MeterReplacement,
            'serial_number' => 'SN-778',
            'description' => 'Replace the broken meter.',
        ]);

        Livewire::actingAs($owner)
            ->test(WorkOrdersPage::class)
            ->call('openView', $order->id)
            ->assertSee("Work Order {$order->order_number}")
            ->assertSee('Meter Replacement')
            ->assertSee('SN-778')
            ->assertSee('Replace the broken meter.');
    }

    public function test_viewers_cannot_submit_or_update_work_orders(): void
    {
        [, $company] = $this->ownerWithCompany();
        $viewer = $this->memberWithAccess($company, Page::WorkOrders, [Access::View]);
        $order = WorkOrder::factory()->for($company)->create();

        Livewire::actingAs($viewer)
            ->test(WorkOrdersPage::class)
            ->assertDontSee('Submit Work Order')
            ->call('submit')
            ->assertDispatched('flash', message: Access::Add->deniedMessage(), tone: 'error')
            ->call('openEdit', $order->id)
            ->set('reason', 'No reason')
            ->set('reasonFor', 'cancel')
            ->call('confirmReason')
            ->assertDispatched('flash', message: Access::Edit->deniedMessage(), tone: 'error')
            ->call('openComplete', $order->id)
            ->assertSet('completingId', '');

        $this->assertSame(WorkOrderStatus::InProgress, $order->fresh()->status);
    }
}
