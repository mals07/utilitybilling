<?php

namespace Tests\Feature;

use App\Enums\Access;
use App\Enums\CustomerStatus;
use App\Enums\Page;
use App\Livewire\Customers\CustomersPage;
use App\Models\AccountType;
use App\Models\ChargeType;
use App\Models\ClusterType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\RateType;
use Database\Factories\CompanyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class CustomersPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function customerValues(Company $company): array
    {
        $city = CompanyFactory::city();

        return [
            'firstName' => 'Rosa',
            'lastName' => 'Lim',
            'companyName' => '',
            'email' => 'rosa@example.com',
            'phoneNumber' => '0917 555 1234',
            'accountTypeId' => (string) AccountType::factory()->for($company)->create()->id,
            'status' => 'connected',
            'chargeTypeId' => (string) ChargeType::factory()->for($company)->create()->id,
            'rateTypeId' => (string) RateType::factory()->for($company)->create()->id,
            'clusterTypeId' => (string) ClusterType::factory()->for($company)->create()->id,
            'homeAddress' => '5 Mabini St.',
            'homeCityId' => (string) $city->id,
            'homeProvinceId' => (string) $city->province_id,
            'homeCountryId' => (string) $city->province->country_id,
            'homeZipCode' => '1000',
            'billingAddress' => '',
            'billingCityId' => '',
            'billingProvinceId' => '',
            'billingCountryId' => '',
            'billingZipCode' => '',
            'latitude' => '14.599512',
            'longitude' => '120.984222',
        ];
    }

    public function test_customers_are_added_with_the_next_account_number(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        Customer::factory()->for($company)->create(['account_number' => '000041']);

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('openAdd')
            ->assertSee('Add Customer')
            ->set('values', $this->customerValues($company))
            ->set('photo', UploadedFile::fake()->image('rosa.jpg', 100, 100))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('adding', false)
            ->assertDispatched('flash', message: 'Customer added with account number 000042.');

        $customer = Customer::query()->where('account_number', '000042')->firstOrFail();
        $this->assertSame('Rosa', $customer->first_name);
        $this->assertSame(CustomerStatus::Connected, $customer->status);
        $this->assertSame(14.599512, $customer->latitude);
        $this->assertNull($customer->billing_address);
        $this->assertSame('image/jpeg', $customer->photo_mime_type);

        $this->actingAs($owner)->get(route('customers.photo', $customer))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_a_partly_filled_billing_address_must_be_completed(): void
    {
        [$owner, $company] = $this->ownerWithCompany();

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('openAdd')
            ->set('values', [...$this->customerValues($company), 'billingAddress' => 'PO Box 1'])
            ->call('save')
            ->assertSet('notice', 'Please correct the highlighted fields.')
            ->assertSee('Select a city.')
            ->assertSee('The zip/postal code field is required.');

        $this->assertSame(0, Customer::count());
    }

    public function test_same_as_home_address_copies_the_address(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $values = $this->customerValues($company);

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('openAdd')
            ->set('values', $values)
            ->call('copyHomeAddress')
            ->assertSet('values.billingAddress', '5 Mabini St.')
            ->assertSet('values.billingCityId', $values['homeCityId'])
            ->assertSet('values.billingZipCode', '1000');
    }

    public function test_only_one_coordinate_is_an_invalid_map_location(): void
    {
        [$owner, $company] = $this->ownerWithCompany();

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('openAdd')
            ->set('values', [...$this->customerValues($company), 'longitude' => ''])
            ->call('save')
            ->assertSee('The map location is invalid. Place the pin again.');
    }

    public function test_inactive_account_types_are_refused_except_the_one_the_customer_has(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $values = $this->customerValues($company);
        $inactive = AccountType::factory()->for($company)->create(['is_active' => false]);

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('openAdd')
            ->set('values', [...$values, 'accountTypeId' => (string) $inactive->id])
            ->call('save')
            ->assertSee('Select an account type from the list.');

        $customer = Customer::factory()->for($company)->create([
            'account_type_id' => $inactive->id,
            'cluster_type_id' => $values['clusterTypeId'],
        ]);

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('openCustomer', $customer->id)
            ->assertSee('Update Customer')
            ->assertSee("Account Number: {$customer->account_number}")
            ->set('values.firstName', 'Renamed')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('flash', message: 'Changes saved.');

        $this->assertSame('Renamed', $customer->fresh()->first_name);
    }

    public function test_view_only_members_see_the_customer_without_saving(): void
    {
        [, $company] = $this->ownerWithCompany();
        $viewer = $this->memberWithAccess($company, Page::Customers, [Access::View]);
        $customer = Customer::factory()->for($company)->create();

        Livewire::actingAs($viewer)
            ->test(CustomersPage::class)
            ->assertDontSee('Add Customer')
            ->call('openCustomer', $customer->id)
            ->assertDontSee('>Update<', false)
            ->set('values.firstName', 'Changed')
            ->call('save')
            ->assertDispatched('flash', message: "You don't have permission to make changes on this page.", tone: 'error');

        $this->assertNotSame('Changed', $customer->fresh()->first_name);
    }

    public function test_search_matches_account_numbers_and_names(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        Customer::factory()->for($company)->create(['account_number' => '000777', 'first_name' => 'Pedro']);
        Customer::factory()->for($company)->create(['account_number' => '000888', 'first_name' => 'Juana']);

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->set('search', '0007')
            ->assertSee('Pedro')
            ->assertDontSee('Juana');
    }
}
