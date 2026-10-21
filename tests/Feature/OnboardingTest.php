<?php

namespace Tests\Feature;

use App\Enums\Access;
use App\Enums\Page;
use App\Enums\WorkOrderType;
use App\Livewire\Onboarding\GettingStarted;
use App\Livewire\Onboarding\Tour;
use App\Models\Customer;
use App\Models\RateType;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Onboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array{key: string, done: bool}>  $steps
     * @return array<string, bool>
     */
    private function doneByKey(array $steps): array
    {
        return array_column($steps, 'done', 'key');
    }

    public function test_dashboard_shows_the_getting_started_checklist_to_a_new_admin(): void
    {
        [$owner] = $this->ownerWithCompany();

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertSee('Getting started')
            ->assertSee('1 of 11 done')
            ->assertSee('Take the tour');
    }

    public function test_checklist_ticks_off_only_the_steps_the_company_has_done(): void
    {
        [, $company] = $this->ownerWithCompany();
        RateType::factory()->for($company)->create();
        $company->update(['currency' => 'PHP']);

        $done = $this->doneByKey(Onboarding::checklist($company->fresh()));

        $this->assertSame([
            'profile' => true,
            'operation' => true,
            'lookups' => false,
            'rates' => true,
            'charges' => false,
            'team' => false,
            'customer' => false,
            'installation' => false,
            'reading' => false,
            'bills' => false,
            'payment' => false,
        ], $done);
    }

    public function test_meter_installation_step_needs_a_completed_new_installation_order(): void
    {
        [, $company] = $this->ownerWithCompany();
        $customer = Customer::factory()->for($company)->create();
        WorkOrder::factory()->forCustomer($customer, WorkOrderType::NewInstallation)->create();
        WorkOrder::factory()->forCustomer($customer, WorkOrderType::PipeRepair)->completed()->create();

        $beforeInstalling = $this->doneByKey(Onboarding::checklist($company))['installation'];
        WorkOrder::factory()->forCustomer($customer, WorkOrderType::NewInstallation)->completed()->create();
        $afterInstalling = $this->doneByKey(Onboarding::checklist($company))['installation'];

        $this->assertFalse($beforeInstalling);
        $this->assertTrue($afterInstalling);
    }

    public function test_hiding_the_checklist_keeps_it_hidden(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 09:00'));
        [$owner] = $this->ownerWithCompany();

        Livewire::actingAs($owner)->test(GettingStarted::class)
            ->assertSee('Getting started')
            ->call('dismiss')
            ->assertDontSee('Getting started');

        $this->assertSame('2026-10-21 09:00:00', $owner->fresh()->getting_started_dismissed_at->toDateTimeString());
        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertDontSee('Getting started');
    }

    public function test_staff_do_not_see_the_getting_started_checklist(): void
    {
        [, $company] = $this->ownerWithCompany();
        $member = $this->memberWithAccess($company, Page::Customers, [Access::View]);

        $this->actingAs($member)->get(route('dashboard'))
            ->assertDontSee('Getting started')
            ->assertSee('Take the tour');
    }

    public function test_tour_for_staff_covers_only_the_pages_their_role_can_open(): void
    {
        [, $company] = $this->ownerWithCompany();
        $member = $this->memberWithAccess($company, Page::Customers, [Access::View]);

        $stops = Onboarding::tour($member);

        $this->assertSame(['dashboard', 'customers', 'finish'], array_column($stops, 'key'));
    }

    public function test_tour_for_an_admin_covers_every_page(): void
    {
        [$owner] = $this->ownerWithCompany();

        $keys = array_column(Onboarding::tour($owner), 'key');

        $this->assertSame([
            'dashboard', 'customers', 'work-orders', 'meter-readings', 'invoices', 'payments', 'disconnections',
            'company', 'rates', 'charges', 'users', 'roles', 'finish',
        ], $keys);
    }

    public function test_tour_starts_on_its_own_until_the_user_finishes_it(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 09:00'));
        [$owner] = $this->ownerWithCompany();

        Livewire::actingAs($owner)->test(Tour::class)
            ->assertViewHas('autoStart', true)
            ->call('complete')
            ->assertViewHas('autoStart', false);

        $this->assertSame('2026-10-21 09:00:00', $owner->fresh()->tour_completed_at->toDateTimeString());
    }

    public function test_retaking_the_tour_keeps_the_first_completion_time(): void
    {
        [$owner] = $this->ownerWithCompany();
        $owner->update(['tour_completed_at' => Carbon::parse('2026-10-01 08:00')]);

        Livewire::actingAs($owner)->test(Tour::class)->call('complete');

        $this->assertSame('2026-10-01 08:00:00', $owner->fresh()->tour_completed_at->toDateTimeString());
    }

    public function test_tour_is_not_shown_before_the_company_is_created(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('company'))->assertDontSee('Take the tour');
    }
}
