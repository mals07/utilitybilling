<?php

namespace App\Models;

use App\Enums\DiscrepancySeverity;
use App\Enums\SyncCheck;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the server found different from what a device recorded, for one uploaded item.
 */
#[Fillable(['company_id', 'sync_item_id', 'check', 'severity', 'message', 'device_value', 'server_value'])]
class SyncDiscrepancy extends Model
{
    use BelongsToCompany;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check' => SyncCheck::class,
            'severity' => DiscrepancySeverity::class,
        ];
    }

    /**
     * @return BelongsTo<SyncItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(SyncItem::class, 'sync_item_id');
    }
}
