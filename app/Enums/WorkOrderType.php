<?php

namespace App\Enums;

/**
 * Kinds of field work a water utility sends staff out for, in the order shown in the Order Type list.
 */
enum WorkOrderType: string
{
    case NewInstallation = 'new_installation';
    case Reconnection = 'reconnection';
    case Disconnection = 'disconnection';
    case MeterReplacement = 'meter_replacement';
    case MeterRepair = 'meter_repair';
    case MeterRelocation = 'meter_relocation';
    case MeterReadingVerification = 'meter_reading_verification';
    case LeakRepair = 'leak_repair';
    case PipeRepair = 'pipe_repair';
    case LowWaterPressure = 'low_water_pressure';
    case WaterQualityComplaint = 'water_quality_complaint';
    case BillingComplaint = 'billing_complaint';
    case ChangeOfOwnership = 'change_of_ownership';

    public function label(): string
    {
        return match ($this) {
            self::NewInstallation => 'New Installation',
            self::Reconnection => 'Reconnection',
            self::Disconnection => 'Disconnection',
            self::MeterReplacement => 'Meter Replacement',
            self::MeterRepair => 'Meter Repair',
            self::MeterRelocation => 'Meter Relocation',
            self::MeterReadingVerification => 'Meter Re-reading / Verification',
            self::LeakRepair => 'Leak Repair',
            self::PipeRepair => 'Pipe Repair',
            self::LowWaterPressure => 'Low Water Pressure',
            self::WaterQualityComplaint => 'Water Quality Complaint',
            self::BillingComplaint => 'Billing Complaint',
            self::ChangeOfOwnership => 'Change of Ownership',
        };
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])->all();
    }
}
