<?php

namespace Tests\Feature;

use App\Enums\Access;
use App\Enums\AuthTokenType;
use App\Enums\CustomerStatus;
use App\Enums\Page;
use App\Enums\WorkOrderType;
use App\Livewire\Company\OperationSettingsForm;
use App\Livewire\Customers\CustomersPage;
use App\Livewire\Onboarding\Tour;
use App\Livewire\Onboarding\Welcome;
use App\Livewire\Setup\SetupWizard;
use App\Livewire\WorkOrders\WorkOrdersPage;
use App\Models\AccountType;
use App\Models\ClusterType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\MeterType;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\AuthTokens;
use App\Support\Billing\Ledger;
use App\Support\Guides;
use App\Support\Onboarding;
use App\Support\SetupExamples;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A company still in the setup wizard, at the given step, and its owner.
     *
     * @return array{0: User, 1: Company}
     */
    private function ownerInSetup(string $step = 'billing'): array
    {
        $company = Company::factory()->inSetup($step)->create();

        return [$company->user->fresh(), $company];
    }

    /**
     * Lists, a rate and charges, as the required steps leave them.
     */
    private function finishRequiredSteps(Company $company): void
    {
        $company->update(['currency' => 'PHP']);
        SetupExamples::lists($company);
        SetupExamples::rates($company);
        SetupExamples::charges($company);
    }

    public function test_new_user_without_a_company_is_sent_to_the_setup_wizard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('setup'));
        $this->actingAs($user)->get(route('setup'))
            ->assertSee('Your company profile')
            ->assertSee('Step 1 of 10');
    }

    public function test_new_sign_up_sees_the_welcome_page_before_the_setup_wizard(): void
    {
        $user = User::factory()->notWelcomed()->create();

        $this->assertSame('welcome', $user->homeRoute());
        $this->actingAs($user)->get(route('setup'))->assertRedirect(route('welcome'));
        $this->actingAs($user)->get(route('welcome'))
            ->assertOk()
            ->assertSee('Welcome! Thank you for signing up to Water Utility Management System')
            ->assertSee('Before we start, I will walk you through the steps on how to configure your system.')
            ->assertSee('Lookup lists')
            ->assertSee('Post the fee payment');
    }

    public function test_verifying_the_email_continues_to_the_welcome_page(): void
    {
        $user = User::factory()->unverified()->notWelcomed()->create();
        $token = AuthTokens::issue($user, AuthTokenType::EmailVerification);

        $this->actingAs($user)
            ->get(route('auth.verify', ['token' => $token]))
            ->assertRedirect(route('welcome', ['verified' => 1]));
    }

    public function test_starting_setup_from_the_welcome_page_shows_it_only_once(): void
    {
        $user = User::factory()->notWelcomed()->create();

        Livewire::actingAs($user)->test(Welcome::class)
            ->call('start')
            ->assertRedirect(route('setup'));

        $this->assertNotNull($user->fresh()->welcomed_at);
        $this->actingAs($user->fresh())->get(route('welcome'))->assertRedirect(route('setup'));
    }

    public function test_invited_staff_never_see_the_welcome_page(): void
    {
        $company = Company::factory()->create();
        $member = User::factory()->memberOf($company)->notWelcomed()->create();

        $this->assertFalse($member->needsWelcome());
        $this->actingAs($member)->get(route('welcome'))->assertRedirect(route('dashboard'));
    }

    public function test_app_pages_stay_locked_until_setup_is_finished(): void
    {
        [$owner] = $this->ownerInSetup('rates');

        $this->actingAs($owner)->get(route('customers'))->assertRedirect(route('setup'));
        $this->actingAs($owner)->get(route('company'))->assertRedirect(route('setup'));
        $this->assertSame('setup', $owner->homeRoute());
    }

    public function test_signing_in_again_resumes_at_the_saved_step(): void
    {
        [$owner] = $this->ownerInSetup('rates');

        $this->actingAs($owner)->get(route('setup'))
            ->assertSee('Step 4 of 10')
            ->assertSee('Water rates');
    }

    public function test_continue_is_refused_until_the_step_is_done(): void
    {
        [$owner, $company] = $this->ownerInSetup('billing');

        Livewire::actingAs($owner)->test(SetupWizard::class)
            ->call('continue')
            ->assertSet('step', 'billing')
            ->assertSee('Save your billing settings first');

        $this->assertSame('billing', $company->fresh()->setup_step);
    }

    public function test_continue_moves_to_the_next_step_and_saves_it(): void
    {
        [$owner, $company] = $this->ownerInSetup('billing');
        $company->update(['currency' => 'PHP']);

        Livewire::actingAs($owner)->test(SetupWizard::class)
            ->call('continue')
            ->assertSet('step', 'lists');

        $this->assertSame('lists', $company->fresh()->setup_step);
    }

    public function test_right_after_the_profile_is_saved_the_wizard_opens_billing_settings(): void
    {
        [$owner] = $this->ownerInSetup('billing');
        $owner->company->update(['setup_step' => null]);

        Livewire::actingAs($owner)->test(SetupWizard::class)->assertSet('step', 'billing');
    }

    public function test_required_steps_cannot_be_skipped(): void
    {
        [$owner, $company] = $this->ownerInSetup('rates');

        Livewire::actingAs($owner)->test(SetupWizard::class)
            ->call('skip')
            ->assertSet('step', 'rates');

        $this->assertNull($company->fresh()->setup_completed_at);
    }

    public function test_skipping_the_team_step_moves_on_to_the_first_customer(): void
    {
        [$owner] = $this->ownerInSetup('team');

        Livewire::actingAs($owner)->test(SetupWizard::class)
            ->call('skip')
            ->assertSet('step', 'customer');
    }

    public function test_skipping_a_later_step_finishes_setup_and_opens_the_app(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 09:00'));
        [$owner, $company] = $this->ownerInSetup('customer');

        Livewire::actingAs($owner)->test(SetupWizard::class)
            ->call('skip')
            ->assertRedirect(route('dashboard'));

        $company->refresh();
        $this->assertSame('2026-10-21 09:00:00', $company->setup_completed_at->toDateTimeString());
        $this->assertNull($company->setup_step);
        $this->actingAs($owner->fresh())->get(route('customers'))->assertOk();
    }

    public function test_finishing_the_last_step_completes_setup(): void
    {
        [$owner, $company] = $this->ownerInSetup('payment');
        $customer = Customer::factory()->for($company)->connected()->create();
        Ledger::recordPayment($customer, ['paid_on' => '2026-10-21', 'amount' => '1500.00', 'method' => 'cash', 'reference_number' => null, 'notes' => null], $owner);

        Livewire::actingAs($owner)->test(SetupWizard::class)
            ->call('continue')
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($company->fresh()->isSetUp());
    }

    public function test_only_earlier_steps_can_be_opened_from_the_step_list(): void
    {
        [$owner] = $this->ownerInSetup('rates');

        Livewire::actingAs($owner)->test(SetupWizard::class)
            ->call('goTo', 'customer')
            ->assertSet('step', 'rates')
            ->call('goTo', 'billing')
            ->assertSet('step', 'billing');
    }

    public function test_examples_complete_the_lists_rates_charges_and_team_steps(): void
    {
        [$owner, $company] = $this->ownerInSetup('lists');
        $wizard = Livewire::actingAs($owner)->test(SetupWizard::class);

        foreach (['lists', 'rates', 'charges', 'team'] as $step) {
            $wizard->set('step', $step)->call('useExample')->call('useExample');
            $this->assertTrue(Onboarding::isDone($company, $step), "{$step} is not done");
        }

        $this->assertSame(['Commercial', 'Government', 'Residential'], $company->accountTypes()->orderBy('name')->pluck('name')->all());
        $this->assertSame(['10.00', '25.00', '35.00'], $company->rateTypes()->sole()->ranges()->orderBy('range_from')->pluck('rate')->all());
        $this->assertSame('1500.00', $company->workOrderFees()->where('order_type', WorkOrderType::NewInstallation)->value('amount'));
        $this->assertSame(['Cashier', 'Meter Reader'], $company->roles()->orderBy('name')->pluck('name')->all());
    }

    public function test_billing_example_fills_settings_that_save(): void
    {
        [$owner, $company] = $this->ownerInSetup('billing');

        Livewire::actingAs($owner)->test(OperationSettingsForm::class)
            ->call('useSetupExample')
            ->call('save')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertSame('PHP', $company->currency);
        $this->assertSame(15, $company->due_date_days);
    }

    public function test_customer_example_fills_a_pending_customer_that_saves(): void
    {
        [$owner, $company] = $this->ownerInSetup('customer');
        $this->finishRequiredSteps($company);

        Livewire::actingAs($owner)->test(CustomersPage::class)
            ->call('useSetupExample')
            ->assertSet('adding', true)
            ->call('save')
            ->assertHasNoErrors();

        $customer = $company->customers()->sole();
        $this->assertSame('Maria Santos', "{$customer->first_name} {$customer->last_name}");
        $this->assertSame(CustomerStatus::Pending, $customer->status);
        $this->assertSame('Residential', $customer->accountType->name);
    }

    public function test_work_order_example_submits_a_new_installation_for_the_pending_customer(): void
    {
        [$owner, $company] = $this->ownerInSetup('work-order');
        $customer = Customer::factory()->for($company)->create([
            'account_type_id' => AccountType::factory()->for($company),
            'cluster_type_id' => ClusterType::factory()->for($company),
        ]);
        MeterType::factory()->for($company)->create();

        Livewire::actingAs($owner)->test(WorkOrdersPage::class)
            ->call('useSetupExample', 'work-order')
            ->call('submit')
            ->assertHasNoErrors();

        $order = WorkOrder::query()->sole();
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame(WorkOrderType::NewInstallation, $order->order_type);
        $this->assertSame($owner->id, $order->assigned_to_id);
    }

    public function test_staff_wait_while_their_administrator_finishes_setup(): void
    {
        [, $company] = $this->ownerInSetup('rates');
        $member = $this->memberWithAccess($company, Page::Customers, [Access::View]);

        $this->actingAs($member)->get(route('customers'))->assertRedirect(route('setup'));
        Livewire::actingAs($member)->test(SetupWizard::class)
            ->assertSee('Your administrator is finishing setup')
            ->call('skip')
            ->call('continue');

        $this->assertSame('rates', $company->fresh()->setup_step);
    }

    public function test_finished_companies_are_sent_from_the_wizard_to_the_dashboard(): void
    {
        [$owner] = $this->ownerWithCompany();

        $this->actingAs($owner)->get(route('setup'))->assertRedirect(route('dashboard'));
    }

    public function test_every_wizard_step_has_a_step_by_step_guide(): void
    {
        $guides = Guides::all();

        foreach (Onboarding::STEPS as $step) {
            $this->assertArrayHasKey(Onboarding::guideFor($step), $guides, "{$step} has no guide");
        }
    }

    public function test_a_guide_is_served_by_name_and_unknown_guides_are_not_found(): void
    {
        [$owner] = $this->ownerWithCompany();

        $this->actingAs($owner)->getJson(route('guides.show', 'rates'))
            ->assertOk()
            ->assertJsonPath('title', 'Water rates')
            ->assertJsonPath('steps.1.until', '#minimum-consumption:not([disabled])');
        $this->actingAs($owner)->getJson(route('guides.show', 'nope'))->assertNotFound();
    }

    public function test_restart_onboarding_signs_in_as_a_new_account_at_step_one_when_local(): void
    {
        [$owner] = $this->ownerWithCompany();
        $this->app['env'] = 'local';

        // Outside the testing environment the CSRF check runs, so send a token like the form does.
        $this->actingAs($owner)
            ->withSession(['_token' => 'test-token'])
            ->post(route('dev.restart-onboarding'), ['_token' => 'test-token'])
            ->assertRedirect(route('setup'));

        $tester = User::query()->where('email', 'like', 'onboarding-%@onboarding.test')->sole();
        $this->assertAuthenticatedAs($tester);
        $this->assertFalse($tester->hasCompany());
        $this->assertTrue($tester->is_admin);
        $this->assertTrue($tester->hasVerifiedEmail());
    }

    public function test_restart_onboarding_is_not_available_outside_local(): void
    {
        [$owner] = $this->ownerWithCompany();

        $this->actingAs($owner)->post(route('dev.restart-onboarding'))->assertNotFound();

        $this->assertSame(1, User::query()->count());
        $this->actingAs($owner)->get(route('dashboard'))->assertDontSee('Restart onboarding (test)');
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
}
