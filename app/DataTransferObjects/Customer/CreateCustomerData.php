<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Customer;

use Spatie\LaravelData\Data;

final class CreateCustomerData extends Data
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $phone_country_code = null,
        public readonly ?string $description = null,
        public readonly ?array $metadata = null,
    ) {}
}
