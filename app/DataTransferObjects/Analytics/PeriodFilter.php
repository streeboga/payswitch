<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Analytics;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class PeriodFilter extends Data
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    /** @param array<string, mixed> $params */
    public static function fromRequest(array $params): self
    {
        if (! empty($params['from']) && ! empty($params['to'])) {
            return new self(
                from: CarbonImmutable::parse($params['from'])->startOfDay(),
                to: CarbonImmutable::parse($params['to'])->endOfDay(),
            );
        }

        $period = $params['period'] ?? '30d';

        $days = match ($period) {
            '7d' => 7,
            '90d' => 90,
            default => 30,
        };

        $now = CarbonImmutable::now();

        return new self(
            from: $now->subDays($days)->startOfDay(),
            to: $now->endOfDay(),
        );
    }
}
