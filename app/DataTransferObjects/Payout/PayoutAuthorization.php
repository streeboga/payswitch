<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Payout;

final readonly class PayoutAuthorization
{
    public function __construct(
        public string $authorizationReference,
        public string $reserveReference,
        public string $approvedBy,
    ) {}
}
