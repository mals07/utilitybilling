<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Models\Concerns\DefersBinaryColumns;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id', 'customer_import_id', 'account_number', 'first_name', 'last_name', 'company_name', 'email', 'phone_number', 'status',
    'disconnection_exempt_reason', 'disconnection_exempt_until', 'disconnection_exempted_by_id',
    'account_type_id', 'charge_type_id', 'meter_type_id', 'rate_type_id', 'cluster_type_id',
    'meter_number', 'meter_serial_number', 'meter_installed_on',
    'home_address', 'home_city_id', 'home_province_id', 'home_country_id', 'home_zip_code',
    'billing_address', 'billing_city_id', 'billing_province_id', 'billing_country_id', 'billing_zip_code',
    'latitude', 'longitude', 'photo_data', 'photo_mime_type', 'photo_file_name',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use DefersBinaryColumns, HasFactory;

    /**
     * @return list<string>
     */
    public function binaryColumns(): array
    {
        return ['photo_data'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'meter_installed_on' => 'date',
            'disconnection_exempt_until' => 'date',
        ];
    }

    /**
     * Ids of the company's customers with the text in any of the columns, for whereIn() on a list of
     * their invoices, payments or readings. The customers are searched once, instead of once per row
     * of the list as whereHas() does.
     *
     * @param  list<string>  $columns
     * @return Builder<Customer>
     */
    public static function idsMatching(int $companyId, string $search, array $columns = ['account_number', 'first_name', 'last_name']): Builder
    {
        return self::query()
            ->select('customers.id')
            ->where('company_id', $companyId)
            ->where(function (Builder $query) use ($columns, $search): void {
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', "%{$search}%");
                }
            });
    }

    /**
     * Has a meter installed, set by completing a New Installation or Meter Replacement.
     */
    public function hasMeter(): bool
    {
        return $this->meter_number !== null;
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
     * @return HasMany<DisconnectionNotice, $this>
     */
    public function disconnectionNotices(): HasMany
    {
        return $this->hasMany(DisconnectionNotice::class);
    }

    /**
     * Who marked the customer exempt from disconnection.
     *
     * @return BelongsTo<User, $this>
     */
    public function disconnectionExemptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disconnection_exempted_by_id');
    }

    /**
     * Exempt from disconnection for non-payment on the given day ("YYYY-MM-DD" in the company's time zone).
     */
    public function isExemptFromDisconnection(string $today): bool
    {
        return $this->disconnection_exempt_reason !== null
            && ($this->disconnection_exempt_until === null || $this->disconnection_exempt_until->toDateString() >= $today);
    }

    /**
     * Where bills go: the billing address when there is one, otherwise the home address.
     */
    public function mailingAddress(): string
    {
        $prefix = $this->billing_address !== null ? 'billing' : 'home';

        return collect([
            $this->{"{$prefix}_address"},
            City::query()->whereKey($this->{"{$prefix}_city_id"})->value('name'),
            Province::query()->whereKey($this->{"{$prefix}_province_id"})->value('name'),
            $this->{"{$prefix}_zip_code"},
        ])->filter()->implode(', ');
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The import that added the customer, if they came from a file.
     *
     * @return BelongsTo<CustomerImport, $this>
     */
    public function customerImport(): BelongsTo
    {
        return $this->belongsTo(CustomerImport::class);
    }

    /**
     * @return BelongsTo<AccountType, $this>
     */
    public function accountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class)->withTrashed();
    }

    /**
     * @return BelongsTo<MeterType, $this>
     */
    public function meterType(): BelongsTo
    {
        return $this->belongsTo(MeterType::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ClusterType, $this>
     */
    public function clusterType(): BelongsTo
    {
        return $this->belongsTo(ClusterType::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ChargeType, $this>
     */
    public function chargeType(): BelongsTo
    {
        return $this->belongsTo(ChargeType::class);
    }

    /**
     * @return BelongsTo<RateType, $this>
     */
    public function rateType(): BelongsTo
    {
        return $this->belongsTo(RateType::class);
    }

    /**
     * URL of the stored photo, versioned so a new upload isn't served from cache.
     */
    public function photoUrl(): ?string
    {
        if ($this->photo_file_name === null) {
            return null;
        }

        return route('customers.photo', ['customer' => $this->id, 'v' => $this->updated_at?->getTimestamp()]);
    }
}
