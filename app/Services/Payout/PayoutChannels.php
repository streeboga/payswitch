<?php

declare(strict_types=1);

namespace App\Services\Payout;

use App\Contracts\PayoutChannel;
use Streeboga\PaymentData\Exceptions\PaymentException;

final readonly class PayoutChannels
{
    /** @param array<string, PayoutChannel> $channels */
    public function __construct(private array $channels = []) {}

    public function resolve(string $channel): PayoutChannel
    {
        return $this->channels[$channel] ?? throw new PaymentException(
            'Payout channel requires operator approval and an accepted adapter', 'payout_channel_unavailable', 'invalid_request_error', 409,
        );
    }
}
