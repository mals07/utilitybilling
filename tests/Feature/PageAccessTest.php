<?php

namespace Tests\Feature;

use App\Enums\Access;
use App\Enums\Page;
use App\Livewire\Rates\RatesPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PageAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_see_every_page_in_the_sidebar(): void
    {
        [$owner] = $this->ownerWithCompany();

        $response = $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        foreach (Page::cases() as $page) {
            $response->assertSee($page->label());
        }

        $response->assertSeeInOrder([
            'Customer Management', 'Customers',
            'Readings &amp; Payments', 'Meter Readings', 'Invoices', 'Payments',
            'Field Operations', 'Work Orders', 'Disconnections',
            'Reports',
            'System Administration', 'Company Setup', 'Users', 'Rates', 'Charges', 'Roles',
        ], escape: false);
    }

    public function test_only_the_sidebar_group_holding_the_current_page_starts_open(): void
    {
        [$owner] = $this->ownerWithCompany();

        $html = $this->actingAs($owner)->get(route('customers'))->getContent();

        $this->assertMatchesRegularExpression('/id="sidebar-group-customer-management" x-show="open"\s+class/', $html);
        $this->assertMatchesRegularExpression('/id="sidebar-group-field-operations" x-show="open"\s+x-cloak/', $html);
        $this->assertMatchesRegularExpression('/id="sidebar-group-system-administration" x-show="open"\s+x-cloak/', $html);
    }

    public function test_the_sidebar_group_last_left_open_reopens_outside_any_group(): void
    {
        [$owner] = $this->ownerWithCompany();
        $this->withUnencryptedCookie('ub_sidebar_group', 'field-operations');

        $dashboard = $this->actingAs($owner)->get(route('dashboard'))->getContent();

        $this->assertMatchesRegularExpression('/id="sidebar-group-field-operations" x-show="open"\s+class/', $dashboard);
        $this->assertMatchesRegularExpression('/id="sidebar-group-customer-management" x-show="open"\s+x-cloak/', $dashboard);

        $customers = $this->actingAs($owner)->get(route('customers'))->getContent();

        $this->assertMatchesRegularExpression('/id="sidebar-group-customer-management" x-show="open"\s+class/', $customers);
        $this->assertMatchesRegularExpression('/id="sidebar-group-field-operations" x-show="open"\s+x-cloak/', $customers);
    }

    public function test_sidebar_headings_without_a_visible_page_are_hidden(): void
    {
        [, $company] = $this->ownerWithCompany();
        $member = $this->memberWithAccess($company, Page::Rates, [Access::View]);

        $this->actingAs($member)->get(route('dashboard'))
            ->assertSee('System Administration')
            ->assertDontSee('Customer Management')
            ->assertDontSee('Field Operations');
    }

    public function test_users_without_a_company_are_sent_to_the_setup_wizard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('rates'))->assertRedirect(route('setup'));
        $this->actingAs($user)->get(route('company'))->assertRedirect(route('setup'));
    }

    public function test_pages_outside_the_role_show_access_denied(): void
    {
        [, $company] = $this->ownerWithCompany();
        $member = $this->memberWithAccess($company, Page::Rates, [Access::View]);

        $this->actingAs($member)->get(route('rates'))->assertOk()->assertDontSee('Access denied');
        $this->actingAs($member)->get(route('charges'))->assertForbidden()->assertSee('403 · Access denied');

        $sidebar = $this->actingAs($member)->get(route('dashboard'));
        $sidebar->assertSee('Rates')->assertDontSee('Charges')->assertDontSee('Customers');
    }

    public function test_actions_check_access_on_the_server(): void
    {
        [, $company] = $this->ownerWithCompany();
        $viewer = $this->memberWithAccess($company, Page::Rates, [Access::View]);

        Livewire::actingAs($viewer)
            ->test(RatesPage::class)
            ->assertDontSee('Add Rate Type')
            ->set('rateTypeName', 'Residential')
            ->call('saveRateType')
            ->assertDispatched('flash', message: "You don't have permission to add on this page.", tone: 'error');

        $this->assertSame(0, $company->rateTypes()->count());
    }

    public function test_special_access_grants_a_page_only_while_it_runs(): void
    {
        [, $company] = $this->ownerWithCompany();
        $member = $this->memberWithAccess($company, Page::Rates, [Access::View]);
        $member->specialAccess()->create([
            'page' => Page::Charges->value,
            'can_view' => true,
            'start_date' => now('Asia/Manila')->toDateString(),
            'end_date' => now('Asia/Manila')->addDay()->toDateString(),
        ]);

        $this->assertTrue($member->fresh()->canAccessPage(Page::Charges));
        $this->assertFalse($member->fresh()->canAccessPage(Page::Charges, Access::Edit));

        $this->travel(3)->days();
        $this->assertFalse($member->fresh()->canAccessPage(Page::Charges));
    }
}
