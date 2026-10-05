<?php

declare(strict_types=1);

namespace App\Services\Payout;

use App\Contracts\PayoutAuthorizationVerifier;
use App\DataTransferObjects\Payout\PayoutAuthorization;
use App\Models\Payout;
use Streeboga\PaymentData\Exceptions\PaymentException;

final readonly class DisabledPayoutAuthorizationVerifier implements PayoutAuthorizationVerifier
{
    public function verify(Payout $payout): PayoutAuthorization
    {
        throw new PaymentException('Genesis authorization/reserve verification is not configured', 'payout_authorization_unavailable', 'invalid_request_error', 409);
    }
}
