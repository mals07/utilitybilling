<?php

namespace App\Livewire\Customers;

use App\Enums\Access;
use App\Enums\CustomerStatus;
use App\Enums\Page;
use App\Livewire\Auth\AuthRules;
use App\Livewire\Company\ProfileForm;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Livewire\Concerns\ParsesIds;
use App\Models\AccountType;
use App\Models\ClusterType;
use App\Models\Customer;
use App\Support\ImageType;
use App\Support\Locations;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Customers: the customers table and the Add/Update Customer modals.
 */
#[Layout('layouts.app')]
#[Title('Customers')]
class CustomersPage extends Component
{
    use GuardsPageAccess, ParsesIds, WithFileUploads, WithPagination;

    /**
     * Customers shown per page of the customers table.
     */
    public const PER_PAGE = 10;

    /**
     * Minimum rows drawn in the customers table, so an empty table keeps its shape.
     */
    public const MINIMUM_ROWS = 10;

    public const ACCOUNT_NUMBER_DIGITS = 6;

    public const PHOTO_MAX_BYTES = 2 * 1024 * 1024;

    public const PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Modal field => customers column.
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

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * The customer open in the Update Customer modal.
     */
    #[Url(as: 'customer', except: '')]
    public string $customer = '';

    public bool $adding = false;

    /**
     * @var array<string, string>
     */
    public array $values = [];

    /**
     * The framed photo picked in the modal, not saved yet.
     */
    public ?TemporaryUploadedFile $photo = null;

    public bool $photoRemoved = false;

    public ?string $notice = null;

    protected function page(): Page
    {
        return Page::Customers;
    }

