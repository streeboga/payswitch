<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors\Drivers;

use Streeboga\PaymentConnectors\AbstractConnector;
use Streeboga\PaymentData\Enums\PaymentStatus;

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
            'amount' => ($params['amount'] ?? 0) / 100,
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

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        $signatureHeader = $headers['stripe-signature'] ?? null;
        if (! $signatureHeader) {
            return false;
        }

        $webhookSecret = $this->credentials['webhook_secret'] ?? null;
        if (! $webhookSecret) {
            return false;
        }

        $elements = explode(',', $signatureHeader);
        $timestamp = null;
        $signatures = [];

        foreach ($elements as $element) {
            if (! str_contains($element, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $element, 2);
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if (! $timestamp || empty($signatures)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $timestamp.'.'.$payload, $webhookSecret);

        foreach ($signatures as $sig) {
            if (hash_equals($expectedSignature, $sig)) {
                return true;
            }
        }

        return false;
    }

    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus
    {
        return match ($eventType) {
            'payment_intent.succeeded' => PaymentStatus::Succeeded,
            'payment_intent.payment_failed' => PaymentStatus::Failed,
            'payment_intent.canceled' => PaymentStatus::Cancelled,
            'payment_intent.requires_action' => PaymentStatus::RequiresCustomerAction,
            default => null,
        };
    }

    public function extractPaymentIdFromWebhook(array $payload): ?string
    {
        return $payload['data']['object']['metadata']['payment_id'] ?? null;
    }
}
