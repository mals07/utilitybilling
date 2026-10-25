<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Customer;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class BelongsToCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_user_finds_only_their_companys_records(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $own = Customer::factory()->for($company)->create();
        $stranger = Customer::factory()->create();

        $this->actingAs($owner);

        $this->assertSame([$own->id], Customer::query()->pluck('id')->all());
        $this->assertNull(Customer::query()->find($stranger->id));
    }

    public function test_a_signed_in_user_without_a_company_finds_no_records(): void
    {
        Customer::factory()->create();

        $this->actingAs(User::factory()->create());

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_a_record_created_without_a_company_gets_the_signed_in_users(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $attributes = Customer::factory()->raw();
        unset($attributes['company_id']);

        $this->actingAs($owner);
        $customer = Customer::query()->create($attributes);

        $this->assertSame($company->id, $customer->company_id);
    }

    public function test_running_as_a_company_finds_only_its_records_until_the_callback_ends(): void
    {
        $company = Company::factory()->create();
        $own = Customer::factory()->for($company)->create();
        Customer::factory()->create();

        $found = CurrentCompany::as($company, fn (): array => Customer::query()->pluck('id')->all());

        $this->assertSame([$own->id], $found);
        $this->assertSame(2, Customer::query()->count());
    }

    public function test_every_model_with_a_company_id_column_is_limited_to_the_current_company(): void
    {
        // Users are looked up across companies to sign in, reset passwords and accept invitations.
        $exempt = [User::class];
        $unscoped = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.Str::before(basename($file), '.php');
            if (! is_subclass_of($class, Model::class) || (new \ReflectionClass($class))->isAbstract() || in_array($class, $exempt, true)) {
                continue;
            }

            if (Schema::hasColumn((new $class)->getTable(), 'company_id') && ! in_array(BelongsToCompany::class, class_uses_recursive($class), true)) {
                $unscoped[] = $class;
            }
        }

        $this->assertSame([], $unscoped, 'These models have a company_id column but do not use BelongsToCompany.');
    }
}
