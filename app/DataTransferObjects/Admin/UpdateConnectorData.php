<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

final class UpdateConnectorData extends Data
{
    public function __construct(
        public readonly string|Optional $connector_name = new Optional,
        public readonly string|Optional $connector_type = new Optional,
        public readonly array|Optional|null $connector_account_details = new Optional,
        public readonly array|Optional|null $payment_methods_enabled = new Optional,
        public readonly bool|Optional $test_mode = new Optional,
        public readonly bool|Optional $disabled = new Optional,
        public readonly string|Optional|null $profile_id = new Optional,
    ) {}

    public function toUpdateArray(): array
    {
        return collect($this->toArray())
            ->reject(fn ($value) => $value instanceof Optional)
            ->toArray();
    }
}
