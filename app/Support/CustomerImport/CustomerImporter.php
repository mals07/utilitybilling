<?php

namespace App\Support\CustomerImport;

use App\Enums\CustomerStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerImport;
use App\Models\User;
use App\Support\CustomerRules;
use App\Support\Locations;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Checks rows read from an import file and adds the valid ones as new customers. Lookups are typed by
 * name in the file and matched ignoring capitals and extra spaces.
 */
final class CustomerImporter
{
    /**
     * Rows one file may hold.
     */
    public const MAX_ROWS = 1000;

    /**
     * File column => CustomerRules field, in template order. To import another detail later, add its
     * column here and its field to CustomerRules::FIELDS.
     */
    public const COLUMNS = [
        'First Name' => 'firstName',
        'Last Name' => 'lastName',
        'Company Name' => 'companyName',
        'Email' => 'email',
        'Phone Number' => 'phoneNumber',
        'Account Type' => 'accountTypeId',
        'Status' => 'status',
        'Charge Type' => 'chargeTypeId',
        'Rate' => 'rateTypeId',
        'Cluster Type' => 'clusterTypeId',
        'Home Address' => 'homeAddress',
        'Home City' => 'homeCityId',
        'Home Province' => 'homeProvinceId',
        'Home Country' => 'homeCountryId',
        'Home Zip Code' => 'homeZipCode',
        'Billing Address' => 'billingAddress',
        'Billing City' => 'billingCityId',
        'Billing Province' => 'billingProvinceId',
        'Billing Country' => 'billingCountryId',
        'Billing Zip Code' => 'billingZipCode',
        'Latitude' => 'latitude',
        'Longitude' => 'longitude',
    ];

    /**
     * Columns every row must fill. Status defaults to Pending and the countries to the company's.
     */
    public const REQUIRED = [
        'First Name', 'Last Name', 'Account Type', 'Charge Type', 'Rate', 'Cluster Type',
        'Home Address', 'Home City', 'Home Province', 'Home Zip Code',
    ];

    /**
     * Normalized name => id, per lookup.
     *
     * @var array<string, array<string, int>>
     */
    private array $lookups = [];

    /**
     * Normalized names of the company's deactivated account and cluster types.
     *
     * @var array<string, array<string, true>>
     */
    private array $inactive = [];

    /**
     * Duplicate key => "000045 (Rosa Lim)" for the company's customers.
     *
     * @var array<string, string>
     */
    private array $existing = [];

    public function __construct(private Company $company)
    {
        $this->loadLookups();

        $company->customers()->get(['account_number', 'first_name', 'last_name', 'home_address', 'email'])
            ->each(function (Customer $customer): void {
                $key = self::duplicateKey($customer->first_name, $customer->last_name, $customer->home_address, (string) $customer->email);
                $this->existing[$key] ??= "{$customer->account_number} ({$customer->fullName()})";
            });
    }

