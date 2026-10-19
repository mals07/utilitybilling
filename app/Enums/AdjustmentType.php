<?php

namespace App\Enums;

enum AdjustmentType: string
{
    /** Lowers what the customer owes on the invoice. */
    case Credit = 'credit';
    /** Adds to what the customer owes on the invoice. */
    case Debit = 'debit';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Credit Memo',
            self::Debit => 'Debit Memo',
        };
    }

    /**
     * "CM" or "DM", printed before the memo number.
     */
    public function prefix(): string
    {
        return $this === self::Credit ? 'CM' : 'DM';
    }

    /**
     * +1 for a debit memo, -1 for a credit memo.
     */
    public function sign(): int
    {
        return $this === self::Debit ? 1 : -1;
    }

    /**
     * Lowering what is owed needs Delete access; adding to it needs Edit access.
     */
    public function requiredAccess(): Access
    {
        return $this === self::Credit ? Access::Delete : Access::Edit;
    }
}
