<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use Spatie\LaravelData\Data;

final class CreateConnectorData extends Data
{
    /**
     * @param  array<string, mixed>  $connector_account_details
     * @param  array<int, string>|null  $payment_methods_enabled
     */
    public function __construct(
        public readonly string $connector_name,
        public readonly string $connector_type,
        public readonly array $connector_account_details,
        public readonly ?string $profile_id = null,
        public readonly ?array $payment_methods_enabled = null,
        public readonly bool $test_mode = false,
    ) {}
}
