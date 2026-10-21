<?php

namespace Tests\Feature;

use App\Enums\AuthTokenType;
use App\Livewire\Auth\AcceptInvitation;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Auth\SignUp;
use App\Livewire\Auth\VerifyEmail;
use App\Models\EmailOutbox;
use App\Models\User;
use App\Support\AuthTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('dashboard'))->assertRedirect('/login');
        $this->get(route('login'))->assertOk()->assertSee('Email Address or Username');
    }

    public function test_signing_up_creates_an_admin_and_emails_a_verification_link(): void
    {
        Livewire::test(SignUp::class)
            ->set('name', 'Ana Maria Cruz')
            ->set('email', 'Ana@Example.com ')
            ->set('password', 'secret123')
            ->set('confirmPassword', 'secret123')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('verify-email'));

        $user = User::query()->where('email', 'ana@example.com')->firstOrFail();
        $this->assertTrue($user->is_admin);
        $this->assertSame('Ana', $user->first_name);
        $this->assertSame('Maria Cruz', $user->last_name);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('secret123', $user->password));
        $this->assertAuthenticatedAs($user);

        $email = EmailOutbox::query()->where('to', 'ana@example.com')->firstOrFail();
        $this->assertSame(AuthTokenType::EmailVerification->subject(), $email->subject);
        $this->assertStringContainsString('/auth/verify?token=', $email->action_url);
    }

    public function test_sign_up_validates_like_the_next_app(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test(SignUp::class)
            ->set('email', 'taken@example.com')
            ->set('password', 'lettersonly')
            ->set('confirmPassword', 'different')
            ->call('register')
            ->assertHasErrors(['name' => 'required', 'email' => 'unique', 'password' => 'regex', 'confirmPassword' => 'same'])
            ->assertSee('Full name is required.')
            ->assertSee('An account with this email address already exists.')
            ->assertSee('Password must include a letter and a number.')
            ->assertSee('Passwords do not match.');
    }

    public function test_the_verification_link_verifies_the_email_once(): void
    {
        $user = User::factory()->unverified()->create();
        $token = AuthTokens::issue($user, AuthTokenType::EmailVerification);

        $this->actingAs($user)
            ->get(route('auth.verify', ['token' => $token]))
            ->assertRedirect(route('setup', ['verified' => 1]));

        $this->assertNotNull($user->fresh()->email_verified_at);

        // Links are single-use.
        $this->get(route('auth.verify', ['token' => $token]))->assertRedirect(route('verify-email', ['error' => 'invalid']));
    }

    public function test_unverified_users_are_held_on_the_verification_page_and_can_resend_after_a_minute(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verify-email'));

        Livewire::actingAs($user)
            ->test(VerifyEmail::class)
            ->call('resend')
            ->assertSet('status.tone', 'success')
            ->call('resend')
            ->assertSet('status.tone', 'error')
            ->assertSee('Please wait');

        $this->travel(61)->seconds();

        Livewire::actingAs($user)->test(VerifyEmail::class)->call('resend')->assertSet('status.tone', 'success');
    }

    public function test_users_log_in_with_their_email_or_username(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com', 'username' => 'owner.one']);

        Livewire::test(Login::class)
            ->set('login', 'OWNER@example.com')
            ->set('password', 'password1')
            ->call('authenticate')
            ->assertRedirect(route('setup'));
        $this->assertAuthenticatedAs($user);

        Auth::logout();

        Livewire::test(Login::class)
            ->set('login', 'owner.one')
            ->set('password', 'password1')
            ->call('authenticate')
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_passwords_invited_users_and_deactivated_users_cannot_log_in(): void
    {
        User::factory()->create(['email' => 'owner@example.com']);
        User::factory()->invited()->create(['email' => 'invited@example.com']);
        User::factory()->create(['email' => 'gone@example.com', 'deactivated_at' => now()]);

        Livewire::test(Login::class)
            ->set('login', 'owner@example.com')->set('password', 'wrong-password1')->call('authenticate')
            ->assertSet('message', 'Incorrect email address, username or password.')
            ->set('login', 'invited@example.com')->set('password', 'password1')->call('authenticate')
            ->assertSet('message', 'Incorrect email address, username or password.')
            ->set('login', 'gone@example.com')->call('authenticate')
            ->assertSet('message', 'This account has been deactivated. Contact your administrator to restore access.');

        $this->assertGuest();
    }

    public function test_a_password_reset_link_sets_a_new_password_and_signs_out_everywhere(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $component = Livewire::test(ForgotPassword::class)
            ->set('email', 'owner@example.com')
            ->call('sendResetLink')
            ->assertSee('If an account exists for owner@example.com');
        $link = $component->get('devLink');
        $this->assertNotNull($link);

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        Livewire::withQueryParams(['token' => $query['token']])
            ->test(ResetPassword::class)
            ->assertSet('linkValid', true)
            ->set('password', 'newpass123')
            ->set('confirmPassword', 'newpass123')
            ->call('resetPassword')
            ->assertRedirect(route('login', ['reset' => 1]));

        $this->assertTrue(Hash::check('newpass123', $user->fresh()->password));
        $this->assertNotSame($user->remember_token, $user->fresh()->remember_token);

        // The link can't be used twice.
        Livewire::withQueryParams(['token' => $query['token']])->test(ResetPassword::class)->assertSet('linkValid', false);
    }

    public function test_unknown_emails_get_the_same_response_without_a_link(): void
    {
        Livewire::test(ForgotPassword::class)
            ->set('email', 'nobody@example.com')
            ->call('sendResetLink')
            ->assertSee('If an account exists for nobody@example.com')
            ->assertSet('devLink', null);

        $this->assertSame(0, EmailOutbox::count());
    }

    public function test_invited_users_choose_a_username_and_password(): void
    {
        [, $company] = $this->ownerWithCompany();
        User::factory()->create(['username' => 'taken']);
        $invited = User::factory()->invited()->create(['company_id' => $company->id, 'is_admin' => false]);
        $token = AuthTokens::issue($invited, AuthTokenType::Invitation);

        Livewire::withQueryParams(['token' => $token])
            ->test(AcceptInvitation::class)
            ->assertSee("Welcome, {$invited->first_name}")
            ->set('username', 'taken')
            ->set('password', 'secret123')
            ->set('confirmPassword', 'secret123')
            ->call('accept')
            ->assertHasErrors('username')
            ->assertSee('This username is already taken.')
            ->set('username', 'new.user')
            ->call('accept')
            ->assertRedirect(route('dashboard'));

        $invited->refresh();
        $this->assertSame('new.user', $invited->username);
        $this->assertNotNull($invited->email_verified_at);
        $this->assertNotNull($invited->last_login_at);
        $this->assertAuthenticatedAs($invited);
    }

    public function test_expired_invitations_show_the_expired_card(): void
    {
        $invited = User::factory()->invited()->create();
        $token = AuthTokens::issue($invited, AuthTokenType::Invitation);
        $this->travel(8)->days();

        Livewire::withQueryParams(['token' => $token])->test(AcceptInvitation::class)->assertSee('Invitation expired');
    }

    public function test_deactivated_users_are_signed_out_on_their_next_request(): void
    {
        [$owner] = $this->ownerWithCompany();

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        $owner->forceFill(['deactivated_at' => now()])->save();

        $this->get(route('dashboard'))->assertRedirect('/login');
        $this->assertGuest();
    }
}
