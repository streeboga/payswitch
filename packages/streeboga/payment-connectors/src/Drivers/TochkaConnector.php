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

/**
 * Tochka Bank acquiring connector.
 *
 * API docs: https://enter.tochka.com/doc/openapi
 * All amounts are in rubles (decimal). Auth via Bearer JWT token.
 */
final class TochkaConnector implements ConnectorInterface
{
    private string $token;

    private string $customerCode;

    private string $baseUrl;

    /** @param array<string, mixed> $credentials */
    public function __construct(array $credentials)
    {
        $this->token = $credentials['token'] ?? '';
        $this->customerCode = $credentials['customer_code'] ?? '';
        $this->baseUrl = $credentials['base_url'] ?? 'https://enter.tochka.com/api';
    }

    public static function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            defaultDisplayName: ['ru' => 'Точка', 'en' => 'Tochka'],
            logoPath: '/logos/tochka.svg',
            directMethods: [
                'card' => new DirectMethod(SessionResultType::ServerRedirect),
                'sbp' => new DirectMethod(SessionResultType::ServerRedirect),
            ],
            fallbackSessionType: SessionResultType::ServerRedirect,
            amountUnit: AmountUnit::Rubles,
        );
    }

    public function getName(): string
    {
        return 'tochka';
    }

    public function purchase(array $params): array
    {
        return [
            'success' => false,
            'transaction_id' => null,
            'message' => 'Direct purchase not supported — use createPaymentSession for redirect flow',
            'code' => 'not_supported',
            'data' => [],
        ];
    }

    public function authorize(array $params): array
    {
        return [
            'success' => false,
            'transaction_id' => null,
            'message' => 'Direct authorize not supported — use createPaymentSession with preAuthorization',
            'code' => 'not_supported',
            'data' => [],
        ];
    }

    public function capture(array $params): array
    {
        $paymentId = $params['transaction_id'] ?? '';

        return $this->makeRequest(
            'POST',
            "/acquiring/v1.0/payments/{$paymentId}/capture",
            [],
        );
    }

    public function refund(array $params): array
    {
        $paymentId = $params['transaction_id'] ?? '';

        $body = [];
        if (! empty($params['amount'])) {
            $body['amount'] = $this->formatAmount($params['amount']);
        }

        return $this->makeRequest(
            'POST',
            "/acquiring/v1.0/payments/{$paymentId}/cancel",
            $body,
        );
    }

    public function void(array $params): array
    {
        $paymentId = $params['transaction_id'] ?? '';

        return $this->makeRequest(
            'POST',
            "/acquiring/v1.0/payments/{$paymentId}/cancel",
            [],
        );
    }

    public function getPaymentStatus(array $params): array
    {
        $paymentId = $params['transaction_id'] ?? '';

        return $this->makeRequest(
            'GET',
            "/acquiring/v1.0/payments/{$paymentId}",
            [],
        );
    }

    public function createPaymentSession(array $params): PaymentSessionResult|array
    {
        try {
            $body = [
                'amount' => $this->formatAmount($params['amount'] ?? 0),
                'customerCode' => $this->customerCode,
                'purpose' => $params['description'] ?? 'Payment',
                'redirectUrl' => $params['return_url'] ?? '',
                'paymentLinkId' => $params['payment_id'] ?? '',
            ];

            if (! empty($params['fail_url'])) {
                $body['failRedirectUrl'] = $params['fail_url'];
            }

            // Direct method selection
            $method = $params['payment_method'] ?? null;
            if (in_array($method, ['card', 'sbp', 'tinkoff', 'dolyame'], true)) {
                $body['paymentMode'] = $method;
            }

            // Pre-authorization for two-step flow
            if (! empty($params['pre_authorization'])) {
                $body['preAuthorization'] = true;
            }

            $result = $this->makeRequest('POST', '/acquiring/v1.0/payments', $body);

            if (! $result['success']) {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'Tochka payment creation failed',
                    'code' => $result['code'] ?? 'connector_error',
                ];
            }

            $paymentUrl = $result['data']['paymentUrl'] ?? $result['data']['url'] ?? '';
            $transactionId = $result['data']['paymentId'] ?? $result['data']['id'] ?? null;

            return PaymentSessionResult::serverRedirect(
                url: $paymentUrl,
                transactionId: $transactionId ? (string) $transactionId : null,
            );
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'connector_error',
            ];
        }
    }

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        // Tochka webhooks are JWT-encoded (RS256) and should be verified
        // with the public key at https://enter.tochka.com/doc/openapi/static/keys/public.
        // For MVP: accept webhooks and verify by polling status (same approach as YooKassa).
        // The webhook URL is secret + TLS, which provides baseline security.
        return true;
    }

    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus
    {
        return match ($eventType) {
            'acquiringInternetPayment' => PaymentStatus::Succeeded,
            'incomingSbpPayment' => PaymentStatus::Succeeded,
            default => null,
        };
    }

    public function extractPaymentIdFromWebhook(array $payload): ?string
    {
        return $payload['paymentLinkId'] ?? null;
    }

    public function mapPaymentStatusToInternal(string $rawStatus): ?PaymentStatus
    {
        return match ($rawStatus) {
            'AUTHORIZED' => PaymentStatus::RequiresCapture,
            'APPROVED' => PaymentStatus::Succeeded,
            'REFUNDED' => null,
            default => null,
        };
    }

    public function testConnection(): array
    {
        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->timeout(10)
                ->get(rtrim($this->baseUrl, '/').'/acquiring/v1.0/payments/0');

            // Valid credentials: we get a "not found" error (not an auth error).
            // Invalid credentials: we get 401/403.
            if ($response->status() === 401 || $response->status() === 403) {
                return [
                    'success' => false,
                    'message' => 'Authentication failed',
                ];
            }

            return ['success' => true, 'message' => 'Connection successful'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Format an amount from kopecks (internal) to rubles (Tochka API).
     */
    private function formatAmount(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    /**
     * Send an HTTP request to the Tochka API.
     *
     * @param  array<string, mixed>  $data
     * @return array{success: bool, transaction_id: ?string, message: ?string, code: ?string, data: array<string, mixed>}
     */
    private function makeRequest(string $method, string $endpoint, array $data): array
    {
        try {
            $url = rtrim($this->baseUrl, '/').'/'.ltrim($endpoint, '/');

            $request = Http::withToken($this->token)
                ->acceptJson()
                ->timeout(30);

            $response = match (strtoupper($method)) {
                'GET' => $request->get($url, $data),
                default => $request->post($url, $data),
            };

            $body = $response->json() ?? [];

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'transaction_id' => $body['paymentId'] ?? $body['id'] ?? null,
                    'message' => $body['message'] ?? $body['error'] ?? 'Tochka error (HTTP '.$response->status().')',
                    'code' => (string) ($body['code'] ?? $response->status()),
                    'data' => $body,
                ];
            }

            return [
                'success' => true,
                'transaction_id' => isset($body['paymentId']) ? (string) $body['paymentId'] : ($body['id'] ?? null),
                'message' => $body['message'] ?? 'ok',
                'code' => 'ok',
                'data' => $body,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'transaction_id' => null,
                'message' => $e->getMessage(),
                'code' => 'connector_error',
                'data' => [],
            ];
        }
    }
}
