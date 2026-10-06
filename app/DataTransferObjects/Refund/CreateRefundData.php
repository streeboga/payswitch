<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Refund;

use Spatie\LaravelData\Data;

final class CreateRefundData extends Data
{
    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>|null  $receipt  Состав чека возврата; не хранится, уходит провайдеру.
     */
    public function __construct(
        public readonly string $payment_id,
        public readonly int $amount,
        public readonly ?string $reason = null,
        public readonly ?array $metadata = null,
        public readonly ?string $idempotency_key = null,
        public readonly ?array $receipt = null,
    ) {}
}
