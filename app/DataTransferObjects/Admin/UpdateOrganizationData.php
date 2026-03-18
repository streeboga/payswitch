<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use Spatie\LaravelData\Data;

final class UpdateOrganizationData extends Data
{
    public function __construct(
        public readonly string $name,
    ) {}
}
