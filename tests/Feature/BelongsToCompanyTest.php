<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\CompanyIsolationException;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
        $attributes = Customer::factory()->for($company)->raw();
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
        $this->expectException(CompanyIsolationException::class);
        Customer::query()->count();
    }

    public function test_querying_company_records_with_no_current_company_is_refused(): void
    {
        Customer::factory()->create();

        $this->expectException(CompanyIsolationException::class);

        Customer::query()->get();
    }

    public function test_across_all_companies_finds_every_companys_records_even_when_signed_in(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $own = Customer::factory()->for($company)->create();
        $stranger = Customer::factory()->create();

        $this->actingAs($owner);
        $found = CurrentCompany::acrossAll(fn (): array => Customer::query()->orderBy('id')->pluck('id')->all());

        $this->assertSame([$own->id, $stranger->id], $found);
    }

    public function test_saving_a_record_for_another_company_is_refused(): void
    {
        [$owner] = $this->ownerWithCompany();
        $strangersAttributes = Customer::factory()->raw();

        $this->actingAs($owner);

        try {
            Customer::query()->create($strangersAttributes);
            $this->fail('The record was saved for another company.');
        } catch (CompanyIsolationException) {
            $this->assertDatabaseMissing('customers', ['account_number' => $strangersAttributes['account_number']]);
        }
    }

    public function test_a_record_cannot_be_moved_to_another_company_even_across_all_companies(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->for($company)->create();
        $otherCompany = Company::factory()->create();

        try {
            CurrentCompany::acrossAll(fn (): bool => $customer->update(['company_id' => $otherCompany->id]));
            $this->fail('The record was moved to another company.');
        } catch (CompanyIsolationException) {
            $this->assertDatabaseHas('customers', ['id' => $customer->id, 'company_id' => $company->id]);
        }
    }

    public function test_deleting_another_companys_record_is_refused(): void
    {
        [$owner] = $this->ownerWithCompany();
        $stranger = Customer::factory()->create();

        $this->actingAs($owner);

        try {
            $stranger->delete();
            $this->fail('Another company\'s record was deleted.');
        } catch (CompanyIsolationException) {
            $this->assertDatabaseHas('customers', ['id' => $stranger->id]);
        }
    }

    public function test_a_record_loaded_without_its_company_column_can_still_be_saved(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $customer = Customer::factory()->for($company)->create(['first_name' => 'Ana']);

        $this->actingAs($owner);
        Customer::query()->select(['id', 'first_name'])->findOrFail($customer->id)->update(['first_name' => 'Bea']);

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'first_name' => 'Bea']);
    }

    public function test_the_database_refuses_a_record_linked_to_another_companys_record(): void
    {
        $order = WorkOrder::factory()->create();
        $stranger = Customer::factory()->create();

        $this->expectException(QueryException::class);

        // Straight to the database, past every check in the app.
        DB::table('work_orders')->where('id', $order->id)->update(['customer_id' => $stranger->id]);
    }

    public function test_only_reviewed_code_skips_the_company_scope(): void
    {
        // Each of these filters by company itself or must look across companies (signing in a device).
        $reviewed = [
            'Models/Concerns/NumberedPerCompany.php',
            'Models/User.php',
            'Support/Reader/Devices.php',
        ];
        $found = [];

        foreach (File::allFiles(app_path()) as $file) {
            $path = str_replace('\\', '/', $file->getRelativePathname());
            if (! in_array($path, $reviewed, true) && preg_match('/DB::(?!transaction\b|raw\b)\w+|withoutGlobalScope/', $file->getContents())) {
                $found[] = $path;
            }
        }

        $this->assertSame([], $found, 'These files query the database around the company scope. Filter by company with Eloquent, or review them and add them here.');
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
