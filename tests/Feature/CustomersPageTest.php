<?php

namespace Tests\Feature;

use App\Enums\Access;
use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentType;
use App\Enums\CustomerStatus;
use App\Enums\DisconnectionNoticeStatus;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\MeterReadingStatus;
use App\Enums\Page;
use App\Enums\PaymentMethod;
use App\Enums\ReadingCode;
use App\Enums\WorkOrderType;
use App\Livewire\Customers\CustomersPage;
use App\Models\AccountType;
use App\Models\ChargeType;
use App\Models\ClusterType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DisconnectionNotice;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\MeterReading;
use App\Models\Payment;
use App\Models\RateType;
use App\Models\RolePermission;
use App\Models\WorkOrder;
use Database\Factories\CompanyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CustomersPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function customerValues(Company $company): array
    {
        $city = CompanyFactory::city();

        return [
            'firstName' => 'Rosa',
            'lastName' => 'Lim',
            'companyName' => '',
            'email' => 'rosa@example.com',
            'phoneNumber' => '0917 555 1234',
            'accountTypeId' => (string) AccountType::factory()->for($company)->create()->id,
            'status' => 'connected',
            'chargeTypeId' => (string) ChargeType::factory()->for($company)->create()->id,
            'rateTypeId' => (string) RateType::factory()->for($company)->create()->id,
            'clusterTypeId' => (string) ClusterType::factory()->for($company)->create()->id,
            'homeAddress' => '5 Mabini St.',
            'homeCityId' => (string) $city->id,
            'homeProvinceId' => (string) $city->province_id,
            'homeCountryId' => (string) $city->province->country_id,
            'homeZipCode' => '1000',
            'billingAddress' => '',
            'billingCityId' => '',
            'billingProvinceId' => '',
            'billingCountryId' => '',
            'billingZipCode' => '',
            'latitude' => '14.599512',
            'longitude' => '120.984222',
        ];
    }

    public function test_another_companys_customer_cannot_be_opened_and_its_photo_is_not_found(): void
    {
        [$owner] = $this->ownerWithCompany();
        $stranger = Customer::factory()->create(['first_name' => 'Imelda', 'last_name' => 'Outsider', 'photo_data' => 'photo-bytes', 'photo_mime_type' => 'image/jpeg']);

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('openCustomer', $stranger->id)
            ->assertSet('customer', '')
            ->assertDontSee('Outsider');

        $this->actingAs($owner)->get(route('customers.photo', $stranger))->assertNotFound();
    }

    public function test_customers_are_added_with_the_next_account_number(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        Customer::factory()->for($company)->create(['account_number' => '000041']);

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('openAdd')
            ->assertSee('Add Customer')
            ->set('values', $this->customerValues($company))
            ->set('photo', UploadedFile::fake()->image('rosa.jpg', 100, 100))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('adding', false)
            ->assertDispatched('flash', message: 'Customer added with account number 000042.');

        $customer = Customer::query()->where('account_number', '000042')->firstOrFail();
        $this->assertSame('Rosa', $customer->first_name);
        $this->assertSame(CustomerStatus::Connected, $customer->status);
        $this->assertSame(14.599512, $customer->latitude);
        $this->assertNull($customer->billing_address);
        $this->assertSame('image/jpeg', $customer->photo_mime_type);

        $this->actingAs($owner)->get(route('customers.photo', $customer))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_a_large_customer_photo_is_stored_shrunk_as_a_jpeg(): void
    {
        [$owner, $company] = $this->ownerWithCompany();

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('openAdd')
            ->set('values', $this->customerValues($company))
            ->set('photo', UploadedFile::fake()->image('house.png', 1000, 500))
            ->call('save')
            ->assertHasNoErrors();

        $customer = $company->customers()->sole();
        $stored = $this->actingAs($owner)->get(route('customers.photo', $customer))->assertHeader('Content-Type', 'image/jpeg')->getContent();
        $this->assertSame([800, 400], array_slice(getimagesizefromstring($stored), 0, 2));
        $this->assertSame('image/jpeg', $customer->photo_mime_type);
    }

    public function test_a_partly_filled_billing_address_must_be_completed(): void
    {
        [$owner, $company] = $this->ownerWithCompany();

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('openAdd')
            ->set('values', [...$this->customerValues($company), 'billingAddress' => 'PO Box 1'])
            ->call('save')
            ->assertSet('notice', 'Please correct the highlighted fields.')
            ->assertSee('Select a city.')
            ->assertSee('The zip/postal code field is required.');

        $this->assertSame(0, Customer::count());
    }

    public function test_same_as_home_address_copies_the_address(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $values = $this->customerValues($company);

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('openAdd')
            ->set('values', $values)
            ->call('copyHomeAddress')
            ->assertSet('values.billingAddress', '5 Mabini St.')
            ->assertSet('values.billingCityId', $values['homeCityId'])
            ->assertSet('values.billingZipCode', '1000');
    }

    public function test_only_one_coordinate_is_an_invalid_map_location(): void
    {
        [$owner, $company] = $this->ownerWithCompany();

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('openAdd')
            ->set('values', [...$this->customerValues($company), 'longitude' => ''])
            ->call('save')
            ->assertSee('The map location is invalid. Place the pin again.');
    }

    public function test_inactive_account_types_are_refused_except_the_one_the_customer_has(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $values = $this->customerValues($company);
        $inactive = AccountType::factory()->for($company)->create(['is_active' => false]);

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('openAdd')
            ->set('values', [...$values, 'accountTypeId' => (string) $inactive->id])
            ->call('save')
            ->assertSee('Select an account type from the list.');

        $customer = Customer::factory()->for($company)->create([
            'account_type_id' => $inactive->id,
            'cluster_type_id' => $values['clusterTypeId'],
        ]);

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('openCustomer', $customer->id)
            ->assertSee('Update Customer')
            ->assertSee("Account Number: {$customer->account_number}")
            ->set('values.firstName', 'Renamed')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('flash', message: 'Changes saved.');

        $this->assertSame('Renamed', $customer->fresh()->first_name);
    }

    public function test_view_only_members_see_the_customer_without_saving(): void
    {
        [, $company] = $this->ownerWithCompany();
        $viewer = $this->memberWithAccess($company, Page::Customers, [Access::View]);
        $customer = Customer::factory()->for($company)->create();

        Livewire::actingAs($viewer)
            ->test(CustomersPage::class)
            ->assertDontSee('Add Customer')
            ->call('openCustomer', $customer->id)
            ->assertDontSee('>Update<', false)
            ->set('values.firstName', 'Changed')
            ->call('save')
            ->assertDispatched('flash', message: "You don't have permission to make changes on this page.", tone: 'error');

        $this->assertNotSame('Changed', $customer->fresh()->first_name);
    }

    public function test_search_matches_account_numbers_and_names(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        Customer::factory()->for($company)->create(['account_number' => '000777', 'first_name' => 'Pedro']);
        Customer::factory()->for($company)->create(['account_number' => '000888', 'first_name' => 'Juana']);

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->set('search', '0007')
            ->assertSee('Pedro')
            ->assertDontSee('Juana');
    }

    public function test_history_lists_the_customers_records_latest_first_with_voids_as_their_own_entries(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $customer = Customer::factory()->for($company)->connected()->create();
        $otherCustomer = Customer::factory()->for($company)->connected()->create();

        $this->travelTo(Carbon::parse('2026-10-01 09:00'));
        $order = WorkOrder::factory()->forCustomer($customer, WorkOrderType::LeakRepair)->create();
        $order->log($owner, 'submitted', 'Submitted and assigned to Ben.');

        $this->travelTo(Carbon::parse('2026-10-02 09:00'));
        MeterReading::query()->create([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'billing_period' => '2026-10', 'status' => MeterReadingStatus::Ready,
            'reading_code' => ReadingCode::Normal, 'previous_reading' => '100', 'present_reading' => '112', 'consumption' => '12', 'read_on' => '2026-10-02',
        ]);

        $this->travelTo(Carbon::parse('2026-10-03 09:00'));
        $invoice = Invoice::createNumbered($company, [
            'customer_id' => $customer->id, 'kind' => InvoiceKind::Monthly, 'billing_period' => '2026-10', 'invoice_date' => '2026-10-03',
            'due_date' => '2026-10-18', 'total_amount' => '500.00', 'balance' => '500.00', 'status' => InvoiceStatus::Unpaid,
        ]);

        $this->travelTo(Carbon::parse('2026-10-04 09:00'));
        $memo = InvoiceAdjustment::createNumbered($company, [
            'type' => AdjustmentType::Credit, 'invoice_id' => $invoice->id, 'customer_id' => $customer->id,
            'reason_category' => AdjustmentReason::LeakAdjustment, 'reason' => 'Leak on site', 'amount' => '50.00', 'issued_on' => '2026-10-04',
        ]);

        $this->travelTo(Carbon::parse('2026-10-05 09:00'));
        $payment = Payment::createNumbered($company, [
            'customer_id' => $customer->id, 'paid_on' => '2026-10-05', 'amount' => '450.00', 'unapplied_amount' => '0.00', 'method' => PaymentMethod::Cash,
        ]);

        $this->travelTo(Carbon::parse('2026-10-06 09:00'));
        $payment->update(['voided_at' => now(), 'void_reason' => 'Bounced check', 'voided_by_id' => $owner->id]);

        $this->travelTo(Carbon::parse('2026-10-20 09:00'));
        $notice = DisconnectionNotice::createNumbered($company, [
            'customer_id' => $customer->id, 'status' => DisconnectionNoticeStatus::Pending, 'overdue_bills' => 1,
            'overdue_amount' => '450.00', 'oldest_due_date' => '2026-10-18', 'issued_on' => '2026-10-20', 'deadline' => '2026-10-27',
        ]);

        $otherCustomersBill = Invoice::createNumbered($company, [
            'customer_id' => $otherCustomer->id, 'kind' => InvoiceKind::Monthly, 'billing_period' => '2026-10',
            'invoice_date' => '2026-10-20', 'due_date' => '2026-11-04', 'total_amount' => '300.00', 'balance' => '300.00', 'status' => InvoiceStatus::Unpaid,
        ]);

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('openHistory', $customer->id)
            ->assertSee('Customer History')
            ->assertSeeInOrder([
                "Disconnection notice {$notice->notice_number} issued",
                "Payment {$payment->receipt_number} voided",
                'Bounced check',
                "Payment {$payment->receipt_number} received",
                "Credit Memo {$memo->label()} on invoice {$invoice->invoice_number}",
                "Invoice {$invoice->invoice_number} issued",
                'Meter reading for October 2026',
                "Work order {$order->order_number}",
                'Leak Repair',
                'Submitted and assigned to Ben.',
            ])
            ->assertDontSee("Invoice {$otherCustomersBill->invoice_number} issued")
            ->call('closeHistory')
            ->assertDontSee('Customer History');
    }

    public function test_history_shows_only_the_sections_whose_pages_the_user_may_view(): void
    {
        [, $company] = $this->ownerWithCompany();
        $member = $this->memberWithAccess($company, Page::Customers, [Access::View]);
        RolePermission::query()->create(['role_id' => $member->role_id, 'page' => Page::Payments->value, 'can_add' => false, 'can_edit' => false, 'can_view' => true, 'can_delete' => false]);
        $customer = Customer::factory()->for($company)->connected()->create();
        $invoice = Invoice::createNumbered($company, [
            'customer_id' => $customer->id, 'kind' => InvoiceKind::Monthly, 'billing_period' => '2026-10', 'invoice_date' => '2026-10-03',
            'due_date' => '2026-10-18', 'total_amount' => '500.00', 'balance' => '500.00', 'status' => InvoiceStatus::Unpaid,
        ]);
        $payment = Payment::createNumbered($company, [
            'customer_id' => $customer->id, 'paid_on' => '2026-10-05', 'amount' => '450.00', 'unapplied_amount' => '0.00', 'method' => PaymentMethod::Cash,
        ]);

        Livewire::actingAs($member)
            ->test(CustomersPage::class)
            ->call('openHistory', $customer->id)
            ->assertSee("Payment {$payment->receipt_number} received")
            ->assertDontSee("Invoice {$invoice->invoice_number} issued")
            ->assertSee('have access to them: work orders, invoices and memos, meter readings, disconnection notices.');
    }

    public function test_another_companys_customer_history_cannot_be_opened(): void
    {
        [$owner] = $this->ownerWithCompany();
        $stranger = Customer::factory()->create(['first_name' => 'Imelda', 'last_name' => 'Outsider']);

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('openHistory', $stranger->id)
            ->assertSet('historyCustomerId', null)
            ->assertDontSee('Customer History')
            ->assertDontSee('Outsider');
    }
}
