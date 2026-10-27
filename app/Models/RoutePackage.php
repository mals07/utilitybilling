<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a device downloaded to read its route offline: when, the billing settings it billed with,
 * and the version of each customer at the time, so a sync can tell what changed since.
 */
#[Fillable(['company_id', 'device_id', 'billing_period', 'as_of', 'config_hash', 'customer_versions'])]
class RoutePackage extends Model
{
    use BelongsToCompany;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'as_of' => 'datetime',
            'customer_versions' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
