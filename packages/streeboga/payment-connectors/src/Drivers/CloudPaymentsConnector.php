<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors\Drivers;

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\PaymentStatus;

final class CloudPaymentsConnector implements ConnectorInterface
{
    private string $publicId;

    private string $apiSecret;

    private array $credentials;

    private string $baseUrl = 'https://api.cloudpayments.ru';

    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
        $this->publicId = $credentials['public_id'] ?? '';
        $this->apiSecret = $credentials['api_secret'] ?? $credentials['api_key'] ?? '';
    }

    public function getName(): string
    {
        return 'cloudpayments';
    }

    public function purchase(array $params): array
    {
        return $this->makeRequest('/payments/cards/charge', [
            'Amount' => ($params['amount'] ?? 0) / 100,
            'Currency' => $params['currency'] ?? 'RUB',
            'CardCryptogramPacket' => $params['payment_method_data']['card']['cryptogram'] ?? $params['token'] ?? '',
            'Name' => $params['payment_method_data']['card']['card_holder_name'] ?? '',
            'IpAddress' => $params['ip_address'] ?? '127.0.0.1',
            'Description' => $params['description'] ?? '',
            'InvoiceId' => $params['payment_id'] ?? '',
            'JsonData' => json_encode($params['metadata'] ?? []),
        ]);
    }

    public function authorize(array $params): array
    {
        return $this->makeRequest('/payments/cards/auth', [
            'Amount' => ($params['amount'] ?? 0) / 100,
            'Currency' => $params['currency'] ?? 'RUB',
            'CardCryptogramPacket' => $params['payment_method_data']['card']['cryptogram'] ?? $params['token'] ?? '',
            'Name' => $params['payment_method_data']['card']['card_holder_name'] ?? '',
            'IpAddress' => $params['ip_address'] ?? '127.0.0.1',
            'Description' => $params['description'] ?? '',
            'InvoiceId' => $params['payment_id'] ?? '',
        ]);
    }

    public function capture(array $params): array
    {
        return $this->makeRequest('/payments/confirm', [
            'TransactionId' => $params['transaction_id'] ?? '',
            'Amount' => ($params['amount'] ?? 0) / 100,
        ]);
    }

    public function refund(array $params): array
    {
        return $this->makeRequest('/payments/refund', [
            'TransactionId' => $params['transaction_id'] ?? '',
            'Amount' => ($params['amount'] ?? 0) / 100,
        ]);
    }

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        $hmac = $headers['content-hmac'] ?? null;
        if (! $hmac) {
            return ! app()->environment('production');
        }

        $apiSecret = $this->credentials['api_secret'] ?? $this->credentials['api_key'] ?? '';
        $expected = base64_encode(hash_hmac('sha256', $payload, $apiSecret, true));

        return hash_equals($expected, $hmac);
    }

    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus
    {
        return match ($eventType) {
            'payment.succeeded' => PaymentStatus::Succeeded,
            'payment.canceled' => PaymentStatus::Cancelled,
            'payment.waiting_for_capture' => PaymentStatus::RequiresCapture,
            default => null,
        };
    }

    public function extractPaymentIdFromWebhook(array $payload): ?string
    {
        return $payload['InvoiceId'] ?? ($payload['data']['InvoiceId'] ?? null);
    }

    private function makeRequest(string $endpoint, array $data): array
    {
        try {
            $response = Http::withBasicAuth($this->publicId, $this->apiSecret)
                ->timeout(30)
                ->post($this->baseUrl.$endpoint, $data);

            $body = $response->json();

            $success = ($body['Success'] ?? false) === true;
            $model = $body['Model'] ?? [];

            if ($model['AcsUrl'] ?? null) {
                return [
                    'success' => false,
                    'transaction_id' => $model['TransactionId'] ?? null,
                    'message' => '3-D Secure authentication required',
                    'code' => 'requires_action',
                    'data' => [
                        'redirect_url' => $model['AcsUrl'],
                        'transaction_id' => $model['TransactionId'] ?? null,
                        'pa_req' => $model['PaReq'] ?? null,
                    ],
                ];
            }

            return [
                'success' => $success,
                'transaction_id' => $model['TransactionId'] ?? null,
                'message' => $body['Message'] ?? ($model['CardHolderMessage'] ?? 'Unknown'),
                'code' => $success ? 'ok' : ($model['ReasonCode'] ?? (string) ($body['Message'] ?? 'error')),
                'data' => $model,
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
}
