<?php

declare(strict_types=1);

namespace App\DataTransferObjects\PaymentMethod;

use Spatie\LaravelData\Data;

final class CreatePaymentMethodData extends Data
{
    public function __construct(
        public readonly string $type,
        public readonly string $connector_name,
        public readonly ?string $card_number = null,
        public readonly ?string $card_last4 = null,
        public readonly ?string $card_brand = null,
        public readonly ?int $card_exp_month = null,
        public readonly ?int $card_exp_year = null,
        public readonly ?string $card_holder_name = null,
        public readonly ?string $connector_token = null,
        public readonly bool $is_default = false,
        public readonly ?array $metadata = null,
    ) {}
}
