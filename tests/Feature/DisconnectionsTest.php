<?php

namespace Tests\Feature;

use App\Enums\Access;
use App\Enums\CustomerStatus;
use App\Enums\DisconnectionNoticeStatus;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\Page;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Livewire\Company\OperationSettingsForm;
use App\Livewire\Disconnections\DisconnectionsPage;
use App\Livewire\WorkOrders\WorkOrdersPage;
use App\Models\ClusterType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DisconnectionNotice;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Billing\Ledger;
use App\Support\Disconnections;
use App\Support\WorkOrderRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DisconnectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday, October 19, 2026 at noon in Manila.
        $this->travelTo(Carbon::parse('2026-10-19 12:00', 'Asia/Manila'));

        [$this->owner, $this->company] = $this->ownerWithCompany();
        $this->company->update(['disconnection_overdue_bills' => 2, 'disconnection_notice_days' => 5, 'disconnection_skip_days' => '6,7']);
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->for($this->company)->connected()->create($attributes);
    }

    private function bill(Customer $customer, string $dueDate, string $amount = '500.00'): Invoice
    {
        return Invoice::createNumbered($this->company, [
            'customer_id' => $customer->id,
            'kind' => InvoiceKind::Monthly,
            'billing_period' => substr($dueDate, 0, 7),
            'invoice_date' => $dueDate,
            'due_date' => $dueDate,
            'total_amount' => $amount,
            'balance' => $amount,
            'status' => InvoiceStatus::Unpaid,
        ]);
    }

    /**
     * A customer with the given number of overdue bills of 500.00, the oldest due first.
     */
    private function overdueCustomer(int $bills = 2, array $attributes = []): Customer
    {
        $customer = $this->customer($attributes);
        for ($month = $bills; $month >= 1; $month--) {
            $this->bill($customer, Carbon::parse('2026-10-15')->subMonthsNoOverflow($month - 1)->toDateString());
        }

        return $customer;
    }

    private function pay(Customer $customer, string $amount): void
    {
        Ledger::recordPayment($customer, [
            'paid_on' => '2026-10-19', 'amount' => $amount, 'method' => 'cash', 'reference_number' => null, 'notes' => null,
        ], $this->owner);
    }

    /**
     * Issues a notice to the customer and moves past its deadline, then creates the work order.
     */
    private function scheduledNotice(Customer $customer): DisconnectionNotice
    {
        $notice = Disconnections::issue($this->company, [$customer->id], $this->owner, false)['issued'][0];
        $this->travelTo(Carbon::parse('2026-10-27 09:00', 'Asia/Manila'));
        Disconnections::createWorkOrders($this->company->fresh(), [$notice->id], $this->owner->id, $this->owner);

        return $notice->fresh();
    }

    public function test_customers_with_enough_overdue_bills_are_eligible_unless_on_hold(): void
    {
        $eligible = $this->overdueCustomer(2);
        $expiredExemption = $this->overdueCustomer(2, ['disconnection_exempt_reason' => 'Typhoon relief', 'disconnection_exempt_until' => '2026-10-18']);

        $oneOverdue = $this->overdueCustomer(1);
        $this->bill($oneOverdue, '2026-10-25');
        $exempt = $this->overdueCustomer(2, ['disconnection_exempt_reason' => 'Hospital']);
        $disputing = $this->overdueCustomer(3);
        WorkOrder::factory()->forCustomer($disputing, WorkOrderType::BillingComplaint)->create();
        $this->overdueCustomer(2, ['status' => CustomerStatus::Disconnected]);

        $today = $this->company->today();
        $this->assertEqualsCanonicalizing(
            [$eligible->id, $expiredExemption->id],
            Disconnections::eligible($this->company, $today)->pluck('customers.id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$exempt->id, $disputing->id],
            Disconnections::onHold($this->company, $today)->pluck('customers.id')->all(),
        );
        $this->assertStringStartsWith('billing complaint', Disconnections::holdReason($disputing, $today));
    }

    public function test_issuing_notices_sets_a_working_day_deadline_and_emails_customers_with_an_address(): void
    {
        // Thursday: five working days, skipping the weekend, end on the next Thursday.
        $this->travelTo(Carbon::parse('2026-10-22 09:00', 'Asia/Manila'));
        $withEmail = $this->overdueCustomer(2, ['email' => 'juan@example.com']);
        $withoutEmail = $this->overdueCustomer(3);
        $notEligible = $this->overdueCustomer(1);

        Livewire::actingAs($this->owner)
            ->test(DisconnectionsPage::class)
            ->set('selected', [(string) $withEmail->id, (string) $withoutEmail->id, (string) $notEligible->id])
            ->set('emailNotices', true)
            ->call('issueNotices')
            ->assertDispatched('flash', fn (string $name, array $params): bool => $params['message'] === 'Issued 2 notices. 1 emailed, the rest have no email address. 1 skipped: no longer eligible.');

        $notice = $withEmail->disconnectionNotices()->sole();
        $this->assertSame(DisconnectionNoticeStatus::Pending, $notice->status);
        $this->assertSame('2026-10-29', $notice->deadline->toDateString());
        $this->assertSame(2, $notice->overdue_bills);
        $this->assertSame('1000.00', $notice->overdue_amount);
        $this->assertNotNull($notice->emailed_at);
        $this->assertDatabaseHas('email_outbox', ['to' => 'juan@example.com', 'subject' => "Notice of Disconnection {$notice->notice_number} – {$this->company->company_name}"]);
        $this->assertNull($withoutEmail->disconnectionNotices()->sole()->emailed_at);
        $this->assertSame(0, $notEligible->disconnectionNotices()->count());

        // Without skipped days, every day counts.
        $this->company->update(['disconnection_skip_days' => '']);
        $this->assertSame('2026-10-27', Disconnections::deadline($this->company->fresh(), '2026-10-22'));
    }

    public function test_paying_until_fewer_bills_than_the_trigger_are_overdue_settles_the_notice_and_cancels_its_work_order(): void
    {
        $customer = $this->overdueCustomer(3);
        $notice = $this->scheduledNotice($customer);
        $order = $notice->workOrder;
        $this->assertSame(DisconnectionNoticeStatus::Scheduled, $notice->status);

        // Three overdue to two: still at the trigger.
        $this->pay($customer, '500.00');
        $this->assertSame(DisconnectionNoticeStatus::Scheduled, $notice->fresh()->status);
        $this->assertSame(WorkOrderStatus::InProgress, $order->fresh()->status);

        // Two to one: under it.
        $this->pay($customer, '500.00');
        $notice->refresh();
        $this->assertSame(DisconnectionNoticeStatus::Settled, $notice->status);
        $this->assertSame('Paid: 1 overdue bill(s) left, under the 2 that trigger disconnection.', $notice->close_reason);
        $this->assertSame(WorkOrderStatus::Cancelled, $order->fresh()->status);
        $this->assertStringContainsString("notice {$notice->notice_number} Settled", $order->fresh()->cancel_reason);
    }

    public function test_work_orders_are_created_only_after_the_deadline_and_not_for_customers_on_hold(): void
    {
        $customer = $this->overdueCustomer(2);
        $disputing = $this->overdueCustomer(2);
        $issued = Disconnections::issue($this->company, [$customer->id, $disputing->id], $this->owner, false)['issued'];
        $ids = array_map(fn (DisconnectionNotice $notice): string => (string) $notice->id, $issued);

        // The deadline is Monday the 26th; it isn't past yet.
        $this->travelTo(Carbon::parse('2026-10-26 09:00', 'Asia/Manila'));
        $result = Disconnections::createWorkOrders($this->company, array_map('intval', $ids), null, $this->owner);
        $this->assertSame([], $result['created']);
        $this->assertStringContainsString('is not past its deadline', $result['skipped'][0]);

        $this->travelTo(Carbon::parse('2026-10-27 09:00', 'Asia/Manila'));
        $complaint = WorkOrder::factory()->forCustomer($disputing, WorkOrderType::BillingComplaint)->create();

        Livewire::actingAs($this->owner)
            ->test(DisconnectionsPage::class)
            ->set('tab', 'due')
            ->set('selected', $ids)
            ->set('assigneeId', (string) $this->owner->id)
            ->call('createWorkOrders')
            ->assertSet('skipped', ["{$disputing->account_number} {$disputing->fullName()}: on hold, billing complaint {$complaint->order_number} is open."]);

        $order = $customer->workOrders()->where('order_type', WorkOrderType::Disconnection)->sole();
        $this->assertSame($this->owner->id, $order->assigned_to_id);
        $this->assertSame($order->id, $customer->disconnectionNotices()->sole()->work_order_id);
        $this->assertSame(DisconnectionNoticeStatus::Pending, $disputing->disconnectionNotices()->sole()->status);
    }

    public function test_completing_the_disconnection_closes_the_notice_and_reconnection_waits_for_the_overdue_bills(): void
    {
        $customer = $this->overdueCustomer(2);
        $notice = $this->scheduledNotice($customer);
        $order = $notice->workOrder;

        // Livewire's fake uploads need the real clock; the bills are still overdue today.
        $this->travelBack();
        $order->update(['requested_at' => now()]);

        Livewire::actingAs($this->owner)
            ->test(WorkOrdersPage::class)
            ->call('openComplete', $order->id)
            ->set('completion.fields.reason', 'non_payment')
            ->set('completion.fields.final_reading', '1520')
            ->set('completion.fields.seal_number', 'SEAL-1')
            ->set('completion.checked', array_fill_keys(array_keys($order->order_type->checklist()), true))
            ->set('newPhotos', [UploadedFile::fake()->image('proof.jpg', 60, 60)])
            ->call('complete')
            ->assertHasNoErrors();

        $this->assertSame(CustomerStatus::Disconnected, $customer->fresh()->status);
        $this->assertSame(DisconnectionNoticeStatus::Disconnected, $notice->fresh()->status);

        $this->assertSame('customer still has 2 overdue bill(s) to pay', WorkOrderRules::whyNot(WorkOrderType::Reconnection, $customer->fresh()));
        $this->pay($customer, '1000.00');
        $this->assertNull(WorkOrderRules::whyNot(WorkOrderType::Reconnection, $customer->fresh()));
    }

    public function test_cancelling_a_notice_needs_delete_access_and_cancels_its_work_order(): void
    {
        $customer = $this->overdueCustomer(2);
        $notice = $this->scheduledNotice($customer);
        $member = $this->memberWithAccess($this->company, Page::Disconnections, [Access::View, Access::Add]);

        Livewire::actingAs($member)
            ->test(DisconnectionsPage::class)
            ->call('askCancel', $notice->id)
            ->set('cancelReason', 'Promised to pay Friday')
            ->call('cancelNotice')
            ->assertDispatched('flash', fn (string $name, array $params): bool => $params['message'] === Access::Delete->deniedMessage());
        $this->assertSame(DisconnectionNoticeStatus::Scheduled, $notice->fresh()->status);

        Livewire::actingAs($this->owner)
            ->test(DisconnectionsPage::class)
            ->call('askCancel', $notice->id)
            ->call('cancelNotice')
            ->assertHasErrors(['cancelReason'])
            ->set('cancelReason', 'Promised to pay Friday')
            ->call('cancelNotice')
            ->assertHasNoErrors();

        $this->assertSame(DisconnectionNoticeStatus::Cancelled, $notice->fresh()->status);
        $this->assertSame('Promised to pay Friday', $notice->fresh()->close_reason);
        $this->assertSame(WorkOrderStatus::Cancelled, $notice->workOrder->fresh()->status);
    }

    public function test_a_bill_due_today_is_not_overdue_until_tomorrow(): void
    {
        $customer = $this->overdueCustomer(1);
        $this->bill($customer, '2026-10-19');

        $this->assertSame([], Disconnections::eligible($this->company, '2026-10-19')->pluck('customers.id')->all());
        $this->assertSame([$customer->id], Disconnections::eligible($this->company, '2026-10-20')->pluck('customers.id')->all());
    }

    public function test_the_tabs_are_counted_and_the_notice_tabs_are_searched_by_customer_and_cluster(): void
    {
        $cluster = ClusterType::factory()->for($this->company)->create();
        $this->overdueCustomer(2);
        $this->overdueCustomer(2, ['disconnection_exempt_reason' => 'Hospital']);
        $found = $this->overdueCustomer(2, ['first_name' => 'Rosalinda', 'cluster_type_id' => $cluster->id]);
        $other = $this->overdueCustomer(2, ['first_name' => 'Teodoro']);
        [$foundNotice, $otherNotice] = Disconnections::issue($this->company, [$found->id, $other->id], $this->owner, false)['issued'];

        $page = Livewire::actingAs($this->owner)->test(DisconnectionsPage::class)
            ->assertViewHas('counts', ['eligible' => 1, 'sent' => 2, 'due' => 0, 'field' => 0, 'hold' => 1])
            ->set('tab', 'sent')
            ->assertSee($foundNotice->notice_number)
            ->assertSee($otherNotice->notice_number);

        $page->set('search', 'rosalin')
            ->assertSee($foundNotice->notice_number)
            ->assertDontSee($otherNotice->notice_number);

        $page->set('search', '')
            ->set('clusterId', (string) $cluster->id)
            ->assertSee($foundNotice->notice_number)
            ->assertDontSee($otherNotice->notice_number);
    }

    public function test_each_tab_lists_its_customers_or_notices(): void
    {
        $eligible = $this->overdueCustomer(2);
        $exempt = $this->overdueCustomer(2, ['disconnection_exempt_reason' => 'Hospital']);
        $waiting = $this->overdueCustomer(2);
        $notice = Disconnections::issue($this->company, [$waiting->id], $this->owner, false)['issued'][0];

        $page = Livewire::actingAs($this->owner)->test(DisconnectionsPage::class)
            ->assertSee($eligible->account_number)
            ->assertDontSee($exempt->account_number);

        $page->set('tab', 'sent')->assertSee($notice->notice_number)->assertSee('5 days left');
        $page->set('tab', 'hold')->assertSee($exempt->account_number)->assertSee('Exempt: Hospital');

        $this->travelTo(Carbon::parse('2026-10-27 09:00', 'Asia/Manila'));
        $page->set('tab', 'due')->assertSee($notice->notice_number)->assertSee('Create Work Orders');

        Disconnections::createWorkOrders($this->company, [$notice->id], null, $this->owner);
        $page->set('tab', 'field')->assertSee($notice->workOrder()->value('order_number'))->assertSee('Unassigned');

        $this->pay($waiting, '1000.00');
        $page->set('tab', 'history')->assertSee($notice->notice_number)->assertSee('Paid: no overdue bills left.');
    }

    public function test_a_member_without_add_access_cannot_issue_notices(): void
    {
        $customer = $this->overdueCustomer(2);
        $member = $this->memberWithAccess($this->company, Page::Disconnections, [Access::View]);

        Livewire::actingAs($member)
            ->test(DisconnectionsPage::class)
            ->assertSee($customer->account_number)
            ->assertDontSee('Issue Notices')
            ->set('selected', [(string) $customer->id])
            ->call('issueNotices')
            ->assertDispatched('flash', fn (string $name, array $params): bool => $params['message'] === Access::Add->deniedMessage());

        $this->assertSame(0, DisconnectionNotice::query()->count());
    }

    public function test_notices_print_in_one_batch_and_another_companys_notices_are_not_found(): void
    {
        $first = $this->overdueCustomer(2);
        $second = $this->overdueCustomer(2);
        $issued = Disconnections::issue($this->company, [$first->id, $second->id], $this->owner, false)['issued'];

        $this->actingAs($this->owner)
            ->get(route('disconnections.print', ['notices' => $issued[0]->id.','.$issued[1]->id]))
            ->assertOk()
            ->assertSee('Notice of Disconnection')
            ->assertSee($first->account_number)
            ->assertSee($second->account_number)
            ->assertSee('October 26 2026');
        $this->assertNotNull($issued[0]->fresh()->printed_at);

        [$otherOwner] = $this->ownerWithCompany();
        $this->actingAs($otherOwner)
            ->get(route('disconnections.print', ['notices' => $issued[0]->id]))
            ->assertNotFound();
    }

    public function test_operation_setup_saves_the_disconnection_settings_and_keeps_a_working_day(): void
    {
        $form = Livewire::actingAs($this->owner)
            ->test(OperationSettingsForm::class)
            ->set('currency', 'PHP')
            ->set('disconnectionOverdueBills', '3')
            ->set('disconnectionNoticeDays', '7')
            ->set('disconnectionSkipDays', ['7'])
            ->call('save')
            ->assertHasNoErrors();

        $company = $this->company->fresh();
        $this->assertSame(3, $company->disconnection_overdue_bills);
        $this->assertSame(7, $company->disconnection_notice_days);
        $this->assertSame([7], $company->disconnectionSkipDays());

        $form->set('disconnectionSkipDays', ['1', '2', '3', '4', '5', '6', '7'])
            ->set('disconnectionOverdueBills', '0')
            ->call('save')
            ->assertHasErrors(['disconnectionSkipDays', 'disconnectionOverdueBills'])
            ->assertSee('Leave at least one working day in the week.')
            ->assertSee('Enter a whole number of overdue bills from 1 to 24.');
    }
}
