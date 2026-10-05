<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\Payout\PayoutExternalResult;
use App\Models\Payout;
use App\Models\PayoutAttempt;
use App\Models\PayoutRecipientVersion;

interface PayoutChannel
{
    /** Must check contract capability, currency/precision, amount and recipient readiness. */
    public function assertSupported(Payout $payout, PayoutRecipientVersion $recipient): void;

    public function execute(Payout $payout, PayoutAttempt $attempt, PayoutRecipientVersion $recipient): ?PayoutExternalResult;

    /** Read the same attempt, never create or send a new transfer. Verify bank evidence here. */
    public function sync(Payout $payout, PayoutAttempt $attempt): ?PayoutExternalResult;
}
