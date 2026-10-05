<?php

declare(strict_types=1);

namespace App\Enums;

enum PayoutStatus: string
{
    case RequiresApproval = 'requires_approval';
    case Ready = 'ready';
    case Processing = 'processing';
    case PendingConfirmation = 'pending_confirmation';
    case AwaitingBankSignature = 'awaiting_bank_signature';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';
}
