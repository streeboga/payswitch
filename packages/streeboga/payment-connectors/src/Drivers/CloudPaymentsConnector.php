<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors\Drivers;

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\DirectMethod;
use Streeboga\PaymentConnectors\PaymentSessionResult;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\SessionResultType;

final class CloudPaymentsConnector implements ConnectorInterface
{
    private string $publicId;

    private string $apiSecret;

    private array $credentials;

    private string $baseUrl = 'https://api.cloudpayments.ru';

    /** @param  array<string, string>  $credentials */
    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
        $this->publicId = $credentials['public_id'] ?? '';
        $this->apiSecret = $credentials['api_secret'] ?? $credentials['api_key'] ?? '';
    }

    public static function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            defaultDisplayName: ['ru' => 'CloudPayments', 'en' => 'CloudPayments'],
            logoPath: '/logos/cloudpayments.svg',
            directMethods: [
                'card' => new DirectMethod(SessionResultType::EmbeddedWidget),
                'sbp' => new DirectMethod(SessionResultType::EmbeddedWidget),
            ],
            fallbackSessionType: SessionResultType::EmbeddedWidget,
            amountUnit: AmountUnit::Rubles,
        );
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

    public function void(array $params): array
    {
        return $this->makeRequest('/payments/void', [
            'TransactionId' => $params['transaction_id'] ?? '',
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

    public function getPaymentStatus(array $params): array
    {
        return $this->makeRequest('/payments/find', [
            'TransactionId' => $params['transaction_id'] ?? '',
        ]);
    }

    public function createPaymentSession(array $params): PaymentSessionResult
    {
        return PaymentSessionResult::embeddedWidget(
            provider: 'cloudpayments',
            scriptUrl: 'https://widget.cloudpayments.ru/bundles/cloudpayments.js',
            params: [
                'publicId' => $this->publicId,
                'amount' => ($params['amount'] ?? 0) / 100,
                'currency' => $params['currency'] ?? 'RUB',
                'description' => $params['description'] ?? '',
                'invoiceId' => $params['payment_id'] ?? '',
            ],
        );
    }

    public function mapPaymentStatusToInternal(string $rawStatus): ?PaymentStatus
    {
        return match ($rawStatus) {
            'Completed' => PaymentStatus::Succeeded,
            'Declined' => PaymentStatus::Failed,
            'Authorized' => PaymentStatus::RequiresCapture,
            default => null,
        };
    }

    public function testConnection(): array
    {
        try {
            $response = Http::withBasicAuth($this->publicId, $this->apiSecret)
                ->timeout(10)
                ->post($this->baseUrl.'/test');

            $body = $response->json();
            $success = ($body['Success'] ?? false) === true;

            return [
                'success' => $success,
                'message' => $success ? 'Connection successful' : ($body['Message'] ?? 'Authentication failed'),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
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
                        'redirect_method' => 'POST',
                        'redirect_params' => [
                            'PaReq' => $model['PaReq'] ?? null,
                            'MD' => $model['TransactionId'] ?? null,
                            'TermUrl' => $model['TermUrl'] ?? null,
                        ],
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
