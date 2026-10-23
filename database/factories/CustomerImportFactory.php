<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CustomerImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerImport>
 */
class CustomerImportFactory extends Factory
{
    /**
     * An import with nothing skipped.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => null,
            'file_name' => 'customers.xlsx',
            'imported_count' => 1,
            'skipped_count' => 0,
            'first_account_number' => '000001',
            'last_account_number' => '000001',
            'skipped_rows' => null,
        ];
    }
}
