<?php

namespace Tests\Feature;

use App\Enums\Access;
use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentType;
use App\Enums\ChargeValueType;
use App\Enums\DistributionType;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceLineKind;
use App\Enums\InvoiceStatus;
use App\Enums\MeterReadingStatus;
use App\Enums\OverdueHandling;
use App\Enums\Page;
use App\Enums\ReadingCode;
use App\Enums\ReadingKind;
use App\Enums\WorkOrderFlag;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Livewire\Invoices\GenerateBillsPage;
use App\Livewire\Invoices\InvoicesPage;
use App\Livewire\Payments\PaymentsPage;
use App\Models\ChargeElement;
use App\Models\ChargeType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RateType;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderReading;
use App\Support\Billing\Adjustments;
use App\Support\Billing\BillingRun;
use App\Support\Billing\Ledger;
use App\Support\Billing\MeterReadings;
use App\Support\Billing\WorkOrderBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BillingTest extends TestCase
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
        $this->company->update(['due_date_days' => 15]);

        // 0–10 @ 10.00, 11–20 @ 25.00, 21+ @ 35.00, minimum 10 cu.m. — the company's Residential Rate.
        $this->rate = RateType::factory()->for($this->company)->create(['name' => 'Residential']);
        $this->rate->ranges()->createMany([
            ['range_from' => 0, 'range_to' => 10, 'rate' => '10.00', 'description' => '0-10 cu.m.'],
            ['range_from' => 11, 'range_to' => 20, 'rate' => '25.00', 'description' => '11-20 cu.m.'],
            ['range_from' => 21, 'range_to' => 99999, 'rate' => '35.00', 'description' => 'Above 20 cu.m.'],
        ]);
        $this->rate->setup()->create([
            'minimum_consumption' => 10,
            'defective_uses_minimum_consumption' => true,
            'defective_uses_three_month_average' => true,
        ]);

        // Surcharge 2% after due (Credit adds) and no regular charges, like the company's Standard Charge Type.
        $this->chargeType = ChargeType::factory()->for($this->company)->create();
        $this->addElement('Surcharge', DistributionType::Credit, ChargeValueType::Percentage, '2.00', afterDue: true);
    }

    private function addElement(string $name, DistributionType $distribution, ChargeValueType $valueType, string $value, bool $afterDue = false): void
    {
        $element = ChargeElement::factory()->for($this->company)->create([
            'name' => $name, 'distribution_type' => $distribution, 'is_after_due' => $afterDue,
        ]);
        $this->chargeType->elements()->create(['charge_element_id' => $element->id, 'value_type' => $valueType, 'value' => $value]);
    }

    private function customer(array $attributes = []): Customer
    {
        $customer = Customer::factory()->for($this->company)->connected()->create([
            'rate_type_id' => $this->rate->id,
            'charge_type_id' => $this->chargeType->id,
            'meter_number' => 'MTR-1',
            ...$attributes,
        ]);

        // The meter's initial reading from its New Installation.
        $installation = WorkOrder::factory()->forCustomer($customer, WorkOrderType::NewInstallation)->completed()->create();
        WorkOrderReading::query()->create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id, 'work_order_id' => $installation->id,
            'meter_number' => $customer->meter_number, 'kind' => ReadingKind::Initial, 'reading' => '0', 'read_on' => now()->subMonths(4)->toDateString(),
        ]);

        return $customer;
    }

    private function bill(Customer $customer, string $period, string $present, bool $defective = false, ?string $invoiceDate = null): Invoice
    {
        $date = $invoiceDate ?? now()->toDateString();

        return BillingRun::generate($customer, $period, $present, $defective, $date, $date, $this->owner);
    }

    public function test_water_is_charged_by_tier_with_a_minimum_consumption(): void
    {
        $customer = $this->customer();
        $period = now()->format('Y-m');

        $this->assertSame(225_00, BillingRun::compute($customer, $period, '15', false, now()->toDateString())['totalCents']);
        $this->assertSame(100_00, BillingRun::compute($customer, $period, '5', false, now()->toDateString())['totalCents']);
        $this->assertTrue(BillingRun::compute($customer, $period, '5', false, now()->toDateString())['minimumApplied']);
        $this->assertSame(525_00, BillingRun::compute($customer, $period, '25', false, now()->toDateString())['totalCents']);
    }

    public function test_credit_elements_add_and_debit_elements_deduct(): void
    {
        $this->addElement('Maintenance fee', DistributionType::Credit, ChargeValueType::Fixed, '50.00');
        $this->addElement('Senior Discount', DistributionType::Debit, ChargeValueType::Percentage, '20.00');
        $customer = $this->customer();

        $invoice = $this->bill($customer, now()->format('Y-m'), '15');

        // 225.00 water + 50.00 maintenance − 20% of 225.00
        $this->assertSame('230.00', $invoice->total_amount);
        $this->assertSame('-45.00', $invoice->lines()->where('description', 'like', 'Senior Discount%')->value('amount'));
        $this->assertSame(0, $invoice->lines()->where('kind', InvoiceLineKind::Surcharge)->count());
    }

    public function test_the_billing_run_page_lists_the_charges_behind_each_amount(): void
    {
        $this->addElement('Maintenance fee', DistributionType::Credit, ChargeValueType::Fixed, '50.00');
        $this->addElement('Senior Discount', DistributionType::Debit, ChargeValueType::Percentage, '20.00');
        $customer = $this->customer();
        MeterReadings::save($customer, now()->format('Y-m'), '15', ReadingCode::Normal, now()->toDateString(), $this->owner);
        $breakdown = ['Water', '225.00', 'Maintenance fee', '50.00', 'Senior Discount (20.00%)', '-45.00', '230.00'];

        // Before billing the breakdown is the preview; after, it's read from the issued invoice.
        Livewire::actingAs($this->owner)
            ->test(GenerateBillsPage::class)
            ->assertSeeInOrder($breakdown)
            ->call('generate')
            ->assertSeeInOrder($breakdown);
    }

    public function test_the_billing_run_page_issues_bills_for_ready_readings(): void
    {
        $customer = $this->customer();
        $flagged = $this->customer(['meter_number' => 'MTR-2']);
        $period = now()->format('Y-m');
        $today = now()->toDateString();
        MeterReadings::save($customer, $period, '15', ReadingCode::Normal, $today, $this->owner);
        // No usage since the last reading needs review, so it isn't billed yet.
        MeterReadings::save($flagged, $period, '0', ReadingCode::Normal, $today, $this->owner);

        Livewire::actingAs($this->owner)
            ->test(GenerateBillsPage::class)
            ->assertSee($customer->account_number)
            ->assertSee('225.00')
            ->assertSee('1 reading need review')
            ->call('generate')
            ->assertDispatched('flash', fn (string $name, array $params): bool => str_contains($params['message'], '1 bill generated'));

        $invoice = $customer->invoices()->sole();
        $this->assertSame(InvoiceKind::Monthly, $invoice->kind);
        $this->assertSame($period, $invoice->billing_period);
        $this->assertSame('225.00', $invoice->balance);
        $this->assertSame(now()->addDays(15)->toDateString(), $invoice->due_date->toDateString());
        $this->assertSame('15.000', $invoice->meterReading->present_reading);
        $this->assertSame(MeterReadingStatus::Billed, $invoice->meterReading->status);
        $this->assertSame(0, $flagged->invoices()->count());
        $this->assertSame(MeterReadingStatus::Review, $flagged->meterReadings()->sole()->status);

        // Billed readings show as billed and can't be billed twice for the month.
        Livewire::actingAs($this->owner)->test(GenerateBillsPage::class)->assertSee("invoice {$invoice->invoice_number}");
        $this->expectExceptionMessage('Already billed for this period');
        $this->bill($customer, $period, '20');
    }

    public function test_the_billing_run_bills_a_large_month_in_batches_with_the_settings_it_started_with(): void
    {
        $period = now()->format('Y-m');
        $today = now()->toDateString();
        $customers = collect(range(1, GenerateBillsPage::BATCH_SIZE + 1))->map(function () use ($period, $today): Customer {
            $customer = $this->customer();
            MeterReadings::save($customer, $period, '15', ReadingCode::Normal, $today, $this->owner);

            return $customer;
        });

        $page = Livewire::actingAs($this->owner)
            ->test(GenerateBillsPage::class)
            ->call('generate')
            ->assertSet('run.issued', GenerateBillsPage::BATCH_SIZE)
            ->assertNotDispatched('flash');
        $this->assertSame(GenerateBillsPage::BATCH_SIZE, Invoice::query()->count());

        // Changing the month mid-run doesn't change what the run bills.
        $page->set('period', now()->subMonth()->format('Y-m'))
            ->call('generateBatch')
            ->assertSet('run', null)
            ->assertDispatched('flash', fn (string $name, array $params): bool => str_contains($params['message'], $customers->count().' bills generated'));

        foreach ($customers as $customer) {
            $this->assertSame($period, $customer->invoices()->sole()->billing_period);
            $this->assertSame($today, $customer->invoices()->sole()->invoice_date->toDateString());
        }
    }

    public function test_the_invoice_and_payment_lists_find_records_by_customer_name(): void
    {
        $found = $this->customer(['first_name' => 'Rosalinda']);
        $other = $this->customer(['first_name' => 'Teodoro']);
        $foundBill = $this->bill($found, now()->format('Y-m'), '15');
        $this->bill($other, now()->format('Y-m'), '15');
        $payment = ['paid_on' => now()->toDateString(), 'amount' => '10.00', 'method' => 'cash', 'reference_number' => null, 'notes' => null];
        $foundReceipt = Ledger::recordPayment($found, $payment, $this->owner);
        Ledger::recordPayment($other, $payment, $this->owner);

        Livewire::actingAs($this->owner)
            ->test(InvoicesPage::class)
            ->set('search', 'rosalin')
            ->assertViewHas('invoices', fn ($invoices): bool => $invoices->pluck('id')->all() === [$foundBill->id]);

        Livewire::actingAs($this->owner)
            ->test(PaymentsPage::class)
            ->set('search', 'rosalin')
            ->assertViewHas('payments', fn ($payments): bool => $payments->pluck('id')->all() === [$foundReceipt->id]);
    }

    public function test_credit_and_debit_memos_are_numbered_separately(): void
    {
        $invoice = $this->bill($this->customer(), now()->format('Y-m'), '15');
        $memo = fn (AdjustmentType $type) => Adjustments::issue($invoice, $type, $type === AdjustmentType::Credit ? AdjustmentReason::MeterReadingError : AdjustmentReason::Undercharge, 'Correction', 10_00, now()->toDateString(), null, $this->owner);

        $this->assertSame('CM-000001', $memo(AdjustmentType::Credit)->label());
        $this->assertSame('DM-000001', $memo(AdjustmentType::Debit)->label());
        $this->assertSame('CM-000002', $memo(AdjustmentType::Credit)->label());
    }

    public function test_the_next_bill_starts_from_the_last_reading_and_rejects_a_lower_one(): void
    {
        $customer = $this->customer();
        $this->bill($customer, now()->subMonth()->format('Y-m'), '15');

        $next = BillingRun::compute($customer, now()->format('Y-m'), '27', false, now()->toDateString());
        $this->assertSame(15.0, $next['previous']);
        $this->assertSame(12.0, $next['consumption']);

        $this->expectExceptionMessage('is lower than the previous reading');
        BillingRun::compute($customer, now()->format('Y-m'), '14', false, now()->toDateString());
    }

    public function test_a_defective_meter_is_billed_on_the_three_month_average_or_the_minimum(): void
    {
        $customer = $this->customer();
        $this->assertSame(10.0, BillingRun::compute($customer, now()->format('Y-m'), '', true, now()->toDateString())['billed']);

        $this->bill($customer, now()->subMonths(3)->format('Y-m'), '12');
        $this->bill($customer, now()->subMonths(2)->format('Y-m'), '30');
        $this->bill($customer, now()->subMonth()->format('Y-m'), '51');

        // 12, 18 and 21 cu.m. → 17 cu.m. average
        $estimate = BillingRun::compute($customer, now()->format('Y-m'), '', true, now()->toDateString());
        $this->assertSame(17.0, $estimate['billed']);
        $this->assertTrue($estimate['estimated']);
    }

    public function test_a_meter_replaced_during_the_month_is_billed_for_both_meters(): void
    {
        $customer = $this->customer();
        $this->bill($customer, now()->subMonth()->format('Y-m'), '100');

        $replacement = WorkOrder::factory()->forCustomer($customer, WorkOrderType::MeterReplacement)->completed()->create();
        foreach ([['MTR-1', ReadingKind::Final, '108'], ['MTR-NEW', ReadingKind::Initial, '0']] as [$meter, $kind, $reading]) {
            WorkOrderReading::query()->create([
                'company_id' => $this->company->id, 'customer_id' => $customer->id, 'work_order_id' => $replacement->id,
                'meter_number' => $meter, 'kind' => $kind, 'reading' => $reading, 'read_on' => now()->toDateString(),
            ]);
        }
        $customer->update(['meter_number' => 'MTR-NEW']);

        $bill = BillingRun::compute($customer->fresh(), now()->format('Y-m'), '5', false, now()->toDateString());
        $this->assertSame(0.0, $bill['previous']);
        $this->assertSame(8.0, $bill['replaced']);
        $this->assertSame(13.0, $bill['consumption']);
    }

    public function test_work_order_fees_go_on_their_own_invoice_or_the_next_bill(): void
    {
        $customer = $this->customer();

        // New Installation defaults to its own invoice.
        $installation = WorkOrder::factory()->forCustomer($customer, WorkOrderType::NewInstallation)->completed()->create(['fee_amount' => '1500.00']);
        $feeInvoice = WorkOrderBilling::onCompleted($installation, $this->owner, now()->toDateString());
        $this->assertSame(InvoiceKind::WorkOrder, $feeInvoice->kind);
        $this->assertSame('1500.00', $feeInvoice->total_amount);
        $this->assertSame($feeInvoice->id, $installation->fresh()->fee_invoice_id);

        // Leak Repair defaults to the next monthly bill.
        $leak = WorkOrder::factory()->forCustomer($customer, WorkOrderType::LeakRepair)->completed()->create(['fee_amount' => '300.00']);
        $this->assertNull(WorkOrderBilling::onCompleted($leak, $this->owner, now()->toDateString()));

        $bill = $this->bill($customer, now()->format('Y-m'), '15');
        $this->assertSame('525.00', $bill->total_amount);
        $this->assertSame($bill->id, $leak->fresh()->fee_invoice_id);
        $this->assertSame('1500.00', $bill->previous_balance);
    }

    public function test_the_invoice_list_names_what_each_invoice_is_for_with_long_dates(): void
    {
        $customer = $this->customer();
        $bill = $this->bill($customer, '2026-10', '15', invoiceDate: '2026-10-05');
        $installation = WorkOrder::factory()->forCustomer($customer, WorkOrderType::NewInstallation)->completed()->create(['fee_amount' => '1500.00']);
        WorkOrderBilling::onCompleted($installation, $this->owner, '2026-10-05');

        Livewire::actingAs($this->owner)
            ->test(InvoicesPage::class)
            ->assertSeeInOrder(['Water Bill', 'October 2026'])
            ->assertSee('Work Order Fee')
            ->assertSeeInOrder(["Acct No: {$customer->account_number}", 'Billed: Oct. 5 2026', 'Due: Oct. 20 2026'])
            ->assertDontSee('10/05/2026')
            ->call('openInvoice', $bill->id)
            ->assertSee('Issued October 5 2026');
    }

    public function test_payments_go_to_the_oldest_bill_and_the_excess_becomes_advance_credit(): void
    {
        $customer = $this->customer();
        $first = $this->bill($customer, now()->subMonth()->format('Y-m'), '15');     // 225.00
        $second = $this->bill($customer, now()->format('Y-m'), '25');               // 10 cu.m. → 100.00

        Livewire::actingAs($this->owner)
            ->test(PaymentsPage::class, ['preselect' => (string) $customer->id])
            ->assertSet('customerId', (string) $customer->id)
            ->assertSet('amount', '325.00')
            ->set('amount', '300')
            ->set('method', 'e_wallet')
            ->call('record')
            ->assertHasErrors(['referenceNumber'])
            ->assertSet('confirming', false)
            ->set('referenceNumber', 'GC-123')
            ->call('record')
            ->assertHasNoErrors()
            ->assertSet('confirming', true)
            ->assertSee(['Confirm Payment', 'GC-123', '300.00'])
            ->assertNotDispatched('flash')
            ->call('confirmRecord')
            ->assertSet('confirming', false)
            ->assertDispatched('flash', fn (string $name, array $params): bool => str_contains($params['message'], 'Official receipt 000001'));

        $this->assertSame(InvoiceStatus::Paid, $first->fresh()->status);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $second->fresh()->status);
        $this->assertSame('25.00', $second->fresh()->balance);

        // 100.00 more: 25.00 settles the second bill, 75.00 is advance credit for the next one.
        $payment = Ledger::recordPayment($customer, ['paid_on' => now()->toDateString(), 'amount' => '100.00', 'method' => 'cash', 'reference_number' => null, 'notes' => null], $this->owner);
        $this->assertSame('75.00', $payment->unapplied_amount);
        $this->assertSame(['owed' => 0, 'credit' => 75_00], Ledger::summary($customer));

        $third = $this->bill($customer, now()->addMonth()->format('Y-m'), '35', invoiceDate: now()->toDateString());
        $this->assertSame('100.00', $third->total_amount);
        $this->assertSame('25.00', $third->balance);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $third->status);
    }

    public function test_a_payment_is_not_saved_until_it_is_confirmed_and_an_overpayment_is_flagged(): void
    {
        $customer = $this->customer();
        $this->bill($customer, now()->format('Y-m'), '15');     // 225.00

        $page = Livewire::actingAs($this->owner)
            ->test(PaymentsPage::class, ['preselect' => (string) $customer->id])
            ->set('amount', '2250')
            ->call('record')
            ->assertSet('confirming', true)
            ->assertSee('This is 2,025.00 more than the 225.00 owed.');

        $page->call('cancelConfirmation')->assertSet('confirming', false)->assertSet('amount', '2250');
        $this->assertSame(0, $customer->payments()->count());

        $page->call('record')->set('amount', '225')->assertSet('confirming', false);
        $this->assertSame(0, $customer->payments()->count());

        $page->call('record')->assertDontSee('more than the')->call('confirmRecord');
        $this->assertSame('225.00', $customer->payments()->sole()->amount);
    }

    public function test_voiding_a_payment_reopens_its_bills_and_voiding_a_bill_frees_the_month(): void
    {
        $customer = $this->customer();
        $period = now()->format('Y-m');
        $invoice = $this->bill($customer, $period, '15');
        $payment = Ledger::recordPayment($customer, ['paid_on' => now()->toDateString(), 'amount' => '225.00', 'method' => 'cash', 'reference_number' => null, 'notes' => null], $this->owner);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        Livewire::actingAs($this->owner)
            ->test(PaymentsPage::class)
            ->call('openPayment', $payment->id)
            ->set('voiding', true)
            ->call('voidPayment')
            ->assertHasErrors(['voidReason'])
            ->set('voidReason', 'Wrong customer')
            ->call('voidPayment')
            ->assertHasNoErrors();

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        $this->assertNotNull($payment->fresh()->voided_at);

        Livewire::actingAs($this->owner)
            ->test(InvoicesPage::class)
            ->call('openInvoice', $invoice->id)
            ->call('askVoid')
            ->set('voidReason', 'Wrong reading')
            ->call('voidInvoice')
            ->assertHasNoErrors();

        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
        $this->assertNull($invoice->meterReading->fresh()->invoice_id);

        // The month can be billed again with the right reading.
        $this->assertSame('100.00', $this->bill($customer, $period, '9')->total_amount);
    }

    public function test_overdue_bills_are_surcharged_on_the_next_bill_once(): void
    {
        $customer = $this->customer();
        $overdue = $this->bill($customer, now()->subMonths(2)->format('Y-m'), '15', invoiceDate: now()->subDays(40)->toDateString());

        $next = $this->bill($customer, now()->subMonth()->format('Y-m'), '25', invoiceDate: now()->toDateString());

        // 100.00 water + 2% of the 225.00 unpaid
        $this->assertSame('104.50', $next->total_amount);
        $this->assertSame('225.00', $next->previous_balance);
        $this->assertSame($overdue->id, $next->lines()->where('kind', InvoiceLineKind::Surcharge)->value('source_invoice_id'));
        $this->assertSame($next->id, $overdue->fresh()->surcharged_on_invoice_id);

        $after = $this->bill($customer, now()->format('Y-m'), '35', invoiceDate: now()->toDateString());
        $this->assertSame(0, $after->lines()->where('kind', InvoiceLineKind::Surcharge)->where('source_invoice_id', $overdue->id)->count());
    }

    public function test_companies_can_surcharge_the_overdue_invoice_itself(): void
    {
        $this->company->update(['overdue_handling' => OverdueHandling::SameInvoice]);
        $customer = $this->customer();
        $overdue = $this->bill($customer, now()->subMonth()->format('Y-m'), '15', invoiceDate: now()->subDays(40)->toDateString());

        Livewire::actingAs($this->owner)
            ->test(InvoicesPage::class)
            ->assertSee('1 overdue invoice has no surcharge yet')
            ->call('applySurcharges')
            ->assertDispatched('flash', message: 'Surcharge added to 1 overdue invoice.');

        $overdue->refresh();
        $this->assertSame('229.50', $overdue->total_amount);
        $this->assertSame('229.50', $overdue->balance);
        $this->assertNotNull($overdue->surcharged_at);

        // The next bill carries no second surcharge.
        $next = $this->bill($customer, now()->format('Y-m'), '25');
        $this->assertSame('100.00', $next->total_amount);

        $this->artisan('invoices:surcharge-overdue')->expectsOutput('Surcharged 0 overdue invoice(s).')->assertSuccessful();
    }

    public function test_billing_settings_and_fee_billing_are_saved(): void
    {
        Livewire::actingAs($this->owner)
            ->test(InvoicesPage::class)
            ->call('openSettings')
            ->assertSet('overdueHandling', OverdueHandling::NextBill->value)
            ->set('overdueHandling', OverdueHandling::SameInvoice->value)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertSame(OverdueHandling::SameInvoice, $this->company->fresh()->overdue_handling);
    }

    public function test_printable_bill_receipt_and_statement(): void
    {
        $customer = $this->customer();
        $invoice = $this->bill($customer, now()->format('Y-m'), '15');
        $payment = Ledger::recordPayment($customer, ['paid_on' => now()->toDateString(), 'amount' => '100.00', 'method' => 'cash', 'reference_number' => null, 'notes' => null], $this->owner);

        $this->actingAs($this->owner)->get(route('invoices.print', $invoice))
            ->assertOk()->assertSee('Statement of Bill')->assertSee($invoice->invoice_number)->assertSee('125.00');
        $this->actingAs($this->owner)->get(route('payments.receipt', $payment))
            ->assertOk()->assertSee('Official Receipt')->assertSee($payment->receipt_number);
        $this->actingAs($this->owner)->get(route('customers.statement', $customer))
            ->assertOk()->assertSee('Statement of Account')->assertSee('Balance due')->assertSee('125.00');

        [$stranger] = $this->ownerWithCompany();
        $this->actingAs($stranger)->get(route('invoices.print', $invoice))->assertNotFound();
        $this->actingAs($stranger)->get(route('customers.statement', $customer))->assertNotFound();
    }

    public function test_users_need_access_to_bill_record_and_void(): void
    {
        $customer = $this->customer();
        $invoice = $this->bill($customer, now()->subMonth()->format('Y-m'), '15');
        $viewer = $this->memberWithAccess($this->company, Page::Invoices, [Access::View]);

        Livewire::actingAs($viewer)
            ->test(GenerateBillsPage::class)
            ->call('generate')
            ->assertDispatched('flash', message: Access::Add->deniedMessage(), tone: 'error');

        Livewire::actingAs($viewer)
            ->test(InvoicesPage::class)
            ->assertDontSee('Generate Bills')
            ->call('openInvoice', $invoice->id)
            ->assertDontSee('Void invoice')
            ->set('voidReason', 'x')
            ->call('voidInvoice')
            ->assertDispatched('flash', message: Access::Delete->deniedMessage(), tone: 'error');

        $this->actingAs($viewer)->get(route('payments'))->assertForbidden();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
    }

    public function test_a_credit_memo_lowers_the_balance_up_to_what_is_unpaid(): void
    {
        $customer = $this->customer();
        $invoice = $this->bill($customer, now()->format('Y-m'), '15');   // 225.00
        Ledger::recordPayment($customer, ['paid_on' => now()->toDateString(), 'amount' => '25.00', 'method' => 'cash', 'reference_number' => null, 'notes' => null], $this->owner);

        $page = Livewire::actingAs($this->owner)
            ->test(InvoicesPage::class)
            ->call('openInvoice', $invoice->id)
            ->call('openAdjust')
            ->assertSet('adjust.type', AdjustmentType::Credit->value)
            ->set('adjust.category', AdjustmentReason::MeterReadingError->value)
            ->set('adjust.reason', 'Reading was entered as 15 instead of 12.')
            ->set('adjust.amount', '200.01')
            ->call('saveAdjustment')
            ->assertHasErrors(['adjust.amount'])
            ->set('adjust.amount', '75')
            ->call('saveAdjustment')
            ->assertHasNoErrors()
            ->assertDispatched('flash', message: 'Credit Memo CM-000001 issued for 75.00 on invoice '.$invoice->invoice_number.'.');

        $invoice->refresh();
        $this->assertSame('150.00', $invoice->total_amount);
        $this->assertSame('125.00', $invoice->balance);
        $this->assertSame('-75.00', $invoice->lines()->where('kind', InvoiceLineKind::Adjustment)->value('amount'));

        $memo = $invoice->adjustments()->sole();
        $this->actingAs($this->owner)->get(route('invoices.memo', $memo))->assertOk()->assertSee('Credit Memo')->assertSee('CM-000001');
        $this->actingAs($this->owner)->get(route('customers.statement', $customer))
            ->assertOk()->assertSee('CM-000001')->assertSee('225.00')->assertSee('125.00');

        // Voiding it adds a reversing line and puts the invoice back.
        $page->call('askVoidMemo', $memo->id)
            ->set('memoVoidReason', 'Reading was right after all')
            ->call('voidMemo')
            ->assertHasNoErrors();

        $invoice->refresh();
        $this->assertSame('225.00', $invoice->total_amount);
        $this->assertSame('200.00', $invoice->balance);
        $this->assertNotNull($memo->fresh()->voided_at);
        $this->assertSame(2, $invoice->lines()->where('kind', InvoiceLineKind::Adjustment)->count());
    }

    public function test_a_debit_memo_adds_to_the_invoice_and_uses_advance_credit(): void
    {
        $customer = $this->customer();
        $invoice = $this->bill($customer, now()->format('Y-m'), '15');
        Ledger::recordPayment($customer, ['paid_on' => now()->toDateString(), 'amount' => '300.00', 'method' => 'cash', 'reference_number' => null, 'notes' => null], $this->owner);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        // Paid invoices can't be adjusted.
        Livewire::actingAs($this->owner)
            ->test(InvoicesPage::class)
            ->call('openInvoice', $invoice->id)
            ->assertDontSee('Adjust (credit / debit memo)');

        $other = $this->bill($customer, now()->addMonth()->format('Y-m'), '25', invoiceDate: now()->toDateString());   // 100.00, 75.00 credit applied
        $this->assertSame('25.00', $other->balance);

        $memo = Adjustments::issue($other, AdjustmentType::Debit, AdjustmentReason::Undercharge, 'Missed 2 cu.m.', 50_00, now()->toDateString(), null, $this->owner);
        $this->assertSame('DM-000001', $memo->label());
        $this->assertSame('150.00', $other->fresh()->total_amount);
        $this->assertSame('75.00', $other->fresh()->balance);

        Ledger::recordPayment($customer, ['paid_on' => now()->toDateString(), 'amount' => '75.00', 'method' => 'cash', 'reference_number' => null, 'notes' => null], $this->owner);
        $this->assertSame(InvoiceStatus::Paid, $other->fresh()->status);

        // Voiding the debit memo of a paid invoice returns the overpayment as advance credit.
        Adjustments::void($memo, 'Charged by mistake', $this->owner);
        $this->assertSame('100.00', $other->fresh()->total_amount);
        $this->assertSame(InvoiceStatus::Paid, $other->fresh()->status);
        $this->assertSame(['owed' => 0, 'credit' => 50_00], Ledger::summary($customer));
    }

    public function test_credit_memos_need_delete_access_and_debit_memos_edit_access(): void
    {
        $customer = $this->customer();
        $invoice = $this->bill($customer, now()->format('Y-m'), '15');
        $editor = $this->memberWithAccess($this->company, Page::Invoices, [Access::View, Access::Edit]);

        Livewire::actingAs($editor)
            ->test(InvoicesPage::class)
            ->call('openInvoice', $invoice->id)
            ->call('openAdjust')
            ->assertSet('adjust.type', AdjustmentType::Debit->value)
            ->set('adjust.type', AdjustmentType::Credit->value)
            ->set('adjust.category', AdjustmentReason::Rebate->value)
            ->set('adjust.reason', 'Goodwill')
            ->set('adjust.amount', '10')
            ->call('saveAdjustment')
            ->assertDispatched('flash', message: Access::Delete->deniedMessage(), tone: 'error')
            ->set('adjust.type', AdjustmentType::Debit->value)
            ->set('adjust.category', AdjustmentReason::AdditionalCharge->value)
            ->call('saveAdjustment')
            ->assertHasNoErrors();

        $this->assertSame(AdjustmentType::Debit, $invoice->adjustments()->sole()->type);
    }

    public function test_a_flagged_work_order_fills_the_credit_memo_and_is_resolved_by_it(): void
    {
        $customer = $this->customer();
        $invoice = $this->bill($customer, now()->format('Y-m'), '15');
        $complaint = WorkOrder::factory()->forCustomer($customer, WorkOrderType::BillingComplaint)->completed()->create([
            'review_flag' => WorkOrderFlag::BillingAdjustment,
            'completion_data' => ['fields' => ['findings' => 'Charged twice for maintenance', 'outcome' => 'adjustment_needed', 'adjustment_amount' => '45.50'], 'checklist' => [], 'remarks' => null],
        ]);

        Livewire::actingAs($this->owner)
            ->test(InvoicesPage::class)
            ->call('openInvoice', $invoice->id)
            ->call('openAdjust')
            ->assertSee("{$complaint->order_number} · Billing Complaint · Billing adjustment needed")
            ->set('adjust.workOrderId', (string) $complaint->id)
            ->assertSet('adjust.amount', '45.50')
            ->assertSet('adjust.category', AdjustmentReason::BillingError->value)
            ->assertSet('adjust.reason', "Work order {$complaint->order_number} (Billing Complaint). Charged twice for maintenance")
            ->call('saveAdjustment')
            ->assertHasNoErrors();

        $complaint->refresh();
        $this->assertNotNull($complaint->flag_resolved_at);
        $this->assertSame('CM-000001 on invoice '.$invoice->invoice_number, $complaint->flag_resolution);
        $this->assertSame([], Adjustments::flaggedWorkOrders($customer)->all());

        // Voiding the invoice voids its memos and opens the flag again.
        Ledger::voidInvoice($invoice, 'Wrong period', $this->owner);
        $this->assertNull($complaint->fresh()->flag_resolved_at);
        $this->assertNotNull($invoice->adjustments()->sole()->voided_at);
    }

    public function test_completing_a_new_installation_bills_its_fee_upfront(): void
    {
        $customer = Customer::factory()->for($this->company)->create(['rate_type_id' => $this->rate->id, 'charge_type_id' => $this->chargeType->id]);
        $order = WorkOrder::factory()->forCustomer($customer, WorkOrderType::NewInstallation)->create(['assigned_to_id' => $this->owner->id]);
        $order->update(['status' => WorkOrderStatus::Completed, 'fee_amount' => '2000.00']);

        $invoice = WorkOrderBilling::onCompleted($order->fresh(), $this->owner, now()->toDateString());

        $this->assertSame('2000.00', $invoice->balance);
        $this->assertStringContainsString('New Installation fee', $invoice->lines()->value('description'));
        $this->assertSame(1, $customer->invoices()->count());
    }
}
