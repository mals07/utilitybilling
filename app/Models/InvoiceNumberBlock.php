<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Invoice numbers reserved from the company's invoice sequence for a device to print bills with
 * offline. Numbers are stored as integers; invoices show them padded to six digits.
 */
#[Fillable(['company_id', 'device_id', 'route_package_id', 'first_number', 'last_number', 'closed_at'])]
class InvoiceNumberBlock extends Model
{
    use BelongsToCompany;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_number' => 'integer',
            'last_number' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    public function contains(int $number): bool
    {
        return $number >= $this->first_number && $number <= $this->last_number;
    }

    /**
     * "004127"
     */
    public static function format(int $number): string
    {
        return str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return HasMany<InvoiceNumberGap, $this>
     */
    public function gaps(): HasMany
    {
        return $this->hasMany(InvoiceNumberGap::class);
    }
}
