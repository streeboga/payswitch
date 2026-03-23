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
 * T-Bank (Tinkoff) acquiring connector.
 *
 * API docs: https://www.tbank.ru/kassa/dev/payments/
 * All amounts are in kopecks. Every request is signed with SHA-256 Token.
 */
final class TBankConnector implements ConnectorInterface
{
    private string $baseUrl;

    /** @var array<string, mixed> */
    private array $credentials;

    /** @param array<string, mixed> $credentials */
    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
        $this->baseUrl = $credentials['base_url'] ?? 'https://securepay.tinkoff.ru/v2';
    }

    public static function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            defaultDisplayName: ['ru' => 'Т-Банк', 'en' => 'T-Bank'],
            logoPath: '/logos/tbank.svg',
            directMethods: [],
            fallbackSessionType: SessionResultType::ServerRedirect,
            amountUnit: AmountUnit::Kopecks,
            sbpMethod: new DirectMethod(SessionResultType::QrInline),
        );
    }

    public function getName(): string
    {
        return 'tbank';
    }

    public function purchase(array $params): array
    {
        $requestParams = [
            'TerminalKey' => $this->terminalKey(),
            'Amount' => $params['amount'] ?? 0,
            'OrderId' => $this->truncateOrderId($params['payment_id'] ?? ''),
            'Description' => $params['description'] ?? '',
            'PayType' => 'O',
        ];

        if (! empty($params['return_url'])) {
            $requestParams['SuccessURL'] = $params['return_url'];
            $requestParams['FailURL'] = $params['fail_url'] ?? $params['return_url'];
        }

        if (! empty($params['callback_url'])) {
            $requestParams['NotificationURL'] = $params['callback_url'];
        }

        return $this->makeRequest('Init', $requestParams);
    }

    public function authorize(array $params): array
    {
        $requestParams = [
            'TerminalKey' => $this->terminalKey(),
            'Amount' => $params['amount'] ?? 0,
            'OrderId' => $this->truncateOrderId($params['payment_id'] ?? ''),
            'Description' => $params['description'] ?? '',
            'PayType' => 'T',
        ];

        if (! empty($params['return_url'])) {
            $requestParams['SuccessURL'] = $params['return_url'];
            $requestParams['FailURL'] = $params['fail_url'] ?? $params['return_url'];
        }

        if (! empty($params['callback_url'])) {
            $requestParams['NotificationURL'] = $params['callback_url'];
        }

        return $this->makeRequest('Init', $requestParams);
    }

    public function capture(array $params): array
    {
        return $this->makeRequest('Confirm', [
            'TerminalKey' => $this->terminalKey(),
            'PaymentId' => $params['transaction_id'] ?? '',
        ]);
    }

    public function refund(array $params): array
    {
        $requestParams = [
            'TerminalKey' => $this->terminalKey(),
            'PaymentId' => $params['transaction_id'] ?? '',
        ];

        if (! empty($params['amount'])) {
            $requestParams['Amount'] = $params['amount'];
        }

        return $this->makeRequest('Cancel', $requestParams);
    }

    public function void(array $params): array
    {
        return $this->makeRequest('Cancel', [
            'TerminalKey' => $this->terminalKey(),
            'PaymentId' => $params['transaction_id'] ?? '',
        ]);
    }

    public function getPaymentStatus(array $params): array
    {
        return $this->makeRequest('GetState', [
            'TerminalKey' => $this->terminalKey(),
            'PaymentId' => $params['transaction_id'] ?? '',
        ]);
    }

    public function createPaymentSession(array $params): PaymentSessionResult|array
    {
        $paymentMethod = $params['payment_method'] ?? 'card';

        try {
            $requestParams = [
                'TerminalKey' => $this->terminalKey(),
                'Amount' => $params['amount'] ?? 0,
                'OrderId' => $this->truncateOrderId($params['payment_id'] ?? ''),
                'Description' => $params['description'] ?? '',
                'PayType' => 'O',
                'Language' => $params['language'] ?? 'ru',
            ];

            if (! empty($params['return_url'])) {
                $requestParams['SuccessURL'] = $params['return_url'];
                $requestParams['FailURL'] = $params['fail_url'] ?? $params['return_url'];
            }

            if (! empty($params['callback_url'])) {
                $requestParams['NotificationURL'] = $params['callback_url'];
            }

            $initResult = $this->makeRequest('Init', $requestParams);

            if (! $initResult['success']) {
                return [
                    'success' => false,
                    'message' => $initResult['message'] ?? 'T-Bank Init failed',
                    'code' => $initResult['code'] ?? 'connector_error',
                ];
            }

            $paymentId = $initResult['data']['PaymentId'] ?? '';
            $paymentUrl = $initResult['data']['PaymentURL'] ?? '';

            // SBP QR flow
            if ($paymentMethod === 'sbp') {
                return $this->createSbpSession((string) $paymentId, $params);
            }

            // Card / default: redirect to hosted payment page
            return PaymentSessionResult::serverRedirect(
                url: $paymentUrl,
                transactionId: (string) $paymentId,
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
        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return false;
        }

        $receivedToken = $data['Token'] ?? null;
        if ($receivedToken === null) {
            return false;
        }

        // Remove Token from data, add Password, generate expected token
        $params = $data;
        unset($params['Token']);

        $expectedToken = $this->generateToken($params);

        return hash_equals($expectedToken, (string) $receivedToken);
    }

    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus
    {
        return $this->mapTBankStatus($eventType);
    }

    public function extractPaymentIdFromWebhook(array $payload): ?string
    {
        return $payload['OrderId'] ?? null;
    }

    public function mapPaymentStatusToInternal(string $rawStatus): ?PaymentStatus
    {
        return $this->mapTBankStatus($rawStatus);
    }

    public function testConnection(): array
    {
        try {
            // Call GetState with a dummy PaymentId — valid credentials return
            // an error (payment not found), invalid credentials return auth error.
            $requestParams = [
                'TerminalKey' => $this->terminalKey(),
                'PaymentId' => '0',
            ];
            $requestParams['Token'] = $this->generateToken($requestParams);

            $response = Http::asJson()
                ->acceptJson()
                ->timeout(10)
                ->post(rtrim($this->baseUrl, '/').'/GetState', $requestParams);

            $body = $response->json() ?? [];
            $errorCode = $body['ErrorCode'] ?? '';

            // ErrorCode "0" = success (unlikely for dummy), other codes = payment not found = OK
            // If we get authentication error, credentials are bad
            if ($errorCode === '7') {
                return ['success' => false, 'message' => $body['Message'] ?? 'Authentication failed'];
            }

            return ['success' => true, 'message' => 'Connection successful'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Generate the Token (SHA-256 signature) for a T-Bank API request.
     *
     * Algorithm:
     * 1. Add Password to params
     * 2. Sort all key-value pairs alphabetically by key
     * 3. Concatenate all VALUES (no keys, no separators)
     * 4. SHA-256 hash the result
     *
     * @param  array<string, mixed>  $params
     */
    private function generateToken(array $params): string
    {
        $params['Password'] = $this->credentials['password'] ?? '';

        ksort($params);

        $values = implode('', array_values(array_map('strval', $params)));

        return hash('sha256', $values);
    }

    private function terminalKey(): string
    {
        return $this->credentials['terminal_key'] ?? '';
    }

    /**
     * T-Bank OrderId is limited to 36 characters.
     */
    private function truncateOrderId(string $orderId): string
    {
        return mb_substr($orderId, 0, 36);
    }

    /**
     * Map T-Bank status string to internal PaymentStatus.
     */
    private function mapTBankStatus(string $status): ?PaymentStatus
    {
        return match ($status) {
            'NEW' => PaymentStatus::Processing,
            'AUTHORIZED' => PaymentStatus::RequiresCapture,
            'CONFIRMED' => PaymentStatus::Succeeded,
            'REVERSED' => PaymentStatus::Cancelled,
            'REFUNDED' => null,
            'PARTIAL_REFUNDED' => null,
            'REJECTED' => PaymentStatus::Failed,
            default => null,
        };
    }

    /**
     * Send a JSON POST request to the T-Bank API.
     *
     * @param  array<string, mixed>  $params
     * @return array{success: bool, transaction_id: ?string, message: ?string, code: ?string, data: array<string, mixed>}
     */
    private function makeRequest(string $endpoint, array $params): array
    {
        try {
            $params['Token'] = $this->generateToken($params);

            $url = rtrim($this->baseUrl, '/').'/'.ltrim($endpoint, '/');

            $response = Http::asJson()
                ->acceptJson()
                ->timeout(30)
                ->post($url, $params);

            $body = $response->json() ?? [];

            $success = ($body['Success'] ?? false) === true;
            $errorCode = $body['ErrorCode'] ?? '';

            if (! $success && $errorCode !== '0') {
                return [
                    'success' => false,
                    'transaction_id' => isset($body['PaymentId']) ? (string) $body['PaymentId'] : null,
                    'message' => $body['Message'] ?? $body['Details'] ?? 'T-Bank error',
                    'code' => $errorCode,
                    'data' => $body,
                ];
            }

            return [
                'success' => true,
                'transaction_id' => isset($body['PaymentId']) ? (string) $body['PaymentId'] : null,
                'message' => $body['Message'] ?? 'ok',
                'code' => $errorCode ?: '0',
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

    /**
     * Generate SBP QR code for an already initialized payment.
     */
    private function createSbpSession(string $paymentId, array $params): PaymentSessionResult|array
    {
        $qrParams = [
            'TerminalKey' => $this->terminalKey(),
            'PaymentId' => $paymentId,
            'DataType' => 'IMAGE',
        ];

        $qrResult = $this->makeRequest('GetQr', $qrParams);

        if (! $qrResult['success']) {
            return [
                'success' => false,
                'message' => $qrResult['message'] ?? 'SBP QR generation failed',
                'code' => $qrResult['code'] ?? 'connector_error',
            ];
        }

        $qrData = $qrResult['data']['Data'] ?? '';

        return PaymentSessionResult::qrInline(
            qrData: $qrData,
            format: 'svg',
            paymentId: $paymentId,
            transactionId: $paymentId,
        );
    }
}
