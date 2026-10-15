<?php

namespace App\Livewire\Company;

use App\Enums\Access;
use App\Enums\Page;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Support\Format;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Billing headers and footers, due-date days, currency and time zone.
 */
class OperationSettingsForm extends Component
{
    use GuardsPageAccess;

    /**
     * Currencies a company can bill in, keyed by ISO 4217 code.
     */
    public const CURRENCIES = [
        'PHP' => 'PHP - Philippine Peso',
        'USD' => 'USD - US Dollar',
        'EUR' => 'EUR - Euro',
        'GBP' => 'GBP - British Pound',
        'JPY' => 'JPY - Japanese Yen',
        'SGD' => 'SGD - Singapore Dollar',
        'AUD' => 'AUD - Australian Dollar',
        'CAD' => 'CAD - Canadian Dollar',
    ];

    /**
     * Highest number of days a bill may be due after it is issued.
     */
    public const MAX_DUE_DATE_DAYS = 365;

    /**
     * Optional text fields: property => label used in messages.
     */
    public const TEXT_FIELDS = [
        'companyHeaderLine' => 'company header line',
        'billingHeader1' => 'billing header 1',
        'billingHeader2' => 'billing header 2',
        'billingHeader3' => 'billing header 3',
        'billingFooter1' => 'billing footer 1',
        'billingFooter2' => 'billing footer 2',
        'billingFooter3' => 'billing footer 3',
    ];

    public string $companyHeaderLine = '';

    public string $billingHeader1 = '';

    public string $billingHeader2 = '';

    public string $billingHeader3 = '';

    public string $billingFooter1 = '';

    public string $billingFooter2 = '';

    public string $billingFooter3 = '';

    public string $dueDateDays = '';

    public string $currency = '';

    public string $timezone = '';

    protected function page(): Page
    {
        return Page::CompanySetup;
    }

    public function mount(): void
    {
        $company = $this->company();

        foreach (array_keys(self::TEXT_FIELDS) as $field) {
            $this->{$field} = (string) $company->{self::column($field)};
        }
        $this->dueDateDays = (string) $company->due_date_days;
        $this->currency = (string) $company->currency;
        // Asia/Manila is preselected until a time zone is saved.
        $this->timezone = $company->timezone ?? Format::DEFAULT_TIMEZONE;
    }

    /**
     * "billingHeader1" → "billing_header_1"
     */
    private static function column(string $field): string
    {
        return strtolower((string) preg_replace('/(?<!^)([A-Z]|\d)/', '_$1', $field));
    }

    public function save(): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        foreach (array_keys(self::TEXT_FIELDS) as $field) {
            $this->{$field} = trim($this->{$field});
        }
        $this->dueDateDays = trim($this->dueDateDays);

        $rules = [
            'dueDateDays' => ['bail', 'required', 'regex:/^\d+$/', 'integer', 'max:'.self::MAX_DUE_DATE_DAYS],
            'currency' => ['bail', 'required', Rule::in(array_keys(self::CURRENCIES))],
            'timezone' => ['bail', 'required', Rule::in(DateTimeZone::listIdentifiers())],
        ];
        $messages = [
            'dueDateDays.required' => 'The default # of days for due date field is required.',
            'dueDateDays.regex' => 'The default # of days for due date must be a whole number.',
            'dueDateDays.integer' => 'The default # of days for due date must be a whole number.',
            'dueDateDays.max' => 'The default # of days for due date must not be greater than '.self::MAX_DUE_DATE_DAYS.'.',
            'currency.required' => 'The currency field is required.',
            'currency.in' => 'The selected currency is invalid.',
            'timezone.required' => 'The time zone field is required.',
            'timezone.in' => 'The time zone must be a valid time zone.',
        ];
        foreach (self::TEXT_FIELDS as $field => $label) {
            $rules[$field] = ['nullable', 'max:255'];
            $messages["{$field}.max"] = "The {$label} must not be greater than 255 characters.";
        }

        $this->validate($rules, $messages);

        $attributes = [
            'due_date_days' => (int) $this->dueDateDays,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
        ];
        foreach (array_keys(self::TEXT_FIELDS) as $field) {
            $attributes[self::column($field)] = $this->{$field} === '' ? null : $this->{$field};
        }

        $this->company()->update($attributes);

        $this->flash('Operation setup saved.');
    }

    public function render(): View
    {
        return view('livewire.company.operation-settings-form', [
            'timezones' => DateTimeZone::listIdentifiers(),
            'canEdit' => $this->access[Access::Edit->value],
        ]);
    }
}
