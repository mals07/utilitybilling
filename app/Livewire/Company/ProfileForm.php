<?php

namespace App\Livewire\Company;

use App\Enums\Access;
use App\Enums\Page;
use App\Livewire\Auth\AuthRules;
use App\Models\Company;
use App\Models\User;
use App\Support\ImageType;
use App\Support\Locations;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The Company Profile tab. Saving creates the company during onboarding, then updates it.
 */
class ProfileForm extends Component
{
    use WithFileUploads;

    /**
     * Form field => company_profiles column.
     */
    public const FIELDS = [
        'companyName' => 'company_name',
        'address' => 'address',
        'cityId' => 'city_id',
        'provinceId' => 'province_id',
        'countryId' => 'country_id',
        'zipCode' => 'zip_code',
        'email' => 'email',
        'taxIdNumber' => 'tax_id_number',
        'phoneNumber' => 'phone_number',
        'companyEmail' => 'company_email',
        'website' => 'website',
        'headOfCompany' => 'head_of_company',
        'pointPersonFirstName' => 'point_person_first_name',
        'pointPersonLastName' => 'point_person_last_name',
        'pointPersonContact' => 'point_person_contact',
        'pointPersonEmail' => 'point_person_email',
        'employeeNumber' => 'employee_number',
        'employeeTitle' => 'employee_title',
    ];

    public const PHONE_PATTERN = '/^\+?[0-9()\-\s]{7,20}$/';

