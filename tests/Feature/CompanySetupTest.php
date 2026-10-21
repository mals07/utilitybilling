<?php

namespace Tests\Feature;

use App\Enums\Access;
use App\Enums\Page;
use App\Livewire\Company\LookupTable;
use App\Livewire\Company\OperationSettingsForm;
use App\Livewire\Company\ProfileForm;
use App\Models\AccountType;
use App\Models\Company;
use App\Models\User;
use Database\Factories\CompanyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class CompanySetupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function profileValues(): array
    {
        $city = CompanyFactory::city();

        return [
            'companyName' => 'Bayan Water District',
            'address' => '12 Rizal St.',
            'cityId' => (string) $city->id,
            'provinceId' => (string) $city->province_id,
            'countryId' => (string) $city->province->country_id,
            'zipCode' => '1000',
            'email' => 'office@bayan.test',
            'taxIdNumber' => '',
            'phoneNumber' => '',
            'companyEmail' => '',
            'website' => 'bayan.test',
            'headOfCompany' => 'Juan Dela Cruz',
            'pointPersonFirstName' => 'Maria',
            'pointPersonLastName' => 'Santos',
            'pointPersonContact' => '0917 123 4567',
            'pointPersonEmail' => 'maria@bayan.test',
            'employeeNumber' => '',
            'employeeTitle' => '',
        ];
    }

    public function test_saving_the_profile_during_onboarding_creates_the_company(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ProfileForm::class, ['onboarding' => true, 'canSave' => true])
            ->set('values', $this->profileValues())
            ->set('logo', UploadedFile::fake()->image('logo.png', 40, 40))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('setup'));

        $company = $user->fresh()->company;
        $this->assertNotNull($company);
        $this->assertSame($user->id, $company->user_id);
        $this->assertSame('Bayan Water District', $company->company_name);
        $this->assertNull($company->tax_id_number);
        $this->assertSame('logo.png', $company->logo_file_name);
        $this->assertSame('image/png', $company->logo_mime_type);

        $this->actingAs($user)->get(route('company.asset', ['asset' => 'logo']))->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_saving_an_empty_profile_shows_each_required_field_error(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(ProfileForm::class, ['onboarding' => true, 'canSave' => true])
            ->assertDontSeeHtml('wire:dirty')
            ->call('save')
            ->assertHasErrors([
                'values.companyName', 'values.address', 'values.cityId', 'values.provinceId', 'values.countryId',
                'values.zipCode', 'values.email', 'values.headOfCompany', 'values.pointPersonFirstName',
                'values.pointPersonLastName', 'values.pointPersonContact', 'values.pointPersonEmail',
            ])
            ->assertSee('Company name is required.')
            ->assertSee('Please select a city.')
            ->assertSee('Please correct the highlighted fields.')
            ->assertNoRedirect();
    }

    public function test_the_profile_is_validated_like_the_next_app(): void
    {
        $values = [...$this->profileValues(), 'companyName' => '', 'zipCode' => '!', 'pointPersonContact' => 'abc', 'website' => 'not a site'];

        Livewire::actingAs(User::factory()->create())
            ->test(ProfileForm::class, ['onboarding' => true, 'canSave' => true])
            ->set('values', $values)
            ->call('save')
            ->assertSet('status.message', 'Please correct the highlighted fields.')
            ->assertSee('Company name is required.')
            ->assertSee('Enter a valid zip/postal code.')
            ->assertSee('Enter a valid contact number.')
            ->assertSee('Enter a valid website address.');

        $this->assertSame(0, Company::count());
    }

    public function test_uploads_must_really_be_images_of_an_accepted_format(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(ProfileForm::class, ['onboarding' => true, 'canSave' => true])
            ->set('logo', UploadedFile::fake()->createWithContent('logo.png', 'not really a png'))
            ->assertHasErrors('logo')
            ->assertSet('logo', null)
            ->set('icon', UploadedFile::fake()->create('icon.gif', 10, 'image/gif'))
            ->assertHasErrors('icon')
            ->assertSee('"icon.gif" is not a supported format. Use svg, png, ico.');
    }

    public function test_choosing_a_city_fills_its_province_and_country(): void
    {
        $city = CompanyFactory::city();

        Livewire::actingAs(User::factory()->create())
            ->test(ProfileForm::class, ['onboarding' => true, 'canSave' => true])
            ->set('values.cityId', (string) $city->id)
            ->assertSet('values.provinceId', (string) $city->province_id)
            ->assertSet('values.countryId', (string) $city->province->country_id);
    }

    public function test_view_only_members_cannot_change_the_profile(): void
    {
        [, $company] = $this->ownerWithCompany();
        $viewer = $this->memberWithAccess($company, Page::CompanySetup, [Access::View]);

        $this->actingAs($viewer)->get(route('company'))->assertOk()->assertDontSee('Save Profile');

        Livewire::actingAs($viewer)
            ->test(ProfileForm::class, ['onboarding' => false, 'canSave' => false])
            ->set('values.companyName', 'Renamed')
            ->call('save')
            ->assertSet('status.message', "You don't have permission to make changes on this page.");

        $this->assertNotSame('Renamed', $company->fresh()->company_name);
    }

    public function test_operation_settings_are_saved_and_validated(): void
    {
        [$owner, $company] = $this->ownerWithCompany();

        Livewire::actingAs($owner)
            ->test(OperationSettingsForm::class)
            ->assertSet('timezone', 'Asia/Manila')
            ->set('dueDateDays', '400')
            ->set('currency', 'XXX')
            ->call('save')
            ->assertHasErrors(['dueDateDays', 'currency'])
            ->set('dueDateDays', '15')
            ->set('currency', 'PHP')
            ->set('billingHeader1', 'Statement of Account')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('flash', message: 'Operation setup saved.');

        $company->refresh();
        $this->assertSame(15, $company->due_date_days);
        $this->assertSame('PHP', $company->currency);
        $this->assertSame('Statement of Account', $company->billing_header_1);
    }

    public function test_lookup_entries_can_be_added_toggled_removed_and_restored(): void
    {
        [$owner, $company] = $this->ownerWithCompany();

        $table = Livewire::actingAs($owner)
            ->test(LookupTable::class, ['type' => 'account-types'])
            ->set('name', 'Residential')
            ->call('add')
            ->assertHasNoErrors()
            ->assertSee('Residential')
            ->set('name', 'Residential')
            ->call('add')
            ->assertHasErrors('name')
            ->assertSee('This account type already exists.');

        $entry = AccountType::query()->where('company_id', $company->id)->firstOrFail();

        $table->call('toggleActive', $entry->id);
        $this->assertFalse($entry->fresh()->is_active);

        $table->call('remove', $entry->id);
        $this->assertSoftDeleted($entry);

        // A new entry with the same name blocks restoring the removed one.
        $table->set('name', 'Residential')->call('add')->assertHasNoErrors();
        $table->call('restore', $entry->id)->assertSet('restoreError', '"Residential" already exists in the account types list.');
    }
}
