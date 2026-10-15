<?php

namespace Tests\Feature;

use App\Enums\AuthTokenType;
use App\Enums\Page;
use App\Livewire\Users\UsersPage;
use App\Models\AuthToken;
use App\Models\Department;
use App\Models\EmailOutbox;
use App\Models\Role;
use App\Models\User;
use App\Support\SpecialAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UsersPageTest extends TestCase
{
    use RefreshDatabase;

    private function today(): string
    {
        return now('Asia/Manila')->toDateString();
    }

    public function test_inviting_a_user_saves_them_and_emails_an_invitation(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $role = Role::factory()->for($company)->create();
        $role->permissions()->create(['page' => Page::Rates->value, 'can_view' => true]);
        $department = Department::factory()->for($company)->create();

        $page = Livewire::actingAs($owner)
            ->test(UsersPage::class)
            ->call('openAdd')
            ->assertSee('Add User Account')
            ->set('firstName', 'Lito')
            ->set('lastName', 'Reyes')
            ->set('email', 'Lito@Example.com')
            ->set('departmentId', (string) $department->id)
            ->set('roleId', (string) $role->id)
            ->set('accessPage', Page::Charges->value)
            ->set('accessFlags.edit', true)
            ->set('startDate', $this->today())
            ->set('endDate', now('Asia/Manila')->addDays(5)->toDateString())
            ->call('addAccess')
            ->assertHasNoErrors()
            ->assertSee('Not saved yet.')
            ->call('save')
            ->assertSet('adding', false)
            ->assertDispatched('flash', message: 'User added. An invitation was emailed to lito@example.com.');

        $user = User::query()->where('email', 'lito@example.com')->firstOrFail();
        $this->assertSame($company->id, $user->company_id);
        $this->assertFalse($user->is_admin);
        $this->assertNull($user->password);
        $this->assertSame('Lito Reyes', $user->name);

        $access = $user->specialAccess()->firstOrFail();
        $this->assertSame(Page::Charges->value, $access->page);
        $this->assertTrue($access->can_edit);
        $this->assertTrue($access->can_view);

        $this->assertSame(1, EmailOutbox::query()->where('to', 'lito@example.com')->count());
        $this->assertNotNull($page->get('devLink'));
    }

    public function test_special_access_rules(): void
    {
        $today = '2026-10-14';
        $row = ['page' => 'charges', 'flags' => ['add' => false, 'edit' => false, 'view' => true, 'delete' => false], 'startDate' => '2026-10-15', 'endDate' => '2026-10-20'];

        $this->assertSame([], SpecialAccess::checkNew($row, [], ['rates'], 'Charges', $today));
        $this->assertSame(
            ['accessPage' => 'Charges is already part of the main user role.'],
            SpecialAccess::checkNew($row, [], ['charges'], 'Charges', $today),
        );
        $this->assertSame(
            ['startDate' => "The start date can't be in the past."],
            SpecialAccess::checkNew([...$row, 'startDate' => '2026-10-13'], [], [], 'Charges', $today),
        );
        $this->assertSame(
            ['endDate' => "The end date can't be before the start date."],
            SpecialAccess::checkNew([...$row, 'endDate' => '2026-10-14'], [], [], 'Charges', $today),
        );
        $this->assertSame(
            ['accessPage' => 'Charges already has special access on some of these dates.'],
            SpecialAccess::checkNew($row, [['page' => 'charges', 'startDate' => '2026-10-20', 'endDate' => '2026-10-25']], [], 'Charges', $today),
        );
        $this->assertSame('upcoming', SpecialAccess::status($row, $today));
        $this->assertSame('active', SpecialAccess::status($row, '2026-10-20'));
        $this->assertSame('ended', SpecialAccess::status($row, '2026-10-21'));
    }

    public function test_the_invite_form_is_validated(): void
    {
        [$owner] = $this->ownerWithCompany();

        Livewire::actingAs($owner)
            ->test(UsersPage::class)
            ->call('openAdd')
            ->set('email', $owner->email)
            ->call('save')
            ->assertSet('notice.message', 'Please correct the highlighted fields.')
            ->assertSee('The first name field is required.')
            ->assertSee('A user with this email address already exists.')
            ->assertSee('Select a main user role.');
    }

    public function test_changing_an_invited_users_email_replaces_the_invitation(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $role = Role::factory()->for($company)->create();
        $member = User::factory()->memberOf($company, $role)->invited()->create(['email' => 'old@example.com']);
        AuthToken::query()->create(['user_id' => $member->id, 'type' => AuthTokenType::Invitation, 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay()]);

        Livewire::actingAs($owner)
            ->test(UsersPage::class)
            ->call('openUser', $member->id)
            ->assertSee('Update User Account')
            ->assertSee('Resend Email Invitation to User')
            ->set('email', 'new@example.com')
            ->call('save')
            ->assertDispatched('flash', message: 'Changes saved. A new invitation was emailed to new@example.com.');

        $this->assertSame('new@example.com', $member->fresh()->email);
        $this->assertDatabaseMissing('auth_tokens', ['token_hash' => str_repeat('a', 64)]);
        $this->assertSame(1, EmailOutbox::query()->where('to', 'new@example.com')->count());
    }

    public function test_the_email_is_locked_once_the_user_has_logged_in(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $role = Role::factory()->for($company)->create();
        $member = User::factory()->memberOf($company, $role)->create();

        Livewire::actingAs($owner)
            ->test(UsersPage::class)
            ->call('openUser', $member->id)
            ->assertDontSee('Resend Email Invitation to User')
            ->set('email', 'other@example.com')
            ->call('save')
            ->assertSee("The email address can't be changed after the user has logged in.");
    }

    public function test_started_special_access_cannot_be_removed(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $role = Role::factory()->for($company)->create();
        $member = User::factory()->memberOf($company, $role)->create();
        $member->specialAccess()->create(['page' => 'charges', 'can_view' => true, 'start_date' => $this->today(), 'end_date' => $this->today()]);

        $page = Livewire::actingAs($owner)->test(UsersPage::class)->call('openUser', $member->id);

        // The UI offers no remove button for it, and the server refuses a forged list too.
        $page->call('removeAccess', 0)->assertCount('specialAccess', 1);
        $page->set('specialAccess', [])->call('save')
            ->assertSee("Special access that has already started can't be removed.");

        $this->assertSame(1, $member->specialAccess()->count());
    }

    public function test_deactivating_signs_the_user_out_and_reactivating_restores_them(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $member = User::factory()->memberOf($company, Role::factory()->for($company)->create())->create();

        Livewire::actingAs($owner)
            ->test(UsersPage::class)
            ->call('openUser', $member->id)
            ->call('setActive', false)
            ->assertDispatched('flash', message: "{$member->name} has been deactivated.");

        $this->assertTrue($member->fresh()->isDeactivated());

        Livewire::actingAs($owner)
            ->test(UsersPage::class)
            ->call('openUser', $member->id)
            ->assertSee("This user is deactivated and can't log in.", false)
            ->call('setActive', true);

        $this->assertFalse($member->fresh()->isDeactivated());
    }

    public function test_the_owner_is_listed_but_not_managed_here(): void
    {
        [$owner] = $this->ownerWithCompany();

        Livewire::actingAs($owner)
            ->test(UsersPage::class)
            ->assertSee('Administrator')
            ->call('openUser', $owner->id)
            ->assertSet('userId', '')
            ->assertDontSee('Update User Account');
    }

    public function test_resending_an_invitation_waits_a_minute(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $member = User::factory()->memberOf($company, Role::factory()->for($company)->create())->invited()->create();

        Livewire::actingAs($owner)
            ->test(UsersPage::class)
            ->call('openUser', $member->id)
            ->call('resendInvitation')
            ->assertSet('notice.tone', 'success')
            ->call('resendInvitation')
            ->assertSet('notice.tone', 'error')
            ->assertSee('Please wait');
    }
}
