<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Refund;

use Spatie\LaravelData\Data;

final class CreateRefundData extends Data
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public readonly string $payment_id,
        public readonly int $amount,
        public readonly ?string $reason = null,
        public readonly ?array $metadata = null,
    ) {}
}
