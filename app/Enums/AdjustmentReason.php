<?php

namespace App\Enums;

/**
 * Why an invoice was adjusted. Each reason belongs to credit memos or debit memos (or both).
 */
enum AdjustmentReason: string
{
    case BillingError = 'billing_error';
    case MeterReadingError = 'meter_reading_error';
    case LeakAdjustment = 'leak_adjustment';
    case Rebate = 'rebate';
    case Undercharge = 'undercharge';
    case AdditionalCharge = 'additional_charge';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BillingError => 'Billing error',
            self::MeterReadingError => 'Meter reading error',
            self::LeakAdjustment => 'Leak adjustment',
            self::Rebate => 'Rebate / goodwill',
            self::Undercharge => 'Undercharge found',
            self::AdditionalCharge => 'Additional charge',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<self>
     */
    public static function for(AdjustmentType $type): array
    {
        return $type === AdjustmentType::Credit
            ? [self::BillingError, self::MeterReadingError, self::LeakAdjustment, self::Rebate, self::Other]
            : [self::Undercharge, self::MeterReadingError, self::AdditionalCharge, self::Other];
    }

    public function appliesTo(AdjustmentType $type): bool
    {
        return in_array($this, self::for($type), true);
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(AdjustmentType $type): array
    {
        return collect(self::for($type))->mapWithKeys(fn (self $reason): array => [$reason->value => $reason->label()])->all();
    }
}
