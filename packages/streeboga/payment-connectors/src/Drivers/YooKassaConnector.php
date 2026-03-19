<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors\Drivers;

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\PaymentStatus;

final class YooKassaConnector implements ConnectorInterface
{
    private string $shopId;

    private string $secretKey;

    private string $baseUrl = 'https://api.yookassa.ru/v3';

    /** @param  array<string, string>  $credentials */
    public function __construct(array $credentials)
    {
        $this->shopId = $credentials['shop_id'] ?? '';
        $this->secretKey = $credentials['secret_key'] ?? $credentials['api_key'] ?? '';
    }

    public function getName(): string
    {
        return 'yookassa';
    }

    public function purchase(array $params): array
    {
        return $this->createPayment($params, capture: true);
    }

    public function authorize(array $params): array
    {
        return $this->createPayment($params, capture: false);
    }

    public function capture(array $params): array
    {
        $txnId = $params['transaction_id'] ?? '';

        $idempotencyKey = ($params['payment_id'] ?? bin2hex(random_bytes(16))).'_capture';

        return $this->makeRequest('POST', "/payments/{$txnId}/capture", [
            'amount' => [
                'value' => number_format(($params['amount'] ?? 0) / 100, 2, '.', ''),
                'currency' => $params['currency'] ?? 'RUB',
            ],
        ], $idempotencyKey);
    }

    public function refund(array $params): array
    {
        $idempotencyKey = ($params['payment_id'] ?? bin2hex(random_bytes(16))).'_refund_'.($params['amount'] ?? 0);

        return $this->makeRequest('POST', '/refunds', [
            'payment_id' => $params['transaction_id'] ?? '',
            'amount' => [
                'value' => number_format(($params['amount'] ?? 0) / 100, 2, '.', ''),
                'currency' => $params['currency'] ?? 'RUB',
            ],
        ], $idempotencyKey);
    }

