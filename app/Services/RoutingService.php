<?php

declare(strict_types=1);

namespace App\Services;

use Streeboga\PaymentData\Exceptions\PaymentException;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;

final class RoutingService
{
    /**
     * Resolve the connector for a payment.
     *
     * Priority:
     * 1. Explicit connector name from request
     * 2. Auto-select by payment_method + currency
     * 3. First active connector
     */
    public function resolve(int|string $merchantAccountId, ?string $explicitConnector = null, ?string $paymentMethod = null, ?string $currency = null): MerchantConnectorAccount
    {
        $query = MerchantConnectorAccount::where('merchant_account_id', $merchantAccountId)
            ->where('disabled', false);

        // 1. Explicit connector
        if ($explicitConnector) {
            $mca = $query->where('connector_name', $explicitConnector)->first();
            if ($mca) {
                return $mca;
            }
            throw new PaymentException(
                "Connector '{$explicitConnector}' not found or disabled for this merchant",
                'connector_not_found',
                'invalid_request_error',
                400
            );
        }

        // 2. Auto-select by payment method (check payment_methods_enabled JSON)
        if ($paymentMethod) {
            $connectors = $query->get();
            foreach ($connectors as $mca) {
                $methods = $mca->payment_methods_enabled ?? [];
                foreach ($methods as $method) {
                    if (($method['payment_method'] ?? '') === $paymentMethod) {
                        return $mca;
                    }
                }
            }
        }

        // 3. First active connector
        $mca = $query->first();
        if ($mca) {
            return $mca;
        }

        throw new PaymentException(
            'No active connectors configured for this merchant',
            'no_connectors',
            'invalid_request_error',
            400
        );
    }

    /**
     * Get the next fallback connector (excluding already tried ones).
     */
    public function fallback(int|string $merchantAccountId, array $excludeConnectors): ?MerchantConnectorAccount
    {
        return MerchantConnectorAccount::where('merchant_account_id', $merchantAccountId)
            ->where('disabled', false)
            ->whereNotIn('connector_name', $excludeConnectors)
            ->first();
    }
}
