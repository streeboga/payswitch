<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Enums;

/**
 * Fiscal receipt status. Independent of PaymentStatus: the PSP taking the money does not
 * mean the receipt reached the tax office (PSW-12).
 */
enum ReceiptStatus: string
{
    /** Handed to the PSP with the payment; nothing known yet. */
    case Pending = 'pending';
    case Registered = 'registered';
    case Failed = 'failed';

    public function canBecome(self $to): bool
    {
        return match ($this) {
            self::Pending => $to !== self::Pending,
            // A failed receipt may still be registered after a retry; a registered one is final.
            self::Failed => $to === self::Registered,
            self::Registered => false,
        };
    }
}