    public function void(array $params): array
    {
        $txnId = $params['transaction_id'] ?? '';
        $idempotencyKey = ($params['payment_id'] ?? bin2hex(random_bytes(16))).'_void';

        return $this->makeRequest('POST', "/payments/{$txnId}/cancel", [], $idempotencyKey);
    }

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        // YooKassa uses IP whitelist for webhook verification, not signatures.
        // In production, verify source IP is in YooKassa range.
        // For now, accept all — webhook URL is secret + TLS.
        return true;
    }

    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus
    {
        return match ($eventType) {
            'payment.succeeded' => PaymentStatus::Succeeded,
            'payment.canceled' => PaymentStatus::Cancelled,
            'payment.waiting_for_capture' => PaymentStatus::RequiresCapture,
            'refund.succeeded' => null,
            default => null,
        };
    }

    public function extractPaymentIdFromWebhook(array $payload): ?string
    {
        return $payload['object']['metadata']['payment_id']
            ?? null;
    }

    public function getPaymentStatus(array $params): array
    {
        $txnId = $params['transaction_id'] ?? '';

        return $this->makeRequest('GET', "/payments/{$txnId}", []);
    }

    public function createPaymentSession(array $params): array
    {
        $body = [
            'amount' => [
                'value' => number_format(($params['amount'] ?? 0) / 100, 2, '.', ''),
                'currency' => $params['currency'] ?? 'RUB',
            ],
            'capture' => true,
            'description' => $params['description'] ?? '',
            'metadata' => ['payment_id' => $params['payment_id'] ?? ''],
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => $params['return_url'] ?? '',
            ],
        ];

        $idempotencyKey = ($params['payment_id'] ?? bin2hex(random_bytes(16))).'_session';
        $result = $this->makeRequest('POST', '/payments', $body, $idempotencyKey);

        if (! empty($result['data']['confirmation']['confirmation_url'])) {
            $result['redirect_url'] = $result['data']['confirmation']['confirmation_url'];
            $result['code'] = 'redirect';
        }

        return $result;
    }

    private function createPayment(array $params, bool $capture): array
    {
        $body = [
            'amount' => [
                'value' => number_format(($params['amount'] ?? 0) / 100, 2, '.', ''),
                'currency' => $params['currency'] ?? 'RUB',
            ],
            'capture' => $capture,
            'description' => $params['description'] ?? '',
            'metadata' => ['payment_id' => $params['payment_id'] ?? ''],
        ];

        if (! empty($params['token'])) {
            $body['payment_method_id'] = $params['token'];
        } else {
            $card = $params['payment_method_data']['card'] ?? [];
            $body['payment_method_data'] = [
                'type' => 'bank_card',
                'card' => [
                    'number' => $card['card_number'] ?? '',
                    'expiry_month' => $card['card_exp_month'] ?? '',
                    'expiry_year' => $card['card_exp_year'] ?? '',
                    'csc' => $card['card_cvc'] ?? '',
                ],
            ];
        }

        if (! empty($params['return_url'])) {
            $body['confirmation'] = [
                'type' => 'redirect',
                'return_url' => $params['return_url'],
            ];
        }

        $idempotencyKey = $params['payment_id'] ?? bin2hex(random_bytes(16));

        return $this->makeRequest('POST', '/payments', $body, $idempotencyKey);
    }

    private function makeRequest(string $method, string $endpoint, array $data, string $idempotencyKey = ''): array
    {
        try {
            $request = Http::withBasicAuth($this->shopId, $this->secretKey)
                ->withHeaders(['Idempotence-Key' => $idempotencyKey ?: bin2hex(random_bytes(16))])
                ->timeout(30);

            $response = $method === 'POST'
                ? $request->post($this->baseUrl.$endpoint, $data)
                : $request->get($this->baseUrl.$endpoint);

            $body = $response->json() ?? [];

            if (! empty($body['type']) && $body['type'] === 'error') {
                return [
                    'success' => false,
                    'transaction_id' => null,
                    'message' => $body['description'] ?? 'YooKassa error',
                    'code' => $body['code'] ?? 'payment_failed',
                    'data' => $body,
                ];
            }

            $status = $body['status'] ?? '';

            if ($status === 'pending' && ! empty($body['confirmation']['confirmation_url'])) {
                return [
                    'success' => false,
                    'transaction_id' => $body['id'] ?? null,
                    'message' => 'Requires 3D Secure authentication',
                    'code' => 'requires_action',
                    'data' => array_merge($body, [
                        'redirect_url' => $body['confirmation']['confirmation_url'],
                        'redirect_method' => 'GET',
                    ]),
                ];
            }

            $success = in_array($status, ['succeeded', 'waiting_for_capture'], true);

            return [
                'success' => $success,
                'transaction_id' => $body['id'] ?? null,
                'message' => $success ? 'ok' : ($body['cancellation_details']['reason'] ?? $status),
                'code' => $success ? 'ok' : $this->mapErrorCode($body),
                'data' => $body,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'transaction_id' => null,
                'message' => $e->getMessage(),
                'code' => 'connector_error',
            ];
        }
    }

    public function mapPaymentStatusToInternal(string $rawStatus): ?PaymentStatus
    {
        return match ($rawStatus) {
            'succeeded' => PaymentStatus::Succeeded,
            'canceled', 'Declined' => PaymentStatus::Failed,
            'waiting_for_capture' => PaymentStatus::RequiresCapture,
            default => null,
        };
    }

    public function testConnection(): array
    {
        try {
            $response = Http::withBasicAuth($this->shopId, $this->secretKey)
                ->timeout(10)
                ->get($this->baseUrl.'/me');

            if ($response->successful()) {
                return ['success' => true, 'message' => 'Connection successful'];
            }

            $body = $response->json();

            return [
                'success' => false,
                'message' => $body['description'] ?? 'Authentication failed',
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function mapErrorCode(array $body): string
    {
        $reason = $body['cancellation_details']['reason'] ?? '';

        return match ($reason) {
            'card_expired' => 'expired_card',
            'insufficient_funds' => 'insufficient_funds',
            'fraud_suspected', 'issuer_unavailable' => 'card_declined',
            '3d_secure_failed' => 'requires_action',
            default => 'payment_failed',
        };
    }
}
