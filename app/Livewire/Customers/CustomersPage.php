<?php

namespace App\Livewire\Customers;

use App\Enums\Access;
use App\Enums\CustomerStatus;
use App\Enums\Page;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Livewire\Concerns\ParsesIds;
use App\Models\Customer;
use App\Support\CustomerRules;
use App\Support\ImageType;
use App\Support\Locations;
use App\Support\PhotoResizer;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
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

    public const PHOTO_MAX_BYTES = 2 * 1024 * 1024;

    public const PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

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
        $this->values = CustomerRules::emptyValues();

        if ($this->customer !== '') {
            $this->fillFromCustomer();
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Redraws the table with the customers just imported.
     */
    #[On('customers-imported')]
    public function refreshAfterImport(): void
    {
        unset($this->selected);
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
        $this->values = CustomerRules::emptyValues();
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

        foreach (CustomerRules::FIELDS as $field => $column) {
            $value = $customer->{$column};
            $this->values[$field] = match (true) {
                $value instanceof CustomerStatus => $value->value,
                $value === null => '',
                default => (string) $value,
            };
        }
    }

    /**
     * The setup wizard's example: the Add Customer form filled with a sample Pending customer at the company's address.
     */
    #[On('setup-example')]
    public function useSetupExample(): void
    {
        $company = $this->company();
        $first = fn (Relation $query, string $preferred): string => (string) ((clone $query)->where('name', $preferred)->value('id') ?? (clone $query)->orderBy('id')->value('id') ?? '');

        $this->openAdd();
        $this->values = [
            ...$this->values,
            'firstName' => 'Maria',
            'lastName' => 'Santos',
            'status' => CustomerStatus::Pending->value,
            'accountTypeId' => $first($company->accountTypes()->where('is_active', true), 'Residential'),
            'rateTypeId' => $first($company->rateTypes(), 'Residential'),
            'chargeTypeId' => $first($company->chargeTypes(), 'Standard'),
            'clusterTypeId' => $first($company->clusterTypes()->where('is_active', true), 'Zone 1'),
            'homeAddress' => (string) $company->address,
            'homeCityId' => (string) $company->city_id,
            'homeProvinceId' => (string) $company->province_id,
            'homeCountryId' => (string) $company->country_id,
            'homeZipCode' => (string) $company->zip_code,
        ];
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
        foreach (CustomerRules::ADDRESS_PARTS as $part) {
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
     * @return array<string, mixed>|null The customer's attributes, or null after showing the errors.
     */
    private function validateCustomer(?Customer $existing): ?array
    {
        $this->resetValidation();
        $this->values = CustomerRules::trimmed($this->values);

        // A bad location means a tampered or broken request, since only the map sets it.
        $errors = CustomerRules::fieldErrors($this->values);
        if ($photoError = $this->photoError()) {
            $errors['photo'] = $photoError;
        }

        if ($errors === []) {
            $errors = CustomerRules::recordErrors($this->values, $this->company(), $existing);
        }

        if ($errors !== []) {
            foreach ($errors as $key => $message) {
                $this->addError(in_array($key, ['location', 'photo'], true) ? $key : "values.{$key}", $message);
            }
            $this->notice = 'Please correct the highlighted fields.';
            $this->dispatch('focus-first-error');

            return null;
        }

        $attributes = CustomerRules::attributes($this->values);

        if ($this->photo !== null) {
            $data = (string) file_get_contents($this->photo->getRealPath());
            $photo = PhotoResizer::shrink($data, PhotoResizer::CUSTOMER_MAX_SIDE) ?? ['data' => $data, 'mime' => ImageType::detect($data)];
            $attributes['photo_data'] = $photo['data'];
            $attributes['photo_mime_type'] = $photo['mime'];
            $attributes['photo_file_name'] = mb_substr($this->photo->getClientOriginalName(), 0, 255);
        } elseif ($this->photoRemoved) {
            $attributes['photo_data'] = null;
            $attributes['photo_mime_type'] = null;
            $attributes['photo_file_name'] = null;
        }

        return $attributes;
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
            $accountNumber = CustomerRules::nextAccountNumber($this->company());

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
