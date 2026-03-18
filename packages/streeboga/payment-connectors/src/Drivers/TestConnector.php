<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors\Drivers;

use Illuminate\Support\Str;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\PaymentStatus;

/**
 * Test/mock connector that simulates PSP responses.
 *
 * Card numbers determine behavior:
 * - 4242424242424242 → success
 * - 4000000000000002 → decline
 * - 4000000000009995 → insufficient funds
 * - 4000000000003220 → requires 3DS
 * - Any other → success
 */
final class TestConnector implements ConnectorInterface
{
    public function __construct(?array $credentials = []) {}

    public function getName(): string
    {
        return 'test';
    }

    public function purchase(array $params): array
    {
        return $this->simulatePayment($params);
    }

    public function authorize(array $params): array
    {
        return $this->simulatePayment($params, authorize: true);
    }

    public function capture(array $params): array
    {
        return [
            'success' => true,
            'transaction_id' => $params['transaction_id'] ?? ('test_cap_'.Str::ulid()),
            'message' => 'Capture successful',
            'code' => 'ok',
        ];
    }

    public function refund(array $params): array
    {
        $amount = $params['amount'] ?? 0;

        if ($amount > 99999900) {
            return [
                'success' => false,
                'transaction_id' => null,
                'message' => 'Refund amount too large',
                'code' => 'refund_failed',
            ];
        }

        return [
            'success' => true,
            'transaction_id' => 'test_ref_'.Str::ulid(),
            'message' => 'Refund successful',
            'code' => 'ok',
        ];
    }

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        return true;
    }

    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus
    {
        return match ($eventType) {
            'payment.succeeded' => PaymentStatus::Succeeded,
            'payment.failed' => PaymentStatus::Failed,
            'payment.canceled' => PaymentStatus::Cancelled,
            default => null,
        };
    }

    public function extractPaymentIdFromWebhook(array $payload): ?string
    {
        return $payload['payment_id'] ?? ($payload['data']['object']['metadata']['payment_id'] ?? null);
    }

    private function simulatePayment(array $params, bool $authorize = false): array
    {
        $cardNumber = $params['card_number'] ?? $params['payment_method_data']['card']['card_number'] ?? '4242424242424242';

        return match ($cardNumber) {
            '4000000000000002' => [
                'success' => false,
                'transaction_id' => null,
                'message' => 'Your card was declined',
                'code' => 'card_declined',
                'data' => ['decline_code' => 'generic_decline'],
            ],
            '4000000000009995' => [
                'success' => false,
                'transaction_id' => null,
                'message' => 'Your card has insufficient funds',
                'code' => 'insufficient_funds',
                'data' => ['decline_code' => 'insufficient_funds'],
            ],
            '4000000000003220' => [
                'success' => false,
                'transaction_id' => null,
                'message' => 'This payment requires 3D Secure authentication',
                'code' => 'requires_action',
                'data' => [
                    'requires_action' => true,
                    'redirect_url' => 'https://test.3ds.example.com/auth',
                ],
            ],
            default => [
                'success' => true,
                'transaction_id' => 'test_'.($authorize ? 'auth_' : 'ch_').Str::ulid(),
                'message' => $authorize ? 'Authorization successful' : 'Payment successful',
                'code' => 'ok',
                'data' => [],
            ],
        };
    }
}
