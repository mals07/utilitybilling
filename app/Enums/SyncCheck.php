<?php

namespace App\Enums;

/**
 * The checks the server runs on a reading uploaded from a device (App\Support\Reader\SyncValidator).
 */
enum SyncCheck: string
{
    case Assignment = 'assignment';
    case InvoiceNumber = 'invoice_number';
    case DuplicateReading = 'duplicate_reading';
    case MeterChanged = 'meter_changed';
    case NotBillable = 'not_billable';
    case History = 'history';
    case Config = 'config';
    case Amount = 'amount';
    case PreviousBalance = 'previous_balance';
    case Clock = 'clock';
    case Location = 'location';
    case Flags = 'flags';
    case Billing = 'billing';
    case DeviceSignedOut = 'device_signed_out';

    public function label(): string
    {
        return match ($this) {
            self::DeviceSignedOut => 'Device signed out',
            self::Assignment => 'Not assigned to the reader',
            self::InvoiceNumber => 'Invoice number',
            self::DuplicateReading => 'Already read or billed',
            self::MeterChanged => 'Meter changed',
            self::NotBillable => 'Customer not billable',
            self::History => 'Previous reading changed',
            self::Config => 'Rates or charges changed',
            self::Amount => 'Amount differs',
            self::PreviousBalance => 'Previous balance differs',
            self::Clock => 'Device clock',
            self::Location => 'Far from the meter',
            self::Flags => 'Review flags differ',
            self::Billing => 'Can\'t be billed',
        };
    }
}
