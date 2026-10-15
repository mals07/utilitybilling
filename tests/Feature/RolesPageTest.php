<?php

namespace Tests\Feature;

use App\Enums\Page;
use App\Livewire\Roles\RolesPage;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RolesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_are_added_and_must_be_unique(): void
    {
        [$owner, $company] = $this->ownerWithCompany();

        Livewire::actingAs($owner)
            ->test(RolesPage::class)
            ->set('name', 'Cashier')
            ->call('addRole')
            ->assertDispatched('flash', message: 'Role added.')
            ->set('name', 'Cashier')
            ->call('addRole')
            ->assertSee('The role name has already been taken.');

        $this->assertSame(1, $company->roles()->count());
    }

    public function test_any_access_implies_view_and_unchecking_view_clears_the_rest(): void
    {
        $this->assertSame(
            ['add' => false, 'edit' => true, 'view' => true, 'delete' => false],
            RolesPage::toggleAccess(RolesPage::NO_ACCESS, 'edit', true),
        );
        $this->assertSame(RolesPage::NO_ACCESS, RolesPage::toggleAccess(['add' => true, 'edit' => true, 'view' => true, 'delete' => true], 'view', false));
    }

    public function test_form_access_is_added_edited_and_removed(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $role = Role::factory()->for($company)->create(['name' => 'Cashier']);

        $page = Livewire::actingAs($owner)
            ->withQueryParams(['role' => $role->id])
            ->test(RolesPage::class)
            ->call('saveFormAccess')
            ->assertSee('Select a page or form.')
            ->assertSee('Check at least one access.')
            ->set('accessPage', Page::Rates->value)
            ->set('flags.add', true)
            ->call('saveFormAccess')
            ->assertDispatched('flash', message: 'Form access added.');

        $permission = RolePermission::query()->where('role_id', $role->id)->firstOrFail();
        $this->assertTrue($permission->can_add);
        $this->assertTrue($permission->can_view);

        $page->set('accessPage', Page::Rates->value)
            ->set('flags.view', true)
            ->call('saveFormAccess')
            ->assertSee('This page already has access for Cashier. Use the pencil icon to change it.');

        $page->call('startEdit', $permission->id)
            ->assertSet('accessPage', Page::Rates->value)
            ->set('flags.delete', true)
            ->call('saveFormAccess')
            ->assertDispatched('flash', message: 'Form access updated.');
        $this->assertTrue($permission->fresh()->can_delete);

        $page->call('deleteFormAccess', $permission->id);
        $this->assertModelMissing($permission);
    }

    public function test_deleting_a_role_leaves_its_users_without_a_role(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $role = Role::factory()->for($company)->create();
        $member = User::factory()->memberOf($company, $role)->create();

        Livewire::actingAs($owner)
            ->test(RolesPage::class)
            ->call('deleteRole', $role->id)
            ->assertDispatched('flash', message: 'Role deleted.');

        $this->assertModelMissing($role);
        $this->assertNull($member->fresh()->role_id);
    }
}
