<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\Payout\PayoutAuthorization;
use App\Models\Payout;

interface PayoutAuthorizationVerifier
{
    /** Verify Genesis authorization and live reserve against every immutable payout field. */
    public function verify(Payout $payout): PayoutAuthorization;
}
