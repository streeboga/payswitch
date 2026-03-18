<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors\Drivers;

use Streeboga\PaymentConnectors\AbstractConnector;

final class StripeConnector extends AbstractConnector
{
    public function getName(): string
    {
        return 'stripe';
    }

    protected function getGatewayName(): string
    {
        return 'Stripe';
    }

    protected function configureGateway(array $credentials): void
    {
        $this->gateway->setApiKey($credentials['api_key'] ?? '');
    }

    protected function mapPurchaseParams(array $params): array
    {
        return [
            'amount' => ($params['amount'] ?? 0) / 100, // cents to dollars
            'currency' => $params['currency'] ?? 'USD',
            'token' => $params['token'] ?? null,
            'description' => $params['description'] ?? null,
            'metadata' => $params['metadata'] ?? [],
        ];
    }

    protected function mapAuthorizeParams(array $params): array
    {
        return $this->mapPurchaseParams($params);
    }

    protected function mapCaptureParams(array $params): array
    {
        return [
            'amount' => ($params['amount'] ?? 0) / 100,
            'transactionReference' => $params['transaction_id'] ?? null,
        ];
    }

    protected function mapRefundParams(array $params): array
    {
        return [
            'amount' => ($params['amount'] ?? 0) / 100,
            'transactionReference' => $params['transaction_id'] ?? null,
        ];
    }
}
