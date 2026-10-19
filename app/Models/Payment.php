<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\NumberedPerCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money received from a customer, with its official receipt number. Applied to the customer's oldest
 * unpaid bills; what is left over (unapplied_amount) is advance credit for later bills.
 */
#[Fillable([
    'company_id', 'receipt_number', 'customer_id', 'paid_on', 'amount', 'unapplied_amount', 'method',
    'reference_number', 'notes', 'received_by_id', 'void_reason', 'voided_at', 'voided_by_id',
])]
class Payment extends Model
{
    use NumberedPerCompany;

    public const NUMBER_COLUMN = 'receipt_number';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount' => 'decimal:2',
            'unapplied_amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'voided_at' => 'datetime',
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_id');
    }

    public function isVoid(): bool
    {
        return $this->voided_at !== null;
    }
}
