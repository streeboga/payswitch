<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\ConnectorHealthRepositoryInterface;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;

final readonly class ConnectorHealthService
{
    public function __construct(
        private ConnectorHealthRepositoryInterface $connectorHealth,
    ) {}

    /**
     * Resolve a MerchantConnectorAccount by merchant ID and connector key.
     */
    public function resolveConnector(int|string $merchantId, string $connectorKey): MerchantConnectorAccount
    {
        return MerchantConnectorAccount::where('merchant_account_id', $merchantId)
            ->where('key', $connectorKey)
            ->firstOrFail();
    }

    /**
     * Convert a period string ('24h', '7d', '30d') to hours.
     */
    public function periodToHours(string $period): int
    {
        return match ($period) {
            '7d' => 168,
            '30d' => 720,
            default => 24,
        };
    }

    /**
     * Get health snapshot stats for a connector within a given period.
     *
     * @return array{total: int, success_count: int, error_count: int, success_rate: float, error_rate: float}
     */
    public function getHealthStats(int|string $merchantId, string $connectorName, string $period): array
    {
        $hours = $this->periodToHours($period);
        $since = now()->subHours($hours);

        return $this->connectorHealth->getHealthStats($merchantId, $connectorName, $since);
    }

    /**
     * Get error breakdown for a connector (last 7 days, top 20).
     *
     * @return array<int, array{code: string|null, message: string|null, count: int, last_occurrence: string}>
     */
    public function getErrorBreakdown(int|string $merchantId, string $connectorName): array
    {
        return $this->connectorHealth->getErrorBreakdown($merchantId, $connectorName);
    }
}
