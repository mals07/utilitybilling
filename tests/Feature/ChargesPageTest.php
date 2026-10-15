<?php

namespace Tests\Feature;

use App\Enums\ChargeValueType;
use App\Enums\DistributionType;
use App\Livewire\Charges\ChargesPage;
use App\Models\ChargeElement;
use App\Models\ChargeType;
use App\Models\ChargeTypeElement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChargesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_charge_types_are_added_and_updated(): void
    {
        [$owner, $company] = $this->ownerWithCompany();

        $page = Livewire::actingAs($owner)
            ->test(ChargesPage::class)
            ->set('chargeTypeName', 'Residential')
            ->call('saveChargeType')
            ->assertDispatched('flash', message: 'Charge type added.')
            ->assertSee('Residential');

        $chargeType = $company->chargeTypes()->firstOrFail();

        $page->call('selectType', $chargeType->id)
            ->assertSet('chargeTypeName', 'Residential')
            ->set('chargeTypeName', 'Residential Plus')
            ->call('saveChargeType')
            ->assertDispatched('flash', message: 'Charge type updated.');

        $this->assertSame('Residential Plus', $chargeType->fresh()->name);
    }

    public function test_charge_elements_need_a_distribution_type_and_a_unique_name(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        ChargeElement::factory()->for($company)->create(['name' => 'VAT']);

        Livewire::actingAs($owner)
            ->test(ChargesPage::class)
            ->set('elementName', 'Surcharge')
            ->call('addElement')
            ->assertSee('Select a distribution type.')
            ->set('elementDistribution', 'debit')
            ->set('elementAfterDue', true)
            ->call('addElement')
            ->assertHasNoErrors()
            ->assertDispatched('flash', message: 'Charge element added.')
            ->set('elementName', 'VAT')
            ->set('elementDistribution', 'credit')
            ->call('addElement')
            ->assertSee('The charge element has already been taken.');

        $surcharge = $company->chargeElements()->where('name', 'Surcharge')->firstOrFail();
        $this->assertSame(DistributionType::Debit, $surcharge->distribution_type);
        $this->assertTrue($surcharge->is_after_due);
    }

    public function test_charge_elements_are_updated_from_the_modal(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $element = ChargeElement::factory()->for($company)->create(['name' => 'Penalty']);

        Livewire::actingAs($owner)
            ->test(ChargesPage::class)
            ->call('editElement', $element->id)
            ->assertSee('Update Charge Element')
            ->set('editName', 'Late Penalty')
            ->set('editDistribution', 'credit')
            ->call('updateElement')
            ->assertSet('editingElementId', null)
            ->assertDispatched('flash', message: 'Charge element updated.');

        $this->assertSame('Late Penalty', $element->fresh()->name);
        $this->assertSame(DistributionType::Credit, $element->fresh()->distribution_type);
    }

    public function test_the_setup_modal_adds_edits_and_removes_charge_elements(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $chargeType = ChargeType::factory()->for($company)->create(['name' => 'Residential']);
        $element = ChargeElement::factory()->for($company)->create(['name' => 'VAT']);

        $page = Livewire::actingAs($owner)
            ->test(ChargesPage::class)
            ->call('openSetup', $chargeType->id)
            ->assertSee('Charge Type Setup')
            ->call('addSetupElement')
            ->assertSee('Select a charge element.')
            ->set('setupElementId', $element->id)
            ->set('setupValueType', 'percentage')
            ->set('setupValue', '120')
            ->call('addSetupElement')
            ->assertSee('The value must not be greater than 100.')
            ->set('setupValue', '12')
            ->call('addSetupElement')
            ->assertHasNoErrors()
            ->assertSee('12.00%')
            ->set('setupElementId', $element->id)
            ->set('setupValue', '5')
            ->call('addSetupElement')
            ->assertSee('This charge element is already set up for Residential.');

        $row = ChargeTypeElement::query()->firstOrFail();
        $this->assertSame(ChargeValueType::Percentage, $row->value_type);

        $page->call('startRowEdit', $row->id)
            ->set('rowValueType', 'fixed')
            ->set('rowValue', '150')
            ->call('saveRowEdit')
            ->assertSet('editingRowId', null)
            ->assertSee('150.00');
        $this->assertSame('150.00', $row->fresh()->value);

        $page->call('deleteSetupRow', $row->id);
        $this->assertSame(0, ChargeTypeElement::count());

        $page->call('closeSetup')->assertSet('setup', '')->assertDontSee('Charge Type Setup');
    }
}
