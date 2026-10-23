<?php

namespace App\Support;

use App\Enums\CustomerStatus;
use App\Livewire\Auth\AuthRules;
use App\Livewire\Company\ProfileForm;
use App\Models\AccountType;
use App\Models\ClusterType;
use App\Models\Company;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * What makes a customer's details valid. Shared by the Add/Update Customer modals and the customer import,
 * so both accept exactly the same customers.
 */
final class CustomerRules
{
    public const ACCOUNT_NUMBER_DIGITS = 6;

    /**
     * Field => customers column.
     */
    public const FIELDS = [
        'firstName' => 'first_name',
        'lastName' => 'last_name',
        'companyName' => 'company_name',
        'email' => 'email',
        'phoneNumber' => 'phone_number',
        'accountTypeId' => 'account_type_id',
        'status' => 'status',
        'chargeTypeId' => 'charge_type_id',
        'rateTypeId' => 'rate_type_id',
        'clusterTypeId' => 'cluster_type_id',
        'homeAddress' => 'home_address',
        'homeCityId' => 'home_city_id',
        'homeProvinceId' => 'home_province_id',
        'homeCountryId' => 'home_country_id',
        'homeZipCode' => 'home_zip_code',
        'billingAddress' => 'billing_address',
        'billingCityId' => 'billing_city_id',
        'billingProvinceId' => 'billing_province_id',
        'billingCountryId' => 'billing_country_id',
        'billingZipCode' => 'billing_zip_code',
        'latitude' => 'latitude',
        'longitude' => 'longitude',
    ];

    public const ADDRESS_PARTS = ['Address', 'CityId', 'ProvinceId', 'CountryId', 'ZipCode'];

