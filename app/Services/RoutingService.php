<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\RoutingRuleRepositoryInterface;
use Streeboga\PaymentData\Exceptions\PaymentException;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\RoutingRule;

final class RoutingService
{
    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
        private RoutingRuleRepositoryInterface $routingRuleRepository,
    ) {}

    /**
     * Resolve the connector for a payment.
     *
     * Priority:
     * 1. Explicit connector name from request
     * 2. Routing rules (priority, rule_based, volume_split)
     * 3. Auto-select by payment_method + currency
     * 4. First active connector
     */
    public function resolve(int|string $merchantAccountId, ?string $explicitConnector = null, ?string $paymentMethod = null, ?string $currency = null, ?int $amount = null): MerchantConnectorAccount
    {
        // 1. Explicit connector (highest priority)
        if ($explicitConnector) {
            $mca = $this->merchantRepository->findActiveConnectorByMerchantAndName($merchantAccountId, $explicitConnector);

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

        // 2. Check routing rules
        $rules = $this->routingRuleRepository->getActiveByMerchant($merchantAccountId);

        foreach ($rules as $rule) {
            $connector = $this->evaluateRule($rule, $paymentMethod, $currency, $amount, $merchantAccountId);
            if ($connector) {
                return $connector;
            }
        }

        // 3. Auto-select by payment method (check payment_methods_enabled JSON)
        if ($paymentMethod) {
            $connectors = $this->merchantRepository->getActiveConnectorsByMerchant($merchantAccountId);

            foreach ($connectors as $mca) {
                $methods = $mca->payment_methods_enabled ?? [];
                foreach ($methods as $method) {
                    if (($method['payment_method'] ?? '') === $paymentMethod) {
                        return $mca;
                    }
                }
            }
        }

        // 4. First active connector
        $mca = $this->merchantRepository->getFirstActiveConnector($merchantAccountId);

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
        return $this->merchantRepository->getFirstActiveConnector($merchantAccountId, $excludeConnectors);
    }

    private function evaluateRule(RoutingRule $rule, ?string $paymentMethod, ?string $currency, ?int $amount, int|string $merchantAccountId): ?MerchantConnectorAccount
    {
        $config = $rule->rules;
        if (! is_array($config)) {
            return null;
        }

        return match ($rule->type) {
            'priority' => $this->evaluatePriorityRule($config, $merchantAccountId),
            'rule_based' => $this->evaluateRuleBasedRule($config, $currency, $amount, $merchantAccountId),
            'volume_split' => $this->evaluateVolumeSplitRule($config, $merchantAccountId),
            default => null,
        };
    }

    private function evaluatePriorityRule(array $config, int|string $merchantAccountId): ?MerchantConnectorAccount
    {
        $connectorNames = array_filter($config['connectors'] ?? [], fn ($n) => ! empty($n));

        foreach ($connectorNames as $name) {
            $mca = $this->merchantRepository->findActiveConnectorByMerchantAndName($merchantAccountId, $name);

            if ($mca) {
                return $mca;
            }
        }

        return null;
    }

    private function evaluateRuleBasedRule(array $config, ?string $currency, ?int $amount, int|string $merchantAccountId): ?MerchantConnectorAccount
    {
        $conditions = $config['conditions'] ?? [];

        foreach ($conditions as $condition) {
            if (! isset($condition['field'], $condition['operator'], $condition['value'], $condition['connector'])) {
                continue;
            }

            $value = match ($condition['field']) {
                'currency' => $currency,
                'amount' => $amount,
                default => null,
            };

            if ($value === null) {
                continue;
            }

            $matches = match ($condition['operator']) {
                '==' => $value === $condition['value'],
                '!=' => $value !== $condition['value'],
                '>' => $value > $condition['value'],
                '<' => $value < $condition['value'],
                '>=' => $value >= $condition['value'],
                '<=' => $value <= $condition['value'],
                'in' => in_array($value, (array) $condition['value']),
                default => false,
            };

            if ($matches) {
                return $this->merchantRepository->findActiveConnectorByMerchantAndName($merchantAccountId, $condition['connector']);
            }
        }

        // Default connector
        if (isset($config['default_connector'])) {
            return $this->merchantRepository->findActiveConnectorByMerchantAndName($merchantAccountId, $config['default_connector']);
        }

        return null;
    }

    private function evaluateVolumeSplitRule(array $config, int|string $merchantAccountId): ?MerchantConnectorAccount
    {
        $splits = array_filter($config['split'] ?? [], fn ($s) => isset($s['weight']) && $s['weight'] > 0 && isset($s['connector']));
        if (empty($splits)) {
            return null;
        }

        $totalWeight = array_sum(array_column($splits, 'weight'));
        if ($totalWeight <= 0) {
            return null;
        }

        $random = mt_rand(1, $totalWeight);
        $cumulative = 0;

        foreach ($splits as $split) {
            $cumulative += $split['weight'];
            if ($random <= $cumulative) {
                $mca = $this->merchantRepository->findActiveConnectorByMerchantAndName($merchantAccountId, $split['connector']);
                if ($mca) {
                    return $mca;
                }

                // If disabled, continue to next split entry instead of returning null
                continue;
            }
        }

        return null;
    }
}
