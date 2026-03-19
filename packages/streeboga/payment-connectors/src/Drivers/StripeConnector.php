<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors\Drivers;

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\PaymentStatus;

final class StripeConnector implements ConnectorInterface
{
    private string $apiKey;

    private array $credentials;

    private string $baseUrl = 'https://api.stripe.com/v1';

    /** @param  array<string, string>  $credentials */
    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
        $this->apiKey = $credentials['api_key'] ?? '';
    }

    public function getName(): string
    {
        return 'stripe';
    }

    public function purchase(array $params): array
    {
        return $this->createPaymentIntent($params, capture: true);
    }

    public function authorize(array $params): array
    {
        return $this->createPaymentIntent($params, capture: false);
    }

    public function capture(array $params): array
    {
        $piId = $params['transaction_id'] ?? '';

        return $this->makeRequest('POST', "/payment_intents/{$piId}/capture", [
            'amount_to_capture' => $params['amount'] ?? 0,
        ], $params['payment_id'] ?? null);
    }

    public function refund(array $params): array
    {
        return $this->makeRequest('POST', '/refunds', [
            'payment_intent' => $params['transaction_id'] ?? '',
            'amount' => $params['amount'] ?? 0,
        ], $params['payment_id'] ?? null);
    }

    public function void(array $params): array
    {
        $piId = $params['transaction_id'] ?? '';

        return $this->makeRequest('POST', "/payment_intents/{$piId}/cancel", [], $params['payment_id'] ?? null);
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

    public function getPaymentStatus(array $params): array
    {
        $piId = $params['transaction_id'] ?? '';

        return $this->makeRequest('GET', "/payment_intents/{$piId}", []);
    }

    public function createPaymentSession(array $params): array
    {
        $returnUrl = $params['return_url'] ?? '';
        $successUrl = $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'status=success';
        $cancelUrl = $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'status=cancelled';

        $body = [
            'mode' => 'payment',
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => strtolower($params['currency'] ?? 'usd'),
                        'unit_amount' => $params['amount'] ?? 0,
                        'product_data' => ['name' => $params['description'] ?? 'Payment'],
                    ],
                    'quantity' => 1,
                ],
            ],
            'metadata' => ['payment_id' => $params['payment_id'] ?? ''],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];

        $result = $this->makeRequest('POST', '/checkout/sessions', $body, $params['payment_id'] ?? null);

        if (! empty($result['data']['url'])) {
            $result['redirect_url'] = $result['data']['url'];
            $result['code'] = 'redirect';
        }

        return $result;
    }

    private function createPaymentIntent(array $params, bool $capture): array
    {
        $amount = $params['amount'] ?? 0;
        $currency = strtolower($params['currency'] ?? 'usd');

        $body = [
            'amount' => $amount,
            'currency' => $currency,
            'capture_method' => $capture ? 'automatic' : 'manual',
            'metadata' => ['payment_id' => $params['payment_id'] ?? ''],
        ];

        if (! empty($params['token'])) {
            $body['payment_method'] = $params['token'];
            $body['confirm'] = 'true';
        } elseif (! empty($params['payment_method_data']['card'])) {
            $body['payment_method_data'] = [
                'type' => 'card',
                'card' => [
                    'number' => $params['payment_method_data']['card']['card_number'] ?? '',
                    'exp_month' => $params['payment_method_data']['card']['card_exp_month'] ?? '',
                    'exp_year' => $params['payment_method_data']['card']['card_exp_year'] ?? '',
                    'cvc' => $params['payment_method_data']['card']['card_cvc'] ?? '',
                ],
            ];
            $body['confirm'] = 'true';
        }

        if (! empty($params['description'])) {
            $body['description'] = $params['description'];
        }

        if (! empty($params['return_url'])) {
            $body['return_url'] = $params['return_url'];
        }

        return $this->makeRequest('POST', '/payment_intents', $body, $params['payment_id'] ?? null);
    }

    private function makeRequest(string $method, string $endpoint, array $data, ?string $idempotencyKey = null): array
    {
        try {
            $request = Http::withToken($this->apiKey)
                ->asForm()
                ->timeout(30);

            if ($idempotencyKey) {
                $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
            }

            $response = $method === 'GET'
                ? $request->get($this->baseUrl.$endpoint)
                : $request->post($this->baseUrl.$endpoint, $this->flattenParams($data));
            $body = $response->json() ?? [];

            if (isset($body['error'])) {
                return [
                    'success' => false,
                    'transaction_id' => $body['error']['payment_intent']['id'] ?? null,
                    'message' => $body['error']['message'] ?? 'Stripe error',
                    'code' => $body['error']['code'] ?? ($body['error']['type'] ?? 'stripe_error'),
                    'data' => $body,
                ];
            }

            $status = $body['status'] ?? '';

            // 3DS required
            if ($status === 'requires_action' && ! empty($body['next_action']['redirect_to_url']['url'])) {
                return [
                    'success' => false,
                    'transaction_id' => $body['id'] ?? null,
                    'message' => 'Requires 3D Secure authentication',
                    'code' => 'requires_action',
                    'data' => array_merge($body, [
                        'redirect_url' => $body['next_action']['redirect_to_url']['url'],
                        'redirect_method' => 'GET',
                    ]),
                ];
            }

            $success = in_array($status, ['succeeded', 'requires_capture'], true);

            return [
                'success' => $success,
                'transaction_id' => $body['id'] ?? null,
                'message' => $success ? 'ok' : ($status ?: 'unknown'),
                'code' => $success ? 'ok' : ($status ?: 'payment_failed'),
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
            'canceled' => PaymentStatus::Failed,
            'requires_capture' => PaymentStatus::RequiresCapture,
            default => null,
        };
    }

    public function testConnection(): array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(10)
                ->get($this->baseUrl.'/balance');

            if ($response->successful()) {
                return ['success' => true, 'message' => 'Connection successful'];
            }

            $body = $response->json();

            return [
                'success' => false,
                'message' => $body['error']['message'] ?? 'Authentication failed',
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function flattenParams(array $params, string $prefix = ''): array
    {
        $result = [];
        foreach ($params as $key => $value) {
            $fullKey = $prefix ? "{$prefix}[{$key}]" : $key;
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenParams($value, $fullKey));
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }
}
