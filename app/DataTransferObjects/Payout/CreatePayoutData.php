<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Payout;

final readonly class CreatePayoutData
{
    public function __construct(
        public string $project_id,
        public string $operation_id,
        public string $settlement_id,
        public string $idempotency_key,
        public string $recipient_version_key,
        public string $atomic_amount,
        public string $currency,
        public int $precision,
        public string $channel,
        public string $purpose,
    ) {}

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
