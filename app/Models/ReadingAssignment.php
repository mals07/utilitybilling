<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The meter reader who reads a cluster's meters in a billing period, with the meter reader app.
 */
#[Fillable(['company_id', 'billing_period', 'cluster_type_id', 'user_id', 'assigned_by_id'])]
class ReadingAssignment extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<ClusterType, $this>
     */
    public function clusterType(): BelongsTo
    {
        return $this->belongsTo(ClusterType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
