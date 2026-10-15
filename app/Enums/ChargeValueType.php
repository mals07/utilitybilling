<?php

namespace App\Enums;

use App\Support\Format;

enum ChargeValueType: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';

    /**
     * Highest value a charge type element may hold: 100 for a percentage, the limit of DECIMAL(12,2) otherwise.
     */
    public function maxValue(): float
    {
        return $this === self::Percentage ? 100 : 9999999999.99;
    }

    /**
     * "150.00" for a fixed amount, "12.00%" for a percentage.
     */
    public function format(string|float $value): string
    {
        return $this === self::Percentage ? Format::amount($value).'%' : Format::amount($value);
    }
}
