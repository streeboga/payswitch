<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use Spatie\LaravelData\Data;

final class CreateMerchantAccountData extends Data
{
    public function __construct(
        public readonly string $organization_id,
        public readonly string $name,
    ) {}
}