    public function mount(): void
    {
        $this->values = self::emptyValues();

        if ($this->customer !== '') {
            $this->fillFromCustomer();
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, string>
     */
    private static function emptyValues(): array
    {
        return array_fill_keys(array_keys(self::FIELDS), '');
    }

    #[Computed]
    public function selected(): ?Customer
    {
        $id = self::idFrom($this->customer);

        return $id === null ? null : $this->company()->customers()->find($id);
    }

    /* ---------------------------- Opening/closing ---------------------------- */

    public function openAdd(): void
    {
        $this->customer = '';
        $this->resetForm();
        $this->adding = true;
    }

    public function openCustomer(int $id): void
    {
        $this->adding = false;
        $this->customer = (string) $id;
        $this->fillFromCustomer();
    }

    public function closeModal(): void
    {
        $this->adding = false;
        $this->customer = '';
        $this->resetForm();
    }

    private function resetForm(): void
    {
        unset($this->selected);
        $this->values = self::emptyValues();
        $this->reset('photo', 'photoRemoved', 'notice');
        $this->resetValidation();
    }

    private function fillFromCustomer(): void
    {
        $this->resetForm();
        $customer = $this->selected;

        if ($customer === null) {
            $this->customer = '';

            return;
        }

        foreach (self::FIELDS as $field => $column) {
            $value = $customer->{$column};
            $this->values[$field] = match (true) {
                $value instanceof CustomerStatus => $value->value,
                $value === null => '',
                default => (string) $value,
            };
        }
    }

    /* ------------------------------- Locations ------------------------------- */

    /**
     * @return array{countries: list<array{id: int, name: string}>, provinces: list<array{id: int, name: string, countryId: int}>, cities: list<array{id: int, name: string, provinceId: int}>}
     */
    #[Computed(persist: true)]
    public function locations(): array
    {
        return Locations::all();
    }

    /**
     * Keeps the city, province and country of an address consistent as they are picked.
     */
    public function updatedValues(mixed $value, ?string $field = null): void
    {
        if ($field === null) {
            return;
        }

        $this->resetValidation(["values.{$field}", 'location']);

        if (! preg_match('/^(home|billing)(CountryId|ProvinceId|CityId)$/', $field, $match)) {
            return;
        }

        [, $prefix, $part] = $match;
        $provinces = collect($this->locations['provinces'])->keyBy('id');
        $cities = collect($this->locations['cities'])->keyBy('id');
        $value = (string) $value;

        if ($part === 'CountryId') {
            $province = $provinces->get((int) $this->values["{$prefix}ProvinceId"]);
            if (! $province || (string) $province['countryId'] !== $value) {
                $this->values["{$prefix}ProvinceId"] = '';
                $this->values["{$prefix}CityId"] = '';
            }
        } elseif ($part === 'ProvinceId') {
            $province = $provinces->get((int) $value);
            $city = $cities->get((int) $this->values["{$prefix}CityId"]);
            if ($province) {
                $this->values["{$prefix}CountryId"] = (string) $province['countryId'];
            }
            if (! $city || (string) $city['provinceId'] !== $value) {
                $this->values["{$prefix}CityId"] = '';
            }
        } else {
            $city = $cities->get((int) $value);
            $province = $city ? $provinces->get($city['provinceId']) : null;
            if ($province) {
                $this->values["{$prefix}ProvinceId"] = (string) $province['id'];
                $this->values["{$prefix}CountryId"] = (string) $province['countryId'];
            }
        }
    }

    public function copyHomeAddress(): void
    {
        foreach (self::ADDRESS_PARTS as $part) {
            $this->values["billing{$part}"] = $this->values["home{$part}"];
            $this->resetValidation("values.billing{$part}");
        }
    }

    /**
     * @return array{countries: array<int, string>, provinces: array<int, string>, cities: array<int, string>}
     */
    private function locationOptions(string $prefix): array
    {
        $provinces = collect($this->locations['provinces'])->keyBy('id');
        $countryId = $this->values["{$prefix}CountryId"];
        $provinceId = $this->values["{$prefix}ProvinceId"];

        return [
            'countries' => collect($this->locations['countries'])->pluck('name', 'id')->all(),
            'provinces' => collect($this->locations['provinces'])
                ->filter(fn (array $province): bool => $countryId === '' || (string) $province['countryId'] === $countryId)
                ->pluck('name', 'id')->all(),
            // Until a province is chosen, list every city with its province to disambiguate.
            'cities' => collect($this->locations['cities'])
                ->filter(fn (array $city): bool => $provinceId === '' || (string) $city['provinceId'] === $provinceId)
                ->mapWithKeys(fn (array $city): array => [$city['id'] => $provinceId !== ''
                    ? $city['name']
                    : $city['name'].', '.($provinces[$city['provinceId']]['name'] ?? '')])
                ->all(),
        ];
    }

    /* --------------------------------- Photo --------------------------------- */

    public function updatedPhoto(): void
    {
        $this->resetValidation('photo');

        if ($error = $this->photoError()) {
            $this->photo = null;
            $this->addError('photo', $error);

            return;
        }

        $this->photoRemoved = false;
    }

    private function photoError(): ?string
    {
        $file = $this->photo;
        if ($file === null) {
            return null;
        }

        $name = $file->getClientOriginalName();

        if (! in_array($file->getMimeType(), self::PHOTO_MIME_TYPES, true)) {
            return "\"{$name}\" is not a JPG, PNG or WebP image.";
        }
        if ($file->getSize() === 0) {
            return "\"{$name}\" is empty.";
        }
        if ($file->getSize() > self::PHOTO_MAX_BYTES) {
            $sizeMb = number_format($file->getSize() / (1024 * 1024), 1);

            return "\"{$name}\" is {$sizeMb}MB. The photo must not exceed 2MB.";
        }

        $detected = ImageType::detect((string) file_get_contents($file->getRealPath()));
        if ($detected === null || ! in_array($detected, self::PHOTO_MIME_TYPES, true)) {
            return "\"{$name}\" is not a valid JPG, PNG or WebP image.";
        }

        return null;
    }

    public function removePhoto(): void
    {
        $this->photo = null;
        $this->photoRemoved = $this->selected?->photo_file_name !== null;
        $this->resetValidation('photo');
    }

    /* --------------------------------- Saving -------------------------------- */

    /**
     * @return array{rules: array<string, list<mixed>>, messages: array<string, string>}
     */
    private static function validation(bool $withBilling): array
    {
        $rules = [];
        $messages = [];

        $text = function (string $field, string $label, int $max, bool $required) use (&$rules, &$messages): void {
            $rules["values.{$field}"] = ['bail', $required ? 'required' : 'nullable', "max:{$max}"];
            $messages["values.{$field}.required"] = "The {$label} field is required.";
            $messages["values.{$field}.max"] = "The {$label} must not be greater than {$max} characters.";
        };
        $selection = function (string $field, string $message) use (&$rules, &$messages): void {
            $rules["values.{$field}"] = ['bail', 'required', 'regex:/^[1-9]\d{0,9}$/'];
            $messages["values.{$field}.required"] = $message;
            $messages["values.{$field}.regex"] = $message;
        };
        $address = function (string $prefix) use ($text, $selection, &$rules, &$messages): void {
            $text("{$prefix}Address", 'address', 255, true);
            $selection("{$prefix}CityId", 'Select a city.');
            $selection("{$prefix}ProvinceId", 'Select a state/province.');
            $selection("{$prefix}CountryId", 'Select a country/region.');
            $rules["values.{$prefix}ZipCode"] = ['bail', 'required', 'regex:'.ProfileForm::POSTAL_CODE_PATTERN];
            $messages["values.{$prefix}ZipCode.required"] = 'The zip/postal code field is required.';
            $messages["values.{$prefix}ZipCode.regex"] = 'Enter a valid zip/postal code.';
        };

        $text('firstName', 'first name', 75, true);
        $text('lastName', 'last name', 75, true);
        $text('companyName', 'company', 150, false);
        $text('email', 'email', 150, false);
        $rules['values.email'][] = 'regex:'.AuthRules::EMAIL_PATTERN;
        $messages['values.email.regex'] = 'Enter a valid email address.';
        $text('phoneNumber', 'phone number', 30, false);
        $rules['values.phoneNumber'][] = 'regex:'.ProfileForm::PHONE_PATTERN;
        $messages['values.phoneNumber.regex'] = 'Enter a valid phone number.';
        $selection('accountTypeId', 'Select an account type.');
        $rules['values.status'] = ['bail', 'required', Rule::enum(CustomerStatus::class)];
        $messages['values.status.required'] = 'Select a status.';
        $messages['values.status.enum'] = 'Select a status.';
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
     */
    private function locationIsValid(): bool
    {
        $latitude = $this->values['latitude'];
        $longitude = $this->values['longitude'];

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
     * @return array<string, string>
     */
    private function checkSelections(?Customer $existing): array
    {
        $company = $this->company();
        $lookup = fn (string $class, string $field, ?int $current): bool => $class::withTrashed()
            ->where('company_id', $company->id)
            ->whereKey((int) $this->values[$field])
            ->when((int) $this->values[$field] !== $current, fn (Builder $query) => $query->where('is_active', true)->whereNull('deleted_at'))
            ->exists();

        $errors = [];
        if (! $lookup(AccountType::class, 'accountTypeId', $existing?->account_type_id)) {
            $errors['values.accountTypeId'] = 'Select an account type from the list.';
        }
        if (! $lookup(ClusterType::class, 'clusterTypeId', $existing?->cluster_type_id)) {
            $errors['values.clusterTypeId'] = 'Select a cluster type from the list.';
        }
        if (! $company->chargeTypes()->whereKey((int) $this->values['chargeTypeId'])->exists()) {
            $errors['values.chargeTypeId'] = 'Select a charge type from the list.';
        }
        if (! $company->rateTypes()->whereKey((int) $this->values['rateTypeId'])->exists()) {
            $errors['values.rateTypeId'] = 'Select a rate from the list.';
        }

        return $errors;
    }

    /**
     * @return array<string, string>
     */
    private function checkAddress(string $prefix): array
    {
        $errors = [];
        $found = Locations::check(
            (int) $this->values["{$prefix}CityId"],
            (int) $this->values["{$prefix}ProvinceId"],
            (int) $this->values["{$prefix}CountryId"],
            'Select a city from the list.',
        );
        foreach ($found as $suffix => $message) {
            $errors["values.{$prefix}{$suffix}"] = $message;
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>|null The customer's attributes, or null after showing the errors.
     */
    private function validateCustomer(?Customer $existing): ?array
    {
        $this->resetValidation();
        $this->values = array_map(fn ($value): string => trim((string) $value), $this->values);

        // The billing address is optional, but once any part of it is given it must be complete.
        $withBilling = collect(self::ADDRESS_PARTS)->contains(fn (string $part): bool => $this->values["billing{$part}"] !== '');

        $validation = self::validation($withBilling);
        $errors = array_map(
            fn (array $messages): string => $messages[0],
            validator(['values' => $this->values], $validation['rules'], $validation['messages'])->errors()->messages(),
        );

        // Only the map sets these, so a bad value means a tampered or broken request.
        if (! $this->locationIsValid()) {
            $errors['location'] = 'The map location is invalid. Place the pin again.';
        }
        if ($photoError = $this->photoError()) {
            $errors['photo'] = $photoError;
        }

        if ($errors === []) {
            $errors = [
                ...$this->checkSelections($existing),
                ...$this->checkAddress('home'),
                ...($withBilling ? $this->checkAddress('billing') : []),
            ];
        }

        if ($errors !== []) {
            foreach ($errors as $key => $message) {
                $this->addError($key, $message);
            }
            $this->notice = 'Please correct the highlighted fields.';
            $this->dispatch('focus-first-error');

            return null;
        }

        $attributes = [];
        foreach (self::FIELDS as $field => $column) {
            $value = $this->values[$field];
            $attributes[$column] = match (true) {
                $value === '' => null,
                str_ends_with($field, 'Id') => (int) $value,
                in_array($field, ['latitude', 'longitude'], true) => round((float) $value, 6),
                default => $value,
            };
        }

        if ($this->photo !== null) {
            $data = (string) file_get_contents($this->photo->getRealPath());
            $attributes['photo_data'] = $data;
            $attributes['photo_mime_type'] = ImageType::detect($data);
            $attributes['photo_file_name'] = mb_substr($this->photo->getClientOriginalName(), 0, 255);
        } elseif ($this->photoRemoved) {
            $attributes['photo_data'] = null;
            $attributes['photo_mime_type'] = null;
            $attributes['photo_file_name'] = null;
        }

        return $attributes;
    }

    /**
     * The next account number in the company's sequence: one more than the highest so far.
     */
    private function nextAccountNumber(): string
    {
        $highest = $this->company()->customers()->pluck('account_number')
            ->reduce(fn (int $max, string $number): int => max($max, (int) $number), 0);

        return str_pad((string) ($highest + 1), self::ACCOUNT_NUMBER_DIGITS, '0', STR_PAD_LEFT);
    }

    public function save(): void
    {
        $this->notice = null;

        if ($this->adding) {
            $this->addCustomer();
        } elseif ($this->customer !== '') {
            $this->updateCustomer();
        }
    }

    private function addCustomer(): void
    {
        if (! $this->allowed(Access::Add)) {
            return;
        }

        $attributes = $this->validateCustomer(null);
        if ($attributes === null) {
            return;
        }

        // Two customers added at the same moment may pick the same number; the unique index
        // rejects the second, which then takes the next one.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $accountNumber = $this->nextAccountNumber();

            try {
                $this->company()->customers()->create([...$attributes, 'account_number' => $accountNumber]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            $this->closeModal();
            $this->flash("Customer added with account number {$accountNumber}.");

            return;
        }

        $this->notice = "The customer couldn't be added. Please try again.";
    }

    private function updateCustomer(): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        unset($this->selected);
        $customer = $this->selected;
        if ($customer === null) {
            $this->closeModal();
            $this->flash('That customer no longer exists. The table has been refreshed.', 'error');

            return;
        }

        $attributes = $this->validateCustomer($customer);
        if ($attributes === null) {
            return;
        }

        $customer->update($attributes);

        $this->closeModal();
        $this->flash('Changes saved.');
    }

    /* -------------------------------- Render --------------------------------- */

    /**
     * Active Company Setup lookups, plus every rate type and charge type. A customer's account
     * or cluster type may have been deactivated or removed since; it stays selectable for them.
     *
     * @return array<string, array<int, string>>
     */
    private function formOptions(?Customer $selected): array
    {
        $company = $this->company();
        $active = fn (string $relation, ?int $keep) => $company->{$relation}()
            ->withTrashed()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('is_active', true)->whereNull('deleted_at'))
                ->when($keep, fn (Builder $query) => $query->orWhere('id', $keep)))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        return [
            'accountTypes' => $active('accountTypes', $selected?->account_type_id),
            'clusterTypes' => $active('clusterTypes', $selected?->cluster_type_id),
            'chargeTypes' => $company->chargeTypes()->orderBy('name')->pluck('name', 'id')->all(),
            'rateTypes' => $company->rateTypes()->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    public function render(): View
    {
        $company = $this->company()->load(['city:id,name', 'province:id,name', 'country:id,name']);
        $search = trim($this->search);

        $query = $company->customers()
            ->with(['accountType:id,name', 'meterType:id,name'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('account_number', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('company_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('id');

        $selected = $this->selected;
        $modalOpen = $this->adding || $selected !== null;

        return view('livewire.customers.customers-page', [
            'customers' => $this->paginateClamped($query, self::PER_PAGE),
            'selected' => $selected,
            'modalOpen' => $modalOpen,
            'readOnly' => $this->adding ? ! $this->access['add'] : ! $this->access['edit'],
            'options' => $modalOpen ? $this->formOptions($selected) : [],
            'homeLocations' => $modalOpen ? $this->locationOptions('home') : [],
            'billingLocations' => $modalOpen ? $this->locationOptions('billing') : [],
            'photoUrl' => match (true) {
                $this->photo !== null => $this->photo->temporaryUrl(),
                $this->photoRemoved => null,
                default => $selected?->photoUrl(),
            },
            // Where the map opens when nothing is pinned: the company's city.
            'mapArea' => implode(', ', array_filter([$company->city?->name, $company->province?->name, $company->country?->name])),
            'access' => $this->access,
        ]);
    }
}
