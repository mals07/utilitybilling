<?php

namespace App\Livewire\Company;

use App\Enums\Access;
use App\Enums\Page;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Support\Format;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Billing headers and footers, due-date days, currency, time zone, tax, and when meter readings need review.
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

    public const MAX_DISCONNECTION_OVERDUE_BILLS = 24;

    public const MAX_DISCONNECTION_NOTICE_DAYS = 60;

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

    public bool $taxEnabled = false;

    public string $taxName = 'VAT';

    public string $taxRate = '12.00';

    /**
     * "exclusive": the tax is added on top of rates and charges; "inclusive": they already contain it.
     */
    public string $taxMode = 'exclusive';

    public bool $taxOnWater = true;

    public bool $taxOnFees = false;

    public bool $taxOnSurcharges = false;

    public string $reviewUsageMultiplier = '2.0';

    public bool $reviewZeroUsage = true;

    public string $disconnectionOverdueBills = '2';

    public string $disconnectionNoticeDays = '5';

    /**
     * ISO weekdays (as strings) not counted as working days for a notice's deadline.
     *
     * @var list<string>
     */
    public array $disconnectionSkipDays = ['6', '7'];

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

        $this->taxEnabled = (bool) $company->tax_enabled;
        $this->taxName = (string) ($company->tax_name ?? 'VAT');
        $this->taxRate = (string) ($company->tax_rate ?? '12.00');
        $this->taxMode = $company->tax_inclusive ? 'inclusive' : 'exclusive';
        $this->taxOnWater = (bool) ($company->tax_on_water ?? true);
        $this->taxOnFees = (bool) $company->tax_on_fees;
        $this->taxOnSurcharges = (bool) $company->tax_on_surcharges;
        $this->reviewUsageMultiplier = (string) ($company->review_usage_multiplier ?? '2.0');
        $this->reviewZeroUsage = (bool) ($company->review_zero_usage ?? true);
        $this->disconnectionOverdueBills = (string) ($company->disconnection_overdue_bills ?? 2);
        $this->disconnectionNoticeDays = (string) ($company->disconnection_notice_days ?? 5);
        $this->disconnectionSkipDays = array_map('strval', $company->disconnectionSkipDays());
    }

    /**
     * "billingHeader1" → "billing_header_1"
     */
    private static function column(string $field): string
    {
        return strtolower((string) preg_replace('/(?<!^)([A-Z]|\d)/', '_$1', $field));
    }

    /**
     * The setup wizard's recommended settings; tax and review settings keep their defaults.
     */
    #[On('setup-example')]
    public function useSetupExample(): void
    {
        $this->currency = 'PHP';
        $this->timezone = 'Asia/Manila';
        $this->dueDateDays = '15';
        if (trim($this->companyHeaderLine) === '') {
            $this->companyHeaderLine = (string) $this->company()->company_name;
        }
        if (trim($this->billingFooter1) === '') {
            $this->billingFooter1 = 'Please pay on or before the due date to avoid surcharges.';
        }
        $this->resetValidation();
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
        $this->taxName = trim($this->taxName);
        $this->taxRate = trim($this->taxRate);
        $this->reviewUsageMultiplier = trim($this->reviewUsageMultiplier);
        $this->disconnectionOverdueBills = trim($this->disconnectionOverdueBills);
        $this->disconnectionNoticeDays = trim($this->disconnectionNoticeDays);

        $rules = [
            'dueDateDays' => ['bail', 'required', 'regex:/^\d+$/', 'integer', 'max:'.self::MAX_DUE_DATE_DAYS],
            'currency' => ['bail', 'required', Rule::in(array_keys(self::CURRENCIES))],
            'timezone' => ['bail', 'required', Rule::in(DateTimeZone::listIdentifiers())],
            'taxName' => ['bail', 'required', 'max:30'],
            'taxMode' => ['required', Rule::in(['exclusive', 'inclusive'])],
            'taxRate' => ['bail', 'required', 'regex:/^\d{1,3}(\.\d{1,2})?$/', 'numeric', 'min:0', 'max:100'],
            'reviewUsageMultiplier' => ['bail', 'required', 'regex:/^\d{1,2}(\.\d)?$/', 'numeric', 'min:0'],
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
            'taxName.required' => 'The tax name field is required.',
            'taxName.max' => 'The tax name must not be greater than 30 characters.',
            'taxMode.required' => 'Choose whether your rates include the tax.',
            'taxMode.in' => 'Choose whether your rates include the tax.',
            'taxRate.required' => 'The tax rate field is required.',
            'taxRate.regex' => 'The tax rate must be a number with up to 2 decimals, such as 12 or 12.5.',
            'taxRate.numeric' => 'The tax rate must be a number with up to 2 decimals, such as 12 or 12.5.',
            'taxRate.min' => 'The tax rate must not be negative.',
            'taxRate.max' => 'The tax rate must not be greater than 100%.',
            'reviewUsageMultiplier.required' => 'Enter how many times the average usage needs review (0 turns it off).',
            'reviewUsageMultiplier.regex' => 'Enter a number such as 2 or 2.5 (0 turns it off).',
            'reviewUsageMultiplier.numeric' => 'Enter a number such as 2 or 2.5 (0 turns it off).',
            'reviewUsageMultiplier.min' => 'Enter a number such as 2 or 2.5 (0 turns it off).',
            'disconnectionOverdueBills.required' => 'Enter how many overdue bills flag a customer for disconnection.',
            'disconnectionNoticeDays.required' => 'Enter how many working days a notice gives the customer to pay.',
            'disconnectionSkipDays.max' => 'Leave at least one working day in the week.',
            'disconnectionSkipDays.*.in' => 'Choose days of the week from the list.',
        ];
        $rules['disconnectionOverdueBills'] = ['bail', 'required', 'regex:/^\d+$/', 'integer', 'min:1', 'max:'.self::MAX_DISCONNECTION_OVERDUE_BILLS];
        $rules['disconnectionNoticeDays'] = ['bail', 'required', 'regex:/^\d+$/', 'integer', 'min:1', 'max:'.self::MAX_DISCONNECTION_NOTICE_DAYS];
        $rules['disconnectionSkipDays'] = ['array', 'max:6'];
        $rules['disconnectionSkipDays.*'] = [Rule::in(['1', '2', '3', '4', '5', '6', '7'])];
        foreach (['regex', 'integer', 'min', 'max'] as $rule) {
            $messages["disconnectionOverdueBills.{$rule}"] = 'Enter a whole number of overdue bills from 1 to '.self::MAX_DISCONNECTION_OVERDUE_BILLS.'.';
            $messages["disconnectionNoticeDays.{$rule}"] = 'Enter a whole number of working days from 1 to '.self::MAX_DISCONNECTION_NOTICE_DAYS.'.';
        }

        foreach (self::TEXT_FIELDS as $field => $label) {
            $rules[$field] = ['nullable', 'max:255'];
            $messages["{$field}.max"] = "The {$label} must not be greater than 255 characters.";
        }

        $this->validate($rules, $messages);

        $attributes = [
            'due_date_days' => (int) $this->dueDateDays,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'tax_enabled' => $this->taxEnabled,
            'tax_name' => $this->taxName,
            'tax_rate' => $this->taxRate,
            'tax_inclusive' => $this->taxMode === 'inclusive',
            'tax_on_water' => $this->taxOnWater,
            'tax_on_fees' => $this->taxOnFees,
            'tax_on_surcharges' => $this->taxOnSurcharges,
            'review_usage_multiplier' => $this->reviewUsageMultiplier,
            'review_zero_usage' => $this->reviewZeroUsage,
            'disconnection_overdue_bills' => (int) $this->disconnectionOverdueBills,
            'disconnection_notice_days' => (int) $this->disconnectionNoticeDays,
            'disconnection_skip_days' => collect($this->disconnectionSkipDays)->map(fn (string $day): int => (int) $day)->unique()->sort()->implode(','),
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
