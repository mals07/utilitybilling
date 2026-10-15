<?php

namespace App\Enums;

enum DistributionType: string
{
    case Credit = 'credit';
    case Debit = 'debit';

    /**
     * "Credit" / "Debit"
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }
}
