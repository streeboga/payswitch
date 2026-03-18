<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use Spatie\LaravelData\Data;

final class CreateRoutingRuleData extends Data
{
    /**
     * @param  array<string, mixed>  $rules
     */
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly array $rules,
        public readonly bool $active = true,
        public readonly int $priority = 0,
        public readonly ?string $business_profile_id = null,
    ) {}
}
