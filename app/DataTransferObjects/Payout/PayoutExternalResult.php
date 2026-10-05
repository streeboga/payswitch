<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Payout;

use App\Enums\PayoutStatus;
use Carbon\CarbonImmutable;

final readonly class PayoutExternalResult
{
    public function __construct(
        public string $eventId,
        public string $attemptKey,
        public PayoutStatus $status,
        public string $providerReference,
        public int $amount,
        public string $currency,
        public string $recipientVersionKey,
        public int $precision,
        public string $evidenceReference,
        public CarbonImmutable $occurredAt,
        public ?string $relatedReference = null,
        public bool $executionAbsent = false,
    ) {}
}
