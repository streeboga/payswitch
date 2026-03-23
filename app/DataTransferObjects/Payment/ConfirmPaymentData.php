<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Payment;

use Spatie\LaravelData\Data;

final class ConfirmPaymentData extends Data
{
    /**
     * @param  array<string, mixed>  $payment_method_data
     */
    public function __construct(
        public readonly ?string $payment_method = null,
        public readonly array $payment_method_data = [],
        public readonly ?string $connector = null,
        public readonly ?string $payment_method_id = null,
    ) {}
}
