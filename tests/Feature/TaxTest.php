<?php

namespace Tests\Feature;

use App\Enums\ChargeValueType;
use App\Enums\DistributionType;
use App\Enums\InvoiceLineKind;
use App\Enums\ReadingKind;
use App\Enums\WorkOrderType;
use App\Livewire\Charges\ChargesPage;
use App\Livewire\Company\LookupTable;
use App\Livewire\Company\OperationSettingsForm;
use App\Models\AccountType;
use App\Models\ChargeElement;
use App\Models\ChargeType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MeterType;
use App\Models\RateType;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderReading;
use App\Support\Billing\BillingRun;
use App\Support\Billing\WorkOrderBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaxTest extends TestCase
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
        $this->company->update(['tax_enabled' => true, 'tax_name' => 'VAT', 'tax_rate' => '12.00']);

        // 0–10 @ 10.00, 11+ @ 25.00, minimum 10 cu.m.
        $this->rate = RateType::factory()->for($this->company)->create();
        $this->rate->ranges()->createMany([
            ['range_from' => 0, 'range_to' => 10, 'rate' => '10.00', 'description' => '0-10 cu.m.'],
            ['range_from' => 11, 'range_to' => 99999, 'rate' => '25.00', 'description' => 'Above 10 cu.m.'],
        ]);
        $this->rate->setup()->create(['minimum_consumption' => 10]);

        // A taxable 10% environmental fee and a 50.00 maintenance fee that isn't taxed.
        $this->chargeType = ChargeType::factory()->for($this->company)->create();
        foreach ([['Environmental fee', ChargeValueType::Percentage, '10.00', true], ['Maintenance fee', ChargeValueType::Fixed, '50.00', false]] as [$name, $type, $value, $taxable]) {
            $element = ChargeElement::factory()->for($this->company)->create([
                'name' => $name, 'distribution_type' => DistributionType::Credit, 'is_taxable' => $taxable,
            ]);
            $this->chargeType->elements()->create(['charge_element_id' => $element->id, 'value_type' => $type, 'value' => $value]);
        }
    }

    private function customer(array $attributes = []): Customer
    {
        $customer = Customer::factory()->for($this->company)->connected()->create([
            'rate_type_id' => $this->rate->id,
            'charge_type_id' => $this->chargeType->id,
            ...$attributes,
        ]);

        $installation = WorkOrder::factory()->forCustomer($customer, WorkOrderType::NewInstallation)->completed()->create();
        WorkOrderReading::query()->create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id, 'work_order_id' => $installation->id,
            'meter_number' => $customer->meter_number, 'kind' => ReadingKind::Initial, 'reading' => '0', 'read_on' => now()->subMonth()->toDateString(),
        ]);

        return $customer;
    }

    /**
     * 18 cu.m.: water 10 × 10.00 + 8 × 25.00 = 300.00, environmental fee 30.00, maintenance fee 50.00.
     */
    private function billEighteen(Customer $customer): Invoice
    {
        $today = now()->toDateString();

        return BillingRun::generate($customer, now()->format('Y-m'), '18', false, $today, $today, $this->owner);
    }

    public function test_tax_is_added_on_water_and_taxable_charges(): void
    {
        $invoice = $this->billEighteen($this->customer());

        // 12% of (300.00 + 30.00); the maintenance fee isn't taxable.
        $this->assertSame('39.60', $invoice->lines()->where('kind', InvoiceLineKind::Tax)->sole()->amount);
        $this->assertSame('VAT (12.00%)', $invoice->lines()->where('kind', InvoiceLineKind::Tax)->value('description'));
        $this->assertSame('419.60', $invoice->total_amount);
        $this->assertSame('330.00', $invoice->taxable_amount);
        $this->assertSame('39.60', $invoice->tax_amount);

        $this->actingAs($this->owner)->get(route('invoices.print', $invoice))
            ->assertOk()
            ->assertSee('VATable amount')
            ->assertSee('330.00')
            ->assertSee('39.60');
    }

    public function test_tax_exempt_account_types_are_billed_without_tax(): void
    {
        $government = AccountType::factory()->for($this->company)->create(['name' => 'Government', 'is_tax_exempt' => true]);

        $invoice = $this->billEighteen($this->customer(['account_type_id' => $government->id]));

        $this->assertSame(0, $invoice->lines()->where('kind', InvoiceLineKind::Tax)->count());
        $this->assertSame('380.00', $invoice->total_amount);
        $this->assertSame('330.00', $invoice->tax_exempt_amount);
        $this->assertSame('0.00', $invoice->tax_amount);
    }

    public function test_inclusive_tax_is_shown_but_not_added(): void
    {
        $this->company->update(['tax_inclusive' => true]);

        $invoice = $this->billEighteen($this->customer());

        // 330.00 × 12 / 112
        $this->assertSame('380.00', $invoice->total_amount);
        $this->assertSame('35.36', $invoice->tax_amount);
        $this->assertSame('294.64', $invoice->taxable_amount);
        $this->assertSame(0, $invoice->lines()->where('kind', InvoiceLineKind::Tax)->count());
    }

    public function test_what_is_taxed_follows_the_settings(): void
    {
        $this->company->update(['tax_on_water' => false, 'tax_on_fees' => true]);
        $customer = $this->customer();

        // Only the environmental fee: 12% of 30.00.
        $this->assertSame('3.60', $this->billEighteen($customer)->tax_amount);

        $order = WorkOrder::factory()->forCustomer($customer, WorkOrderType::Reconnection)->completed()->create(['fee_amount' => '1000.00']);
        $feeInvoice = WorkOrderBilling::onCompleted($order, $this->owner, now()->toDateString());
        $this->assertSame('1120.00', $feeInvoice->total_amount);

        $this->company->update(['tax_enabled' => false]);
        $other = WorkOrder::factory()->forCustomer($customer, WorkOrderType::Reconnection)->completed()->create(['fee_amount' => '1000.00']);
        $this->assertSame('1000.00', WorkOrderBilling::onCompleted($other, $this->owner, now()->toDateString())->total_amount);
    }

    public function test_tax_and_review_settings_are_saved_and_validated(): void
    {
        Livewire::actingAs($this->owner)
            ->test(OperationSettingsForm::class)
            ->set('dueDateDays', '15')
            ->set('currency', 'PHP')
            ->set('taxRate', '150')
            ->set('reviewUsageMultiplier', 'abc')
            ->call('save')
            ->assertHasErrors(['taxRate', 'reviewUsageMultiplier'])
            ->set('taxRate', '12.5')
            ->set('taxMode', 'inclusive')
            ->set('taxOnSurcharges', true)
            ->set('reviewUsageMultiplier', '3')
            ->set('reviewZeroUsage', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->company->refresh();
        $this->assertSame('12.50', $this->company->tax_rate);
        $this->assertTrue($this->company->tax_inclusive);
        $this->assertTrue($this->company->tax_on_surcharges);
        $this->assertSame('3.0', $this->company->review_usage_multiplier);
        $this->assertFalse($this->company->review_zero_usage);
    }

    public function test_account_types_can_be_tax_exempt_and_meter_types_have_dials(): void
    {
        $accountType = AccountType::factory()->for($this->company)->create(['name' => 'Government']);
        Livewire::actingAs($this->owner)
            ->test(LookupTable::class, ['type' => 'account-types'])
            ->assertSee('Tax exempt')
            ->call('toggleTaxExempt', $accountType->id);
        $this->assertTrue($accountType->fresh()->is_tax_exempt);

        $meterType = MeterType::factory()->for($this->company)->create();
        Livewire::actingAs($this->owner)
            ->test(LookupTable::class, ['type' => 'meter-types'])
            ->call('setDialDigits', $meterType->id, '20')
            ->assertSet('rowError', 'The number of dials must be a whole number from 3 to 9.')
            ->call('setDialDigits', $meterType->id, '4');
        $this->assertSame(4, $meterType->fresh()->dial_digits);
        $this->assertSame(9999, $meterType->fresh()->highestReading());
    }

    public function test_charge_elements_can_be_marked_taxable(): void
    {
        Livewire::actingAs($this->owner)
            ->test(ChargesPage::class)
            ->set('elementName', 'Watershed fee')
            ->set('elementDistribution', 'credit')
            ->set('elementTaxable', true)
            ->call('addElement')
            ->assertHasNoErrors();

        $this->assertTrue($this->company->chargeElements()->where('name', 'Watershed fee')->sole()->is_taxable);
    }
}
