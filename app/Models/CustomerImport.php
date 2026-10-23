<?php

namespace App\Models;

use Database\Factories\CustomerImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One batch of customers imported from an Excel or CSV file, kept as a record of who imported what.
 */
#[Fillable([
    'company_id', 'user_id', 'file_name', 'imported_count', 'skipped_count',
    'first_account_number', 'last_account_number', 'skipped_rows',
])]
class CustomerImport extends Model
{
    /** @use HasFactory<CustomerImportFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'imported_count' => 'integer',
            'skipped_count' => 'integer',
            'skipped_rows' => 'array',
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
     * Who ran the import.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * "000121–000168", or the single number when one customer was imported.
     */
    public function accountNumberRange(): ?string
    {
        if ($this->first_account_number === null) {
            return null;
        }

        return $this->first_account_number === $this->last_account_number
            ? $this->first_account_number
            : "{$this->first_account_number}–{$this->last_account_number}";
    }
}
