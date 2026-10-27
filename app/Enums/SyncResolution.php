<?php

namespace App\Enums;

/**
 * How a supervisor resolved an item held for review. The bill was already printed and handed to the
 * customer, so each says what becomes of it.
 */
enum SyncResolution: string
{
    /** Issue the invoice exactly as the device printed it. */
    case AcceptedAsPrinted = 'accepted';
    /** Issue the invoice with the server's figures, marked Corrected, to deliver a corrected bill. */
    case Rebilled = 'rebilled';
    /** Save nothing; the invoice number is accounted for as rejected and the meter is read again. */
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::AcceptedAsPrinted => 'Accepted as printed',
            self::Rebilled => 'Re-billed with server figures',
            self::Rejected => 'Rejected',
        };
    }
}
