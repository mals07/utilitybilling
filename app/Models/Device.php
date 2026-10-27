<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A phone registered to a meter reader, signed in to the API with its own token. Only the SHA-256
 * hash of the token is stored.
 */
#[Fillable([
    'company_id', 'user_id', 'name', 'token_hash', 'token_expires_at', 'app_version', 'last_seen_at',
    'last_synced_at', 'revoked_at', 'revoked_by_id',
])]
#[Hidden(['token_hash'])]
class Device extends Model
{
    use BelongsToCompany;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The meter reader the device belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<InvoiceNumberBlock, $this>
     */
    public function numberBlocks(): HasMany
    {
        return $this->hasMany(InvoiceNumberBlock::class);
    }

    /**
     * @return HasMany<RoutePackage, $this>
     */
    public function routePackages(): HasMany
    {
        return $this->hasMany(RoutePackage::class);
    }

    /**
     * @return HasMany<SyncItem, $this>
     */
    public function syncItems(): HasMany
    {
        return $this->hasMany(SyncItem::class);
    }
}
