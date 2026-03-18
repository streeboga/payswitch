<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Payment;

use Spatie\LaravelData\Data;
use Streeboga\PaymentData\Enums\AuthenticationType;
use Streeboga\PaymentData\Enums\CaptureMethod;

final class CreatePaymentData extends Data
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
        public readonly CaptureMethod $capture_method = CaptureMethod::Automatic,
        public readonly AuthenticationType $authentication_type = AuthenticationType::NoThreeDs,
        public readonly ?string $customer_id = null,
        public readonly ?string $description = null,
        public readonly ?string $return_url = null,
        public readonly ?string $profile_id = null,
        public readonly ?array $metadata = null,
        public readonly ?int $session_expiry = null,
        public readonly bool $confirm = false,
        public readonly ?string $payment_method = null,
        public readonly ?array $payment_method_data = null,
        public readonly ?string $payment_id = null,
    ) {}
}
