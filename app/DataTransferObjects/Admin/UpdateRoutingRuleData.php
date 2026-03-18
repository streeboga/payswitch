<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

final class UpdateRoutingRuleData extends Data
{
    public function __construct(
        public readonly string|Optional $type = new Optional,
        public readonly string|Optional $name = new Optional,
        public readonly array|Optional $rules = new Optional,
        public readonly bool|Optional $active = new Optional,
        public readonly int|Optional $priority = new Optional,
        public readonly string|Optional|null $business_profile_id = new Optional,
    ) {}

    public function toUpdateArray(): array
    {
        return collect($this->toArray())
            ->reject(fn ($value) => $value instanceof Optional)
            ->toArray();
    }
}
