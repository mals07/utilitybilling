<?php

namespace App\Models;

use App\Enums\DiscrepancySeverity;
use App\Enums\SyncItemStatus;
use App\Enums\SyncItemType;
use App\Enums\SyncResolution;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reading (with the bill printed for it) or a skipped invoice number uploaded from a device. The
 * payload is kept as the device sent it; App\Support\Reader\SyncItems lists its fields.
 */
#[Fillable([
    'company_id', 'sync_batch_id', 'device_id', 'client_uuid', 'type', 'customer_id', 'billing_period',
    'invoice_number', 'payload', 'status', 'resolution', 'resolution_note', 'resolved_by_id', 'resolved_at',
    'meter_reading_id', 'invoice_id',
])]
class SyncItem extends Model
{
    use BelongsToCompany;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SyncItemType::class,
            'status' => SyncItemStatus::class,
            'resolution' => SyncResolution::class,
            'payload' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function isHeld(): bool
    {
        return $this->status === SyncItemStatus::Held;
    }

    /**
     * @return BelongsTo<SyncBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SyncBatch::class, 'sync_batch_id');
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /**
     * @return HasMany<SyncDiscrepancy, $this>
     */
    public function discrepancies(): HasMany
    {
        return $this->hasMany(SyncDiscrepancy::class);
    }

    /**
     * @return HasMany<SyncDiscrepancy, $this>
     */
    public function blockingDiscrepancies(): HasMany
    {
        return $this->discrepancies()->where('severity', DiscrepancySeverity::Blocking);
    }
}
