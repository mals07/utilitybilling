<?php

namespace App\Models;

use App\Enums\OverdueHandling;
use App\Models\Concerns\DefersBinaryColumns;
use App\Support\Format;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company profile. Each account owns exactly one, created during onboarding.
 */
#[Fillable([
    'user_id', 'company_name', 'address', 'city_id', 'province_id', 'country_id', 'zip_code', 'email',
    'tax_id_number', 'phone_number', 'company_email', 'website',
    'logo_data', 'logo_mime_type', 'logo_file_name', 'icon_data', 'icon_mime_type', 'icon_file_name',
    'head_of_company', 'point_person_first_name', 'point_person_last_name', 'point_person_contact',
    'point_person_email', 'employee_number', 'employee_title',
    'company_header_line', 'billing_header_1', 'billing_header_2', 'billing_header_3',
    'billing_footer_1', 'billing_footer_2', 'billing_footer_3', 'due_date_days', 'currency', 'timezone',
    'work_order_assignee_role_id', 'overdue_handling',
    'tax_enabled', 'tax_name', 'tax_rate', 'tax_inclusive', 'tax_on_water', 'tax_on_fees', 'tax_on_surcharges',
    'review_usage_multiplier', 'review_zero_usage',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use DefersBinaryColumns, HasFactory;

    protected $table = 'company_profiles';

    /**
     * @return list<string>
     */
    public function binaryColumns(): array
    {
        return ['logo_data', 'icon_data'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date_days' => 'integer',
            'overdue_handling' => OverdueHandling::class,
            'tax_enabled' => 'boolean',
            'tax_rate' => 'decimal:2',
            'tax_inclusive' => 'boolean',
            'tax_on_water' => 'boolean',
            'tax_on_fees' => 'boolean',
            'tax_on_surcharges' => 'boolean',
            'review_usage_multiplier' => 'decimal:1',
            'review_zero_usage' => 'boolean',
        ];
    }

    /**
     * The owner: whoever signed up and created the company.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Everyone who works in the company, the owner included.
     *
     * @return HasMany<User, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return HasMany<AccountType, $this>
     */
    public function accountTypes(): HasMany
    {
        return $this->hasMany(AccountType::class);
    }

    /**
     * @return HasMany<MeterType, $this>
     */
    public function meterTypes(): HasMany
    {
        return $this->hasMany(MeterType::class);
    }

    /**
     * @return HasMany<ClusterType, $this>
     */
    public function clusterTypes(): HasMany
    {
        return $this->hasMany(ClusterType::class);
    }

    /**
     * @return HasMany<Department, $this>
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * @return HasMany<RateType, $this>
     */
    public function rateTypes(): HasMany
    {
        return $this->hasMany(RateType::class);
    }

    /**
     * @return HasMany<ChargeType, $this>
     */
    public function chargeTypes(): HasMany
    {
        return $this->hasMany(ChargeType::class);
    }

    /**
     * @return HasMany<ChargeElement, $this>
     */
    public function chargeElements(): HasMany
    {
        return $this->hasMany(ChargeElement::class);
    }

    /**
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * @return HasMany<WorkOrder, $this>
     */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<MeterReading, $this>
     */
    public function meterReadings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    /**
     * @return HasMany<WorkOrderFee, $this>
     */
    public function workOrderFees(): HasMany
    {
        return $this->hasMany(WorkOrderFee::class);
    }

    /**
     * The role whose users are offered in a work order's Assigned To field; null offers every active user.
     *
     * @return BelongsTo<Role, $this>
     */
    public function workOrderAssigneeRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'work_order_assignee_role_id');
    }

    /**
     * URL of the stored logo or icon, versioned so a new upload isn't served from cache.
     */
    public function assetUrl(string $kind): ?string
    {
        if ($this->{$kind.'_file_name'} === null) {
            return null;
        }

        return route('company.asset', ['asset' => $kind, 'v' => $this->updated_at?->getTimestamp()]);
    }

    /**
     * Today's date as "YYYY-MM-DD" in the company's time zone.
     */
    public function today(): string
    {
        return Format::todayIn($this->timezone);
    }

    /**
     * "Address, City, Province, Country".
     */
    public function location(): string
    {
        return collect([$this->address, $this->city?->name, $this->province?->name, $this->country?->name])
            ->filter()
            ->implode(', ');
    }
}
