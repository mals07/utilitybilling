<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Models\Concerns\DefersBinaryColumns;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'account_number', 'first_name', 'last_name', 'company_name', 'email', 'phone_number', 'status',
    'account_type_id', 'charge_type_id', 'meter_type_id', 'rate_type_id', 'cluster_type_id',
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
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