    public const POSTAL_CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9\s-]{2,9}$/';

    public const MAX_UPLOAD_BYTES = 3 * 1024 * 1024;

    /**
     * Accepted uploads: extensions, MIME types and the formats named in messages.
     */
    public const UPLOAD_RULES = [
        'logo' => [
            'extensions' => ['svg', 'png', 'jpg', 'jpeg'],
            'mimeTypes' => ['image/svg+xml', 'image/png', 'image/jpeg'],
            'formats' => 'svg, png, jpg',
        ],
        'icon' => [
            'extensions' => ['svg', 'png', 'ico'],
            'mimeTypes' => ['image/svg+xml', 'image/png', 'image/x-icon', 'image/vnd.microsoft.icon'],
            'formats' => 'svg, png, ico',
        ],
    ];

    /**
     * First-time setup: saving the profile continues to the dashboard.
     */
    #[Locked]
    public bool $onboarding = false;

    /**
     * False when the user's role may view but not edit Company Setup.
     */
    #[Locked]
    public bool $canSave = true;

    /**
     * @var array<string, string>
     */
    public array $values = [];

    public ?TemporaryUploadedFile $logo = null;

    public ?TemporaryUploadedFile $icon = null;

    public bool $removeLogo = false;

    public bool $removeIcon = false;

    /**
     * @var array{type: string, message: string}|null
     */
    public ?array $status = null;

    public function mount(bool $onboarding, bool $canSave): void
    {
        $this->onboarding = $onboarding;
        $this->canSave = $canSave;

        $this->fillValues($this->user()->company);
    }

    private function fillValues(?Company $company): void
    {
        foreach (self::FIELDS as $field => $column) {
            $this->values[$field] = (string) ($company?->{$column} ?? '');
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /* ------------------------------ Validation ------------------------------ */

    /**
     * @return array{rules: array<string, list<mixed>>, messages: array<string, string>}
     */
    private function validation(): array
    {
        $rules = [];
        $messages = [];

        $required = function (string $field, string $label, int $max, ?string $pattern = null, ?string $patternMessage = null) use (&$rules, &$messages): void {
            $rules["values.{$field}"] = ['bail', 'required', "max:{$max}", ...($pattern ? ["regex:{$pattern}"] : [])];
            $messages["values.{$field}.required"] = "{$label} is required.";
            $messages["values.{$field}.max"] = "{$label} must be {$max} characters or fewer.";
            if ($patternMessage) {
                $messages["values.{$field}.regex"] = $patternMessage;
            }
        };

        $optional = function (string $field, string $label, int $max, array $extra = [], ?string $extraMessage = null) use (&$rules, &$messages): void {
            $rules["values.{$field}"] = ['bail', 'nullable', "max:{$max}", ...$extra];
            $messages["values.{$field}.max"] = "{$label} must be {$max} characters or fewer.";
            if ($extraMessage) {
                $messages["values.{$field}.regex"] = $extraMessage;
            }
        };

        $selection = function (string $field, string $message) use (&$rules, &$messages): void {
            $rules["values.{$field}"] = ['bail', 'required', 'regex:/^[1-9]\d*$/'];
            $messages["values.{$field}.required"] = $message;
            $messages["values.{$field}.regex"] = $message;
        };

        $email = AuthRules::EMAIL_PATTERN;
        $invalidEmail = 'Enter a valid email address.';

        $required('companyName', 'Company name', 150);
        $required('address', 'Company address', 255);
        $selection('cityId', 'Please select a city.');
        $selection('provinceId', 'Please select a state/province.');
        $selection('countryId', 'Please select a country/region.');
        $required('zipCode', 'Zip/Postal code', 10, self::POSTAL_CODE_PATTERN, 'Enter a valid zip/postal code.');
        $required('email', 'Email address', 150, $email, $invalidEmail);
        $optional('taxIdNumber', 'Tax identification number', 30);
        $optional('phoneNumber', 'Phone number', 30, ['regex:'.self::PHONE_PATTERN], 'Enter a valid phone number.');
        $optional('companyEmail', 'Company email address', 150, ["regex:{$email}"], $invalidEmail);
        $optional('website', 'Company page/site', 255, [function (string $attribute, mixed $value, \Closure $fail): void {
            if (! self::isWebsite((string) $value)) {
                $fail('Enter a valid website address.');
            }
        }]);
        $required('headOfCompany', 'Head of company', 150);
        $required('pointPersonFirstName', 'Firstname', 75);
        $required('pointPersonLastName', 'Lastname', 75);
        $required('pointPersonContact', 'Contact number', 30, self::PHONE_PATTERN, 'Enter a valid contact number.');
        $required('pointPersonEmail', 'Email address', 150, $email, $invalidEmail);
        $optional('employeeNumber', 'Employee number', 50);
        $optional('employeeTitle', 'Employee title/position', 100);

        return ['rules' => $rules, 'messages' => $messages];
    }

    public static function isWebsite(string $value): bool
    {
        $url = preg_match('/^https?:\/\//i', $value) ? $value : "https://{$value}";
        $host = parse_url($url, PHP_URL_HOST);

        return filter_var($url, FILTER_VALIDATE_URL) !== false && is_string($host) && str_contains($host, '.');
    }

    /**
     * Checks a field as soon as the user leaves it.
     */
    public function updatedValues(mixed $value, ?string $field = null): void
    {
        $this->status = null;

        if ($field === null || ! array_key_exists($field, self::FIELDS)) {
            return;
        }

        $this->values[$field] = trim((string) $value);

        match ($field) {
            'countryId' => $this->countryChanged($this->values[$field]),
            'provinceId' => $this->provinceChanged($this->values[$field]),
            'cityId' => $this->cityChanged($this->values[$field]),
            default => null,
        };

        $validation = $this->validation();
        $this->validateOnly("values.{$field}", $validation['rules'], $validation['messages']);
    }

    /* --------------------------- Location dropdowns -------------------------- */

    /**
     * @return array{countries: list<array{id: int, name: string}>, provinces: list<array{id: int, name: string, countryId: int}>, cities: list<array{id: int, name: string, provinceId: int}>}
     */
    #[Computed(persist: true)]
    public function locations(): array
    {
        return Locations::all();
    }

    /**
     * @return array<int, array{id: int, name: string, countryId?: int, provinceId?: int}>
     */
    private function byId(string $list): array
    {
        return collect($this->locations[$list])->keyBy('id')->all();
    }

    /**
     * A province from another country no longer fits, nor its city.
     */
    private function countryChanged(string $countryId): void
    {
        $province = $this->byId('provinces')[(int) $this->values['provinceId']] ?? null;

        if (! $province || (string) $province['countryId'] !== $countryId) {
            $this->values['provinceId'] = '';
            $this->values['cityId'] = '';
        }
    }

    /**
     * Picks the province's country, and drops a city from another province.
     */
    private function provinceChanged(string $provinceId): void
    {
        $province = $this->byId('provinces')[(int) $provinceId] ?? null;
        $city = $this->byId('cities')[(int) $this->values['cityId']] ?? null;

        if ($province) {
            $this->values['countryId'] = (string) $province['countryId'];
        }
        if (! $city || (string) $city['provinceId'] !== $provinceId) {
            $this->values['cityId'] = '';
        }
    }

    /**
     * Picks the city's province and country.
     */
    private function cityChanged(string $cityId): void
    {
        $city = $this->byId('cities')[(int) $cityId] ?? null;
        $province = $city ? ($this->byId('provinces')[$city['provinceId']] ?? null) : null;

        if ($province) {
            $this->values['provinceId'] = (string) $province['id'];
            $this->values['countryId'] = (string) $province['countryId'];
        }
    }

    /**
     * @return array<string, array<int|string, string>>
     */
    private function locationOptions(): array
    {
        $provinces = $this->byId('provinces');

        // Until a province is chosen, list every city with its province to disambiguate.
        $cities = collect($this->locations['cities'])
            ->filter(fn (array $city): bool => $this->values['provinceId'] === '' || (string) $city['provinceId'] === $this->values['provinceId'])
            ->mapWithKeys(fn (array $city): array => [$city['id'] => $this->values['provinceId'] !== ''
                ? $city['name']
                : $city['name'].', '.($provinces[$city['provinceId']]['name'] ?? '')]);

        return [
            'countries' => collect($this->locations['countries'])->pluck('name', 'id')->all(),
            'provinces' => collect($this->locations['provinces'])
                ->filter(fn (array $province): bool => $this->values['countryId'] === '' || (string) $province['countryId'] === $this->values['countryId'])
                ->pluck('name', 'id')->all(),
            'cities' => $cities->all(),
        ];
    }

    /* -------------------------------- Uploads -------------------------------- */

    public function updatedLogo(): void
    {
        $this->checkUpload('logo');
    }

    public function updatedIcon(): void
    {
        $this->checkUpload('icon');
    }

    /**
     * Rejects an unsupported, empty or oversized file as soon as it is picked.
     */
    private function checkUpload(string $kind): void
    {
        $this->status = null;
        $this->resetErrorBag($kind);

        $error = $this->uploadError($kind, $this->{$kind});
        if ($error !== null) {
            $this->{$kind} = null;
            $this->addError($kind, $error);

            return;
        }

        $this->{$kind === 'logo' ? 'removeLogo' : 'removeIcon'} = false;
    }

    private function uploadError(string $kind, ?TemporaryUploadedFile $file): ?string
    {
        if ($file === null) {
            return null;
        }

        $rule = self::UPLOAD_RULES[$kind];
        $name = $file->getClientOriginalName();
        $type = (string) $file->getClientMimeType();
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        // Browsers report .ico inconsistently, so a generic type is allowed as long as the extension matches.
        $typeOk = $type === '' || $type === 'application/octet-stream' || in_array($type, $rule['mimeTypes'], true);

        if (! in_array($extension, $rule['extensions'], true) || ! $typeOk) {
            return "\"{$name}\" is not a supported format. Use {$rule['formats']}.";
        }
        if ($file->getSize() === 0) {
            return "\"{$name}\" is empty.";
        }
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            $sizeMb = number_format($file->getSize() / (1024 * 1024), 1);

            return "\"{$name}\" is {$sizeMb}MB. The file size must not exceed 3MB.";
        }

        $detected = ImageType::detect((string) file_get_contents($file->getRealPath()));
        if ($detected === null || ! in_array($detected, $rule['mimeTypes'], true)) {
            return "\"{$name}\" is not a valid {$rule['formats']} image.";
        }

        return null;
    }

    public function clearAsset(string $kind): void
    {
        if (! array_key_exists($kind, self::UPLOAD_RULES)) {
            return;
        }

        $this->status = null;
        $this->{$kind} = null;
        $this->resetErrorBag($kind);
        $this->{$kind === 'logo' ? 'removeLogo' : 'removeIcon'} = $this->user()->company?->{"{$kind}_file_name"} !== null;
    }

    /* --------------------------------- Save ---------------------------------- */

    public function save(): void
    {
        $user = $this->user();

        // Creating the profile is always allowed; changing it needs Edit access to Company Setup.
        if ($user->hasCompany() && ! $user->canAccessPage(Page::CompanySetup, Access::Edit)) {
            $this->status = ['type' => 'error', 'message' => Access::Edit->deniedMessage()];

            return;
        }

        $this->values = array_map(fn ($value): string => trim((string) $value), $this->values);

        $validation = $this->validation();
        $validator = validator(['values' => $this->values], $validation['rules'], $validation['messages']);

        $errors = $validator->errors()->toArray();
        foreach (['logo', 'icon'] as $kind) {
            if ($error = $this->uploadError($kind, $this->{$kind})) {
                $errors[$kind] = [$error];
            }
        }

        if ($errors === []) {
            $location = Locations::check(
                (int) $this->values['cityId'], (int) $this->values['provinceId'], (int) $this->values['countryId'],
                'Please select a valid city.',
            );
            foreach ($location as $suffix => $message) {
                $errors['values.'.lcfirst($suffix)] = [$message];
            }
        }

        if ($errors !== []) {
            $this->resetErrorBag();
            foreach ($errors as $key => $messages) {
                $this->addError($key, $messages[0]);
            }
            $this->status = ['type' => 'error', 'message' => 'Please correct the highlighted fields.'];
            $this->dispatch('focus-first-error');

            return;
        }

        $company = $this->persist($user);

        $this->resetErrorBag();
        $this->reset('logo', 'icon', 'removeLogo', 'removeIcon');

        if ($this->onboarding) {
            session()->flash('flash', ['message' => 'Company profile saved.', 'tone' => 'success']);
            $this->redirectRoute('dashboard');

            return;
        }

        $this->fillValues($company);
        $this->status = ['type' => 'success', 'message' => 'Company profile saved.'];
    }

    /**
     * Updates the user's company, or creates it when the user has none yet; whoever creates
     * a company owns it and becomes a member of it.
     */
    private function persist(User $user): Company
    {
        $attributes = [];
        foreach (self::FIELDS as $field => $column) {
            $value = $this->values[$field];
            $attributes[$column] = str_ends_with($field, 'Id') ? (int) $value : ($value === '' ? null : $value);
        }

        foreach (['logo' => $this->removeLogo, 'icon' => $this->removeIcon] as $kind => $remove) {
            /** @var TemporaryUploadedFile|null $file */
            $file = $this->{$kind};

            if ($file !== null) {
                $data = (string) file_get_contents($file->getRealPath());
                $attributes["{$kind}_data"] = $data;
                $attributes["{$kind}_mime_type"] = ImageType::detect($data);
                $attributes["{$kind}_file_name"] = mb_substr($file->getClientOriginalName(), 0, 255);
            } elseif ($remove) {
                $attributes["{$kind}_data"] = null;
                $attributes["{$kind}_mime_type"] = null;
                $attributes["{$kind}_file_name"] = null;
            }
        }

        if ($user->hasCompany()) {
            $company = $user->company;
            $company->update($attributes);

            return $company;
        }

        return DB::transaction(function () use ($user, $attributes): Company {
            $company = Company::query()->create([...$attributes, 'user_id' => $user->id]);
            $user->forceFill(['company_id' => $company->id])->save();

            return $company;
        });
    }

    /* -------------------------------- Render --------------------------------- */

    /**
     * What each upload box shows: the new file's preview, or the saved file unless it is being removed.
     *
     * @return array<string, array{url: ?string, fileName: ?string}>
     */
    private function assetPreviews(): array
    {
        $company = $this->user()->company;
        $previews = [];

        foreach (['logo' => $this->removeLogo, 'icon' => $this->removeIcon] as $kind => $removed) {
            /** @var TemporaryUploadedFile|null $file */
            $file = $this->{$kind};

            $previews[$kind] = match (true) {
                $file !== null => ['url' => $file->temporaryUrl(), 'fileName' => $file->getClientOriginalName()],
                ! $removed && $company?->{"{$kind}_file_name"} !== null => [
                    'url' => $company->assetUrl($kind), 'fileName' => $company->{"{$kind}_file_name"},
                ],
                default => ['url' => null, 'fileName' => null],
            };
        }

        return $previews;
    }

    public function render(): View
    {
        return view('livewire.company.profile-form', [
            'options' => $this->locationOptions(),
            'previews' => $this->assetPreviews(),
            'uploadRules' => self::UPLOAD_RULES,
        ]);
    }
}
