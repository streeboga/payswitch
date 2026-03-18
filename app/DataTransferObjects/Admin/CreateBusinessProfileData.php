<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use Spatie\LaravelData\Data;

final class CreateBusinessProfileData extends Data
{
    public function __construct(
        public readonly string $merchant_id,
        public readonly ?string $webhook_url = null,
    ) {}
}
