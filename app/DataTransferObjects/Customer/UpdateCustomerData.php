<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Customer;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

final class UpdateCustomerData extends Data
{
    /**
     * @param  array<string, mixed>|Optional|null  $metadata
     */
    public function __construct(
        public readonly string|Optional $name = new Optional,
        public readonly string|Optional|null $email = new Optional,
        public readonly string|Optional|null $phone = new Optional,
        public readonly string|Optional|null $phone_country_code = new Optional,
        public readonly string|Optional|null $description = new Optional,
        public readonly array|Optional|null $metadata = new Optional,
    ) {}

    /** @return array<string, mixed> */
    public function toUpdateArray(): array
    {
        return collect($this->toArray())
            ->reject(fn ($value) => $value instanceof Optional)
            ->toArray();
    }
}
