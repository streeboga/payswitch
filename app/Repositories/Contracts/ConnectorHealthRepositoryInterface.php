<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Carbon\CarbonInterface;

interface ConnectorHealthRepositoryInterface
{
    /**
     * Get health snapshot stats for a connector within a given period.
     *
     * @return array{total: int, success_count: int, error_count: int, success_rate: float, error_rate: float}
     */
    public function getHealthStats(int|string $merchantId, string $connectorName, CarbonInterface $since): array;

    /**
     * Get error breakdown for a connector (last 7 days, top 20).
     *
     * @return array<int, array{code: string|null, message: string|null, count: int, last_occurrence: string}>
     */
    public function getErrorBreakdown(int|string $merchantId, string $connectorName): array;
}
