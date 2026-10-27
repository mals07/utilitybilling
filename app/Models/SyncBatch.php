<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One upload from a device, sent in chunks. Nothing in it is applied until it is committed with
 * every item in.
 */
#[Fillable(['company_id', 'device_id', 'route_package_id', 'item_count', 'checksum', 'received_uuids', 'device_time', 'committed_at'])]
class SyncBatch extends Model
{
    use BelongsToCompany;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_count' => 'integer',
            'received_uuids' => 'array',
            'device_time' => 'datetime',
            'committed_at' => 'datetime',
        ];
    }

    public function isCommitted(): bool
    {
        return $this->committed_at !== null;
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<RoutePackage, $this>
     */
    public function routePackage(): BelongsTo
    {
        return $this->belongsTo(RoutePackage::class);
    }

    /**
     * @return HasMany<SyncItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SyncItem::class);
    }
}
