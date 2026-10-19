<?php

namespace App\Models;

use App\Models\Concerns\DefersBinaryColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof photo added when completing a work order, e.g. the installed or sealed meter.
 */
#[Fillable(['work_order_id', 'photo_data', 'mime_type', 'file_name', 'uploaded_by_id'])]
class WorkOrderPhoto extends Model
{
    use DefersBinaryColumns;

    public const UPDATED_AT = null;

    /**
     * @return list<string>
     */
    public function binaryColumns(): array
    {
        return ['photo_data'];
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function url(): string
    {
        return route('work-orders.photo', ['photo' => $this->id]);
    }
}