    /**
     * Lower case with single spaces, for matching names typed in a file.
     */
    public static function normalize(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    /**
     * Checks each row. Ready rows carry their CustomerRules values; skipped rows carry their cells and
     * every problem found, so the user can fix them all at once.
     *
     * @param  list<array{row: int, cells: array<string, string>}>  $rows  Cells keyed by column name.
     * @return array{
     *     ready: list<array{row: int, values: array<string, string>}>,
     *     skipped: list<array{row: int, cells: array<string, string>, problem: string}>,
     * }
     */
    public function check(array $rows): array
    {
        $ready = [];
        $skipped = [];
        $seen = [];

        foreach ($rows as $row) {
            $cells = array_map(fn (string $value): string => trim($value), $row['cells']);
            [$values, $problems] = $this->resolve($cells);

            // Rows missing a name or address are already skipped, and would all look alike.
            if ($values['firstName'] !== '' && $values['lastName'] !== '' && $values['homeAddress'] !== '') {
                $key = self::duplicateKey($values['firstName'], $values['lastName'], $values['homeAddress'], $values['email']);
                if (isset($this->existing[$key])) {
                    $problems[] = "Duplicate of customer {$this->existing[$key]}.";
                } elseif (isset($seen[$key])) {
                    $problems[] = "Duplicate of row {$seen[$key]} in this file.";
                }
                $seen[$key] ??= $row['row'];
            }

            if ($problems === []) {
                $ready[] = ['row' => $row['row'], 'values' => $values];
            } else {
                $skipped[] = ['row' => $row['row'], 'cells' => $cells, 'problem' => implode(' ', $problems)];
            }
        }

        return ['ready' => $ready, 'skipped' => $skipped];
    }

    /**
     * Adds the ready rows as customers numbered in file order, and records the import.
     *
     * @param  list<array{row: int, values: array<string, string>}>  $ready
     * @param  list<array{row: int, cells: array<string, string>, problem: string}>  $skipped
     */
    public function import(array $ready, array $skipped, string $fileName, User $user): CustomerImport
    {
        // A customer added at the same moment may take one of the numbers; the unique index rejects
        // the import, which then starts again from the next free number.
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): CustomerImport => $this->store($ready, $skipped, $fileName, $user));
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 3) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @param  list<array{row: int, values: array<string, string>}>  $ready
     * @param  list<array{row: int, cells: array<string, string>, problem: string}>  $skipped
     */
    private function store(array $ready, array $skipped, string $fileName, User $user): CustomerImport
    {
        $first = (int) CustomerRules::nextAccountNumber($this->company);
        $last = $first + count($ready) - 1;

        $import = $this->company->customerImports()->create([
            'user_id' => $user->id,
            'file_name' => mb_substr($fileName, 0, 255),
            'imported_count' => count($ready),
            'skipped_count' => count($skipped),
            'first_account_number' => $ready === [] ? null : CustomerRules::accountNumber($first),
            'last_account_number' => $ready === [] ? null : CustomerRules::accountNumber($last),
            'skipped_rows' => $skipped === [] ? null : $skipped,
        ]);

        foreach ($ready as $index => $row) {
            $this->company->customers()->create([
                ...CustomerRules::attributes($row['values']),
                'customer_import_id' => $import->id,
                'account_number' => CustomerRules::accountNumber($first + $index),
            ]);
        }

        return $import;
    }

    /**
     * Turns a row's cells into CustomerRules values, resolving the typed names to ids.
     *
     * @param  array<string, string>  $cells
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function resolve(array $cells): array
    {
        $values = CustomerRules::emptyValues();
        $problems = [];
        $cell = fn (string $column): string => $cells[$column] ?? '';

        foreach (self::REQUIRED as $column) {
            if ($cell($column) === '') {
                $problems[$column] = "{$column} is required.";
            }
        }

        foreach (self::COLUMNS as $column => $field) {
            if (! str_ends_with($field, 'Id') && $field !== 'status') {
                $values[$field] = $cell($column);
            }
        }

        $status = $cell('Status');
        $values['status'] = CustomerStatus::Pending->value;
        if ($status !== '') {
            $values['status'] = CustomerStatus::tryFrom(self::normalize($status))?->value ?? '';
            if ($values['status'] === '') {
                $problems['Status'] = "Status \"{$status}\" must be Pending, Connected or Disconnected.";
            }
        }

        foreach (['Account Type' => 'accountTypeId', 'Charge Type' => 'chargeTypeId', 'Rate' => 'rateTypeId', 'Cluster Type' => 'clusterTypeId'] as $column => $field) {
            $name = $cell($column);
            if ($name === '') {
                continue;
            }
            $id = $this->lookups[$field][self::normalize($name)] ?? null;
            if ($id !== null) {
                $values[$field] = (string) $id;
            } elseif (isset($this->inactive[$field][self::normalize($name)])) {
                $problems[$column] = "{$column} \"{$name}\" is not active.";
            } else {
                $problems[$column] = "{$column} \"{$name}\" was not found.";
            }
        }

        $hasBilling = collect(['Billing Address', 'Billing City', 'Billing Province', 'Billing Country', 'Billing Zip Code'])
            ->contains(fn (string $column): bool => $cell($column) !== '');
        foreach ($hasBilling ? ['Home', 'Billing'] : ['Home'] as $prefix) {
            $problems = [...$problems, ...$this->resolveAddress($prefix, $cells, $values)];
        }

        // The format checks, worded with the column, for whatever the checks above let through.
        $columns = array_flip(self::COLUMNS);
        foreach (CustomerRules::fieldErrors($values) as $field => $message) {
            $column = $field === 'location' ? 'Latitude' : $columns[$field];
            $problems[$column] ??= $field === 'location'
                ? 'Latitude and Longitude must both be given, within -90 to 90 and -180 to 180.'
                : "{$column}: {$message}";
        }

        return [$values, array_values($problems)];
    }

    /**
     * Resolves one address's country, province and city into $values.
     *
     * @param  array<string, string>  $cells
     * @param  array<string, string>  $values
     * @return array<string, string> Problems keyed by column.
     */
    private function resolveAddress(string $prefix, array $cells, array &$values): array
    {
        $field = strtolower($prefix);
        $country = $cells["{$prefix} Country"] ?? '';
        $province = $cells["{$prefix} Province"] ?? '';
        $city = $cells["{$prefix} City"] ?? '';

        $countryId = $country === ''
            ? $this->company->country_id
            : $this->lookups['country'][self::normalize($country)] ?? null;
        if ($countryId === null) {
            return ["{$prefix} Country" => $country === '' ? "{$prefix} Country is required." : "{$prefix} Country \"{$country}\" was not found."];
        }
        $values["{$field}CountryId"] = (string) $countryId;

        if ($province === '') {
            return $prefix === 'Billing' ? ['Billing Province' => 'Billing Province is required when a billing address is given.'] : [];
        }
        $provinceId = $this->lookups['province']["{$countryId}|".self::normalize($province)] ?? null;
        if ($provinceId === null) {
            return ["{$prefix} Province" => "{$prefix} Province \"{$province}\" was not found."];
        }
        $values["{$field}ProvinceId"] = (string) $provinceId;

        if ($city === '') {
            return $prefix === 'Billing' ? ['Billing City' => 'Billing City is required when a billing address is given.'] : [];
        }
        $cityId = $this->lookups['city']["{$provinceId}|".self::normalize($city)] ?? null;
        if ($cityId === null) {
            return ["{$prefix} City" => "{$prefix} City \"{$city}\" was not found in {$province}."];
        }
        $values["{$field}CityId"] = (string) $cityId;

        return [];
    }

    /**
     * Same first name, last name, home address and email (both blank counts as the same).
     */
    private static function duplicateKey(string $firstName, string $lastName, string $homeAddress, string $email): string
    {
        return implode('|', array_map(self::normalize(...), [$firstName, $lastName, $homeAddress, $email]));
    }

    private function loadLookups(): void
    {
        $company = $this->company;
        $byName = fn ($records): array => $records->mapWithKeys(fn ($record): array => [self::normalize($record->name) => $record->id])->all();

        foreach (['accountTypeId' => 'accountTypes', 'clusterTypeId' => 'clusterTypes'] as $field => $relation) {
            $types = $company->{$relation}()->get(['id', 'name', 'is_active']);
            $this->lookups[$field] = $byName($types->where('is_active', true));
            $this->inactive[$field] = $types->where('is_active', false)
                ->mapWithKeys(fn ($type): array => [self::normalize($type->name) => true])->all();
        }
        $this->lookups['chargeTypeId'] = $byName($company->chargeTypes()->get(['id', 'name']));
        $this->lookups['rateTypeId'] = $byName($company->rateTypes()->get(['id', 'name']));

        $locations = Locations::all();
        $this->lookups['country'] = collect($locations['countries'])
            ->mapWithKeys(fn (array $country): array => [self::normalize($country['name']) => $country['id']])->all();
        $this->lookups['province'] = collect($locations['provinces'])
            ->mapWithKeys(fn (array $province): array => ["{$province['countryId']}|".self::normalize($province['name']) => $province['id']])->all();
        $this->lookups['city'] = collect($locations['cities'])
            ->mapWithKeys(fn (array $city): array => ["{$city['provinceId']}|".self::normalize($city['name']) => $city['id']])->all();
    }
}