    /**
     * Every field, blank.
     *
     * @return array<string, string>
     */
    public static function emptyValues(): array
    {
        return array_fill_keys(array_keys(self::FIELDS), '');
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function trimmed(array $values): array
    {
        return array_map(fn ($value): string => trim((string) $value), $values);
    }

    /**
     * The billing address is optional, but once any part of it is given it must be complete.
     *
     * @param  array<string, string>  $values
     */
    public static function hasBillingAddress(array $values): bool
    {
        return collect(self::ADDRESS_PARTS)->contains(fn (string $part): bool => ($values["billing{$part}"] ?? '') !== '');
    }

    /**
     * Checks each field's format, without the database. Returns [field => message], with "location"
     * for a bad pair of coordinates.
     *
     * @param  array<string, string>  $values  Trimmed.
     * @return array<string, string>
     */
    public static function fieldErrors(array $values): array
    {
        $validation = self::validation(self::hasBillingAddress($values));
        $errors = [];
        foreach (validator($values, $validation['rules'], $validation['messages'])->errors()->messages() as $field => $messages) {
            $errors[$field] = $messages[0];
        }

        if (! self::locationIsValid($values)) {
            $errors['location'] = 'The map location is invalid. Place the pin again.';
        }

        return $errors;
    }

    /**
     * Checks the chosen lookups and addresses against the database. Run once fieldErrors() finds nothing.
     * Returns [field => message].
     *
     * @param  array<string, string>  $values  Trimmed.
     * @return array<string, string>
     */
    public static function recordErrors(array $values, Company $company, ?Customer $existing = null): array
    {
        return [
            ...self::selectionErrors($values, $company, $existing),
            ...self::addressErrors($values, 'home'),
            ...(self::hasBillingAddress($values) ? self::addressErrors($values, 'billing') : []),
        ];
    }

    /**
     * The customers columns for valid values.
     *
     * @param  array<string, string>  $values  Trimmed and valid.
     * @return array<string, mixed>
     */
    public static function attributes(array $values): array
    {
        $attributes = [];
        foreach (self::FIELDS as $field => $column) {
            $value = $values[$field] ?? '';
            $attributes[$column] = match (true) {
                $value === '' => null,
                str_ends_with($field, 'Id') => (int) $value,
                in_array($field, ['latitude', 'longitude'], true) => round((float) $value, 6),
                default => $value,
            };
        }

        return $attributes;
    }

    /**
     * The next account number in the company's sequence: one more than the highest so far.
     */
    public static function nextAccountNumber(Company $company, int $after = 0): string
    {
        $highest = $company->customers()->pluck('account_number')
            ->reduce(fn (int $max, string $number): int => max($max, (int) $number), $after);

        return self::accountNumber($highest + 1);
    }

    public static function accountNumber(int $number): string
    {
        return str_pad((string) $number, self::ACCOUNT_NUMBER_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{rules: array<string, list<mixed>>, messages: array<string, string>}
     */
    private static function validation(bool $withBilling): array
    {
        $rules = [];
        $messages = [];

        $text = function (string $field, string $label, int $max, bool $required) use (&$rules, &$messages): void {
            $rules[$field] = ['bail', $required ? 'required' : 'nullable', "max:{$max}"];
            $messages["{$field}.required"] = "The {$label} field is required.";
            $messages["{$field}.max"] = "The {$label} must not be greater than {$max} characters.";
        };
        $selection = function (string $field, string $message) use (&$rules, &$messages): void {
            $rules[$field] = ['bail', 'required', 'regex:/^[1-9]\d{0,9}$/'];
            $messages["{$field}.required"] = $message;
            $messages["{$field}.regex"] = $message;
        };
        $address = function (string $prefix) use ($text, $selection, &$rules, &$messages): void {
            $text("{$prefix}Address", 'address', 255, true);
            $selection("{$prefix}CityId", 'Select a city.');
            $selection("{$prefix}ProvinceId", 'Select a state/province.');
            $selection("{$prefix}CountryId", 'Select a country/region.');
            $rules["{$prefix}ZipCode"] = ['bail', 'required', 'regex:'.ProfileForm::POSTAL_CODE_PATTERN];
            $messages["{$prefix}ZipCode.required"] = 'The zip/postal code field is required.';
            $messages["{$prefix}ZipCode.regex"] = 'Enter a valid zip/postal code.';
        };

        $text('firstName', 'first name', 75, true);
        $text('lastName', 'last name', 75, true);
        $text('companyName', 'company', 150, false);
        $text('email', 'email', 150, false);
        $rules['email'][] = 'regex:'.AuthRules::EMAIL_PATTERN;
        $messages['email.regex'] = 'Enter a valid email address.';
        $text('phoneNumber', 'phone number', 30, false);
        $rules['phoneNumber'][] = 'regex:'.ProfileForm::PHONE_PATTERN;
        $messages['phoneNumber.regex'] = 'Enter a valid phone number.';
        $selection('accountTypeId', 'Select an account type.');
        $rules['status'] = ['bail', 'required', Rule::enum(CustomerStatus::class)];
        $messages['status.required'] = 'Select a status.';
        $messages['status.enum'] = 'Select a status.';
        $selection('chargeTypeId', 'Select a charge type.');
        $selection('rateTypeId', 'Select a rate.');
        $selection('clusterTypeId', 'Select a cluster type.');
        $address('home');
        if ($withBilling) {
            $address('billing');
        }

        return ['rules' => $rules, 'messages' => $messages];
    }

    /**
     * Both coordinates in range, or neither when no pin is placed.
     *
     * @param  array<string, string>  $values
     */
    private static function locationIsValid(array $values): bool
    {
        $latitude = $values['latitude'] ?? '';
        $longitude = $values['longitude'] ?? '';

        if ($latitude === '' && $longitude === '') {
            return true;
        }

        return is_numeric($latitude) && is_numeric($longitude)
            && abs((float) $latitude) <= 90 && abs((float) $longitude) <= 180;
    }

    /**
     * Checks the chosen lookups belong to the company. Account and cluster types must be active,
     * except the one the customer already has, which stays valid if deactivated since.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private static function selectionErrors(array $values, Company $company, ?Customer $existing): array
    {
        $lookup = fn (string $class, string $field, ?int $current): bool => $class::withTrashed()
            ->where('company_id', $company->id)
            ->whereKey((int) $values[$field])
            ->when((int) $values[$field] !== $current, fn (Builder $query) => $query->where('is_active', true)->whereNull('deleted_at'))
            ->exists();

        $errors = [];
        if (! $lookup(AccountType::class, 'accountTypeId', $existing?->account_type_id)) {
            $errors['accountTypeId'] = 'Select an account type from the list.';
        }
        if (! $lookup(ClusterType::class, 'clusterTypeId', $existing?->cluster_type_id)) {
            $errors['clusterTypeId'] = 'Select a cluster type from the list.';
        }
        if (! $company->chargeTypes()->whereKey((int) $values['chargeTypeId'])->exists()) {
            $errors['chargeTypeId'] = 'Select a charge type from the list.';
        }
        if (! $company->rateTypes()->whereKey((int) $values['rateTypeId'])->exists()) {
            $errors['rateTypeId'] = 'Select a rate from the list.';
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private static function addressErrors(array $values, string $prefix): array
    {
        $errors = [];
        $found = Locations::check(
            (int) $values["{$prefix}CityId"],
            (int) $values["{$prefix}ProvinceId"],
            (int) $values["{$prefix}CountryId"],
            'Select a city from the list.',
        );
        foreach ($found as $suffix => $message) {
            $errors["{$prefix}{$suffix}"] = $message;
        }

        return $errors;
    }
}
