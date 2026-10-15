<?php

namespace Tests\Feature;

use App\Livewire\Rates\RatesPage;
use App\Models\RateRange;
use App\Models\RateType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RatesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_rate_types_are_added_and_must_be_unique_per_company(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        RateType::factory()->create(['name' => 'Commercial']);

        Livewire::actingAs($owner)
            ->test(RatesPage::class)
            ->call('saveRateType')
            ->assertSee('The rate type field is required.')
            ->set('rateTypeName', 'Commercial')
            ->set('rateTypeDescription', 'Shops')
            ->call('saveRateType')
            ->assertHasNoErrors()
            ->assertSet('rateTypeName', '')
            ->assertDispatched('flash', message: 'Rate type added.')
            ->set('rateTypeName', 'Commercial')
            ->call('saveRateType')
            ->assertSee('The rate type has already been taken.');

        $this->assertSame(['Commercial'], $company->rateTypes()->pluck('name')->all());
    }

    public function test_selecting_a_rate_type_loads_it_and_updates_it(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $rateType = RateType::factory()->for($company)->create(['name' => 'Residential', 'description' => 'Homes']);

        Livewire::actingAs($owner)
            ->test(RatesPage::class)
            ->call('select', $rateType->id)
            ->assertSet('rateType', (string) $rateType->id)
            ->assertSet('rateTypeName', 'Residential')
            ->assertSee('Update Rate Type')
            ->set('rateTypeName', 'Residential A')
            ->call('saveRateType')
            ->assertDispatched('flash', message: 'Rate type updated.');

        $this->assertSame('Residential A', $rateType->fresh()->name);
    }

    public function test_another_companys_rate_type_in_the_url_is_ignored(): void
    {
        [$owner] = $this->ownerWithCompany();
        $other = RateType::factory()->create();

        Livewire::actingAs($owner)
            ->withQueryParams(['rateType' => $other->id])
            ->test(RatesPage::class)
            ->assertSet('rateTypeName', '')
            ->assertSee('Add Rate Type');
    }

    public function test_the_rate_setup_is_saved(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $rateType = RateType::factory()->for($company)->create();

        Livewire::actingAs($owner)
            ->withQueryParams(['rateType' => $rateType->id])
            ->test(RatesPage::class)
            ->set('minimumConsumption', '1.5')
            ->call('saveSetup')
            ->assertSee('The minimum consumption must be a whole number.')
            ->set('minimumConsumption', '10')
            ->set('defectiveUsesThreeMonthAverage', true)
            ->call('saveSetup')
            ->assertDispatched('flash', message: "Rate setup saved for {$rateType->name}.");

        $setup = $rateType->setup()->firstOrFail();
        $this->assertSame(10, $setup->minimum_consumption);
        $this->assertTrue($setup->defective_uses_three_month_average);
        $this->assertFalse($setup->defective_uses_minimum_consumption);
    }

    public function test_ranges_are_contiguous_and_only_the_last_can_be_deleted(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $rateType = RateType::factory()->for($company)->create();

        $page = Livewire::actingAs($owner)
            ->withQueryParams(['rateType' => $rateType->id])
            ->test(RatesPage::class)
            ->set('rangeTo', '10')->set('ratePerCount', '12.50')->set('rangeDescription', 'First block')
            ->call('addRange')
            ->assertHasNoErrors()
            ->set('rangeTo', '5')->set('ratePerCount', '15')->set('rangeDescription', 'Second block')
            ->call('addRange')
            ->assertSee('The &quot;To&quot; range must be at least 11.', false)
            ->set('rangeTo', '20')->set('ratePerCount', '15.123')
            ->call('addRange')
            ->assertSee('The rate per count must be a number with up to 2 decimal places.')
            ->set('ratePerCount', '15')
            ->call('addRange')
            ->assertHasNoErrors();

        $ranges = RateRange::query()->where('rate_type_id', $rateType->id)->orderBy('range_from')->get();
        $this->assertSame([[0, 10], [11, 20]], $ranges->map(fn (RateRange $range) => [$range->range_from, $range->range_to])->all());

        $page->call('deleteRange', $ranges->first()->id)
            ->assertSee('Only the last consumption range can be deleted.');
        $page->call('deleteRange', $ranges->last()->id);

        $this->assertSame(1, RateRange::query()->where('rate_type_id', $rateType->id)->count());
    }
}
