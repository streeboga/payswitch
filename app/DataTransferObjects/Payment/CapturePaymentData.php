<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Payment;

use Spatie\LaravelData\Data;

final class CapturePaymentData extends Data
{
    public function __construct(
        public readonly int $amount_to_capture,
    ) {}
}
