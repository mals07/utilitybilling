<?php

namespace Tests\Feature;

use App\Enums\Access;
use App\Enums\CustomerStatus;
use App\Enums\Page;
use App\Livewire\Customers\ImportCustomers;
use App\Models\AccountType;
use App\Models\ChargeType;
use App\Models\ClusterType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerImport;
use App\Models\RateType;
use App\Support\CustomerImport\CustomerSpreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ImportCustomersTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['First Name', 'Last Name', 'Email', 'Account Type', 'Status', 'Charge Type', 'Rate', 'Cluster Type', 'Home Address', 'Home City', 'Home Province', 'Home Zip Code'];

    /**
     * The lookups the rows name.
     */
    private function lookups(Company $company): void
    {
        AccountType::factory()->for($company)->create(['name' => 'Residential']);
        AccountType::factory()->for($company)->create(['name' => 'Old Commercial', 'is_active' => false]);
        ChargeType::factory()->for($company)->create(['name' => 'Standard']);
        RateType::factory()->for($company)->create(['name' => 'Residential Rate']);
        ClusterType::factory()->for($company)->create(['name' => 'Zone 1']);
    }

    /**
     * A row in HEADER order: a valid Pending customer in Manila unless overridden.
     *
     * @param  array<string, string>  $overrides  Keyed by column.
     * @return list<string>
     */
    private function row(string $firstName, array $overrides = []): array
    {
        $cells = [
            'First Name' => $firstName,
            'Last Name' => 'Santos',
            'Email' => '',
            'Account Type' => 'residential',
            'Status' => '',
            'Charge Type' => 'Standard',
            'Rate' => 'Residential Rate',
            'Cluster Type' => 'ZONE 1',
            'Home Address' => "{$firstName} St.",
            'Home City' => 'Manila',
            'Home Province' => 'Metro Manila',
            'Home Zip Code' => '1000',
            ...$overrides,
        ];

        return array_map(fn (string $column): string => $cells[$column], self::HEADER);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csv(array $rows, string $delimiter = ','): UploadedFile
    {
        $lines = array_map(fn (array $cells): string => implode($delimiter, $cells), [self::HEADER, ...$rows]);

        return UploadedFile::fake()->createWithContent('customers.csv', implode("\r\n", $lines));
    }

    public function test_valid_rows_are_imported_in_order_with_the_next_account_numbers(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $this->lookups($company);
        Customer::factory()->for($company)->create(['account_number' => '000041']);

        Livewire::actingAs($owner)
            ->test(ImportCustomers::class)
            ->call('openImport')
            ->set('file', $this->csv([$this->row('Maria'), $this->row('Jose', ['Status' => 'connected'])]))
            ->assertSet('preview.readyCount', 2)
            ->assertSet('preview.skippedCount', 0)
            ->assertSee('Import 2 customers')
            ->call('import')
            ->assertDispatched('customers-imported')
            ->assertDispatched('flash', message: '2 customers imported.')
            ->assertSee('2 customers added with account numbers 000042–000043.');

        $import = CustomerImport::query()->sole();
        $this->assertSame($owner->id, $import->user_id);
        $this->assertSame('customers.csv', $import->file_name);
        $this->assertSame(2, $import->imported_count);
        $this->assertSame(0, $import->skipped_count);

        $maria = Customer::query()->where('account_number', '000042')->sole();
        $this->assertSame('Maria', $maria->first_name);
        $this->assertSame(CustomerStatus::Pending, $maria->status);
        $this->assertSame($company->country_id, $maria->home_country_id);
        $this->assertSame($import->id, $maria->customer_import_id);
        $this->assertSame(CustomerStatus::Connected, Customer::query()->where('account_number', '000043')->sole()->status);
    }

    public function test_rows_with_problems_are_listed_skipped_and_kept_for_download(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $this->lookups($company);
        Customer::factory()->for($company)->create([
            'account_number' => '000007', 'first_name' => 'Rosa', 'last_name' => 'Lim', 'home_address' => '5 Mabini St.', 'email' => 'rosa@example.com',
        ]);

        $component = Livewire::actingAs($owner)
            ->test(ImportCustomers::class)
            ->call('openImport')
            ->set('file', $this->csv([
                $this->row('Ana'),
                $this->row('Ben', ['Rate' => 'Residental']),
                $this->row('Cora', ['Account Type' => 'Old Commercial']),
                $this->row('', ['Home Zip Code' => '']),
                $this->row('Rosa', ['Last Name' => 'LIM', 'Home Address' => '5  Mabini St.', 'Email' => 'Rosa@Example.com']),
                $this->row('Ana'),
                $this->row('Dan', ['Home City' => 'Cebu City']),
            ]))
            ->assertSet('preview.readyCount', 1)
            ->assertSet('preview.skippedCount', 6)
            ->assertSee('Rate "Residental" was not found.')
            ->assertSee('Account Type "Old Commercial" is not active.')
            ->assertSee('First Name is required. Home Zip Code is required.')
            ->assertSee('Duplicate of customer 000007 (Rosa Lim).')
            ->assertSee('Duplicate of row 2 in this file.')
            ->assertSee('Home City "Cebu City" was not found in Metro Manila.')
            ->assertSee('Import 1 valid row, skip 6')
            ->call('downloadProblems')
            ->assertFileDownloaded('customers - problems.xlsx')
            ->call('import');

        $import = CustomerImport::query()->sole();
        $this->assertSame(1, $import->imported_count);
        $this->assertSame(6, $import->skipped_count);
        $this->assertSame([3, 4, 5, 6, 7, 8], array_column($import->skipped_rows, 'row'));
        $this->assertSame(['Ana'], $import->customers()->pluck('first_name')->all());

        $component->call('downloadSkipped', $import->id)->assertFileDownloaded('customers - skipped rows.xlsx');
    }

    public function test_a_file_missing_required_columns_is_refused(): void
    {
        [$owner] = $this->ownerWithCompany();

        Livewire::actingAs($owner)
            ->test(ImportCustomers::class)
            ->call('openImport')
            ->set('file', UploadedFile::fake()->createWithContent('customers.csv', "First Name,Last Name\nMaria,Santos"))
            ->assertHasErrors('file')
            ->assertSee('The file is missing these columns: Account Type, Charge Type, Rate, Cluster Type, Home Address, Home City, Home Province, Home Zip Code.')
            ->assertSet('preview', null);
    }

    public function test_other_file_types_are_refused(): void
    {
        [$owner] = $this->ownerWithCompany();

        Livewire::actingAs($owner)
            ->test(ImportCustomers::class)
            ->call('openImport')
            ->set('file', UploadedFile::fake()->createWithContent('customers.pdf', '%PDF-1.4'))
            ->assertSee('"customers.pdf" is not an Excel (.xlsx) or CSV file.');
    }

    public function test_excel_files_keep_numbers_typed_into_general_cells(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $this->lookups($company);

        $path = tempnam(sys_get_temp_dir(), 'import-test');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_map(fn (string $column): string => "{$column} *", self::HEADER)));
        $row = $this->row('Maria');
        $row[array_search('Home Zip Code', self::HEADER, true)] = 1000;
        $writer->addRow(Row::fromValues($row));
        $writer->close();

        Livewire::actingAs($owner)
            ->test(ImportCustomers::class)
            ->call('openImport')
            ->set('file', UploadedFile::fake()->createWithContent('customers.xlsx', (string) file_get_contents($path)))
            ->assertSet('preview.readyCount', 1)
            ->call('import');
        unlink($path);

        $this->assertSame('1000', Customer::query()->sole()->home_zip_code);
    }

    public function test_csv_saved_by_excel_in_the_windows_code_page_with_semicolons_is_read(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $this->lookups($company);
        $csv = mb_convert_encoding(implode("\r\n", [implode(';', self::HEADER), implode(';', $this->row('Niña'))]), 'Windows-1252', 'UTF-8');

        Livewire::actingAs($owner)
            ->test(ImportCustomers::class)
            ->call('openImport')
            ->set('file', UploadedFile::fake()->createWithContent('customers.csv', $csv))
            ->assertSet('preview.readyCount', 1)
            ->call('import');

        $this->assertSame('Niña', Customer::query()->sole()->first_name);
    }

    public function test_the_templates_download(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $this->lookups($company);

        Livewire::actingAs($owner)
            ->test(ImportCustomers::class)
            ->call('downloadTemplate', 'xlsx')
            ->assertFileDownloaded('customer-import-template.xlsx')
            ->call('downloadTemplate', 'csv')
            ->assertFileDownloaded('customer-import-template.csv');
    }

    public function test_the_excel_template_reads_back_with_every_column(): void
    {
        [, $company] = $this->ownerWithCompany();
        $this->lookups($company);

        ob_start();
        CustomerSpreadsheet::template($company, 'xlsx')->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'template-test');
        file_put_contents($path, ob_get_clean());

        $read = CustomerSpreadsheet::read($path, 'xlsx');
        unlink($path);

        // Every column is recognized, so the only complaint is that it's still empty.
        $this->assertSame('The file has no customers in it.', $read['error']);
    }

    public function test_members_without_add_access_cannot_import(): void
    {
        [, $company] = $this->ownerWithCompany();
        $this->lookups($company);
        $viewer = $this->memberWithAccess($company, Page::Customers, [Access::View]);

        Livewire::actingAs($viewer)
            ->test(ImportCustomers::class)
            ->call('openImport')
            ->assertSet('open', false)
            ->set('file', $this->csv([$this->row('Maria')]))
            ->assertSet('preview', null)
            ->call('import')
            ->assertDispatched('flash', message: "You don't have permission to add on this page.", tone: 'error');

        $this->assertSame(0, Customer::count());
    }

    public function test_the_history_lists_only_the_companys_imports(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        CustomerImport::factory()->for($company)->create(['file_name' => 'march-batch.xlsx']);
        $other = CustomerImport::factory()->create([
            'file_name' => 'other-company.xlsx',
            'skipped_count' => 1,
            'skipped_rows' => [['row' => 2, 'cells' => ['First Name' => 'Pia'], 'problem' => 'Rate is required.']],
        ]);

        Livewire::actingAs($owner)
            ->test(ImportCustomers::class)
            ->call('openImport')
            ->assertSee('march-batch.xlsx')
            ->assertDontSee('other-company.xlsx')
            ->call('downloadSkipped', $other->id)
            ->assertNoFileDownloaded();
    }
}
