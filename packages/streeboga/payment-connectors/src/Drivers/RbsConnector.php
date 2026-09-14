<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors\Drivers;

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\DirectMethod;
use Streeboga\PaymentConnectors\PaymentSessionResult;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Contracts\WebhookEventReading;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\SessionResultType;

/**
 * RBS Gateway connector — shared by Sberbank and Alfa-Bank (identical API, different base URLs).
 *
 * All requests are application/x-www-form-urlencoded with auth params in the body.
 * Responses are JSON.
 */
final class RbsConnector implements ConnectorInterface, WebhookEventReading
{
    private string $baseUrl;

    /** @var array<string, mixed> */
    private array $credentials;

    /** @param array<string, mixed> $credentials */
    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
        $this->baseUrl = $credentials['base_url'] ?? 'https://securepayments.sberbank.ru/payment/rest';
    }

    public static function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            defaultDisplayName: ['ru' => 'Сбербанк', 'en' => 'Sberbank'],
            logoPath: '/logos/sberbank.svg',
            directMethods: [],
            fallbackSessionType: SessionResultType::ServerRedirect,
            amountUnit: AmountUnit::Kopecks,
            sbpMethod: new DirectMethod(SessionResultType::QrInline),
        );
    }

    public function getName(): string
    {
        return 'rbs';
    }

    public function purchase(array $params): array
    {
        $result = $this->makeRequest('register.do', [
            'orderNumber' => $this->truncateOrderNumber($params['payment_id'] ?? ''),
            'amount' => $this->formatAmount($params['amount'] ?? 0),
            'currency' => $this->mapCurrencyCode($params['currency'] ?? 'RUB'),
            'returnUrl' => $params['return_url'] ?? '',
            'failUrl' => $params['fail_url'] ?? $params['return_url'] ?? '',
            'description' => $params['description'] ?? '',
            'sessionTimeoutSecs' => $params['session_timeout'] ?? 1200,
            'jsonParams' => json_encode(['payment_id' => $params['payment_id'] ?? '']),
        ]);

        if (! $result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'transaction_id' => $result['data']['orderId'] ?? null,
            'message' => 'ok',
            'code' => 'ok',
            'data' => $result['data'],
        ];
    }

    public function authorize(array $params): array
    {
        $result = $this->makeRequest('registerPreAuth.do', [
            'orderNumber' => $this->truncateOrderNumber($params['payment_id'] ?? ''),
            'amount' => $this->formatAmount($params['amount'] ?? 0),
            'currency' => $this->mapCurrencyCode($params['currency'] ?? 'RUB'),
            'returnUrl' => $params['return_url'] ?? '',
            'failUrl' => $params['fail_url'] ?? $params['return_url'] ?? '',
            'description' => $params['description'] ?? '',
            'sessionTimeoutSecs' => $params['session_timeout'] ?? 1200,
            'jsonParams' => json_encode(['payment_id' => $params['payment_id'] ?? '']),
        ]);

        if (! $result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'transaction_id' => $result['data']['orderId'] ?? null,
            'message' => 'ok',
            'code' => 'ok',
            'data' => $result['data'],
        ];
    }

    public function capture(array $params): array
    {
        return $this->makeRequest('deposit.do', [
            'orderId' => $params['transaction_id'] ?? '',
            'amount' => $this->formatAmount($params['amount'] ?? 0),
        ]);
    }

    public function refund(array $params): array
    {
        // Идемпотентности у refund.do нет — refund_id передать некуда.
        $result = $this->makeRequest('refund.do', [
            'orderId' => $params['transaction_id'] ?? '',
            'amount' => $this->formatAmount($params['amount'] ?? 0),
        ]);

        // makeRequest считает успехом отсутствие errorCode, а 5xx с HTML его тоже не содержит.
        // Успешный refund.do всегда отвечает errorCode "0".
        if ($result['success'] && ! isset($result['data']['errorCode'])) {
            return ['success' => false, 'transaction_id' => null, 'message' => 'Unparsed RBS response', 'code' => 'connector_error', 'data' => $result['data']];
        }

        return $result;
    }

    public function void(array $params): array
    {
        return $this->makeRequest('reverse.do', [
            'orderId' => $params['transaction_id'] ?? '',
        ]);
    }

    public function getPaymentStatus(array $params): array
    {
        $requestParams = [];

        if (! empty($params['transaction_id'])) {
            $requestParams['orderId'] = $params['transaction_id'];
        } elseif (! empty($params['order_number'])) {
            $requestParams['orderNumber'] = $params['order_number'];
        }

        $result = $this->makeRequest('getOrderStatusExtended.do', $requestParams);

        if (! $result['success'] && ($result['data']['orderStatus'] ?? null) === null) {
            return $result;
        }

        $data = $result['data'];
        $orderStatus = (int) ($data['orderStatus'] ?? -1);

        return [
            'success' => true,
            'transaction_id' => $data['orderId'] ?? ($params['transaction_id'] ?? null),
            'message' => $this->orderStatusLabel($orderStatus),
            'code' => (string) $orderStatus,
            'data' => $data,
        ];
    }

    public function createPaymentSession(array $params): PaymentSessionResult|array
    {
        $paymentMethod = $params['payment_method'] ?? 'card';

        try {
            // Step 1: Register the order
            $registerResult = $this->makeRequest('register.do', [
                'orderNumber' => $this->truncateOrderNumber($params['payment_id'] ?? ''),
                'amount' => $this->formatAmount($params['amount'] ?? 0),
                'currency' => $this->mapCurrencyCode($params['currency'] ?? 'RUB'),
                'returnUrl' => $params['return_url'] ?? '',
                'failUrl' => $params['fail_url'] ?? $params['return_url'] ?? '',
                'description' => $params['description'] ?? '',
                'language' => $params['language'] ?? 'ru',
                'sessionTimeoutSecs' => $params['session_timeout'] ?? 1200,
                'jsonParams' => json_encode(['payment_id' => $params['payment_id'] ?? '']),
                'dynamicCallbackUrl' => $params['callback_url'] ?? '',
            ]);

            if (! $registerResult['success']) {
                return [
                    'success' => false,
                    'message' => $registerResult['message'] ?? 'RBS register failed',
                    'code' => $registerResult['code'] ?? 'connector_error',
                ];
            }

            $orderId = $registerResult['data']['orderId'] ?? '';
            $formUrl = $registerResult['data']['formUrl'] ?? '';

            // Step 2: For SBP, generate QR code
            if ($paymentMethod === 'sbp') {
                return $this->createSbpSession($orderId, $params);
            }

            // Card / default: redirect to hosted payment page
            return PaymentSessionResult::serverRedirect(
                url: $formUrl,
                transactionId: $orderId,
            );
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'connector_error',
            ];
        }
    }

    /**
     * RBS callback checksum, symmetric scheme (HMAC-SHA256 on a key shared with the gateway).
     *
     * Gateway spec: drop `checksum` and `sign_alias` from the callback params, sort the rest
     * by parameter name, join them as "name;value;" (the string ends with ";"), HMAC-SHA256
     * it with the shared callback key and compare the uppercase hex digest.
     *
     * @see https://securepayments.sberbank.ru/wiki/doku.php/integration:api:callback:start
     *
     * ponytail: symmetric scheme only. The gateway also offers asymmetric (SHA512withRSA);
     * add it here if a merchant is issued an RSA callback key instead of a shared one.
     */
    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        $secret = (string) ($this->credentials['callback_secret'] ?? '');
        if ($secret === '') {
            return false;
        }

        parse_str($payload, $params);

        $received = $params['checksum'] ?? '';
        if (! is_string($received) || $received === '') {
            return false;
        }

        unset($params['checksum'], $params['sign_alias']);
        ksort($params, SORT_STRING);

        $signed = '';
        foreach ($params as $name => $value) {
            if (! is_scalar($value)) {
                return false;
            }
            $signed .= $name.';'.$value.';';
        }

        $expected = strtoupper(hash_hmac('sha256', $signed, $secret));

        return hash_equals($expected, strtoupper($received));
    }

    /**
     * The callback names the operation in `operation` and whether it went through in
     * `status` (1 — yes, 0 — no). A failed deposit or approval is a declined payment; a
     * failed reversal or refund changes nothing.
     *
     * @see https://securepayments.sberbank.ru/wiki/doku.php/integration:api:callback:start
     */
    public function webhookEventType(array $payload): string
    {
        $operation = is_string($payload['operation'] ?? null) ? $payload['operation'] : '';

        if ((string) ($payload['status'] ?? '1') === '0') {
            return in_array($operation, ['approved', 'deposited'], true) ? 'declined' : '';
        }

        return $operation;
    }

    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus
    {
        return match ($eventType) {
            'deposited' => PaymentStatus::Succeeded,
            'approved', 'authorized' => PaymentStatus::RequiresCapture,
            'reversed' => PaymentStatus::Cancelled,
            'refunded' => null,
            'declined', 'declinedByTimeout' => PaymentStatus::Failed,
            default => null,
        };
    }

    public function extractPaymentIdFromWebhook(array $payload): ?string
    {
        // jsonParams contains the payment_id we passed on register
        $jsonParams = $payload['jsonParams'] ?? null;
        if (is_string($jsonParams)) {
            $decoded = json_decode($jsonParams, true);
            if (isset($decoded['payment_id'])) {
                return $decoded['payment_id'];
            }
        }

        return $payload['orderNumber'] ?? null;
    }

    public function mapPaymentStatusToInternal(string $rawStatus): ?PaymentStatus
    {
        return match ($rawStatus) {
            '0' => PaymentStatus::Processing,             // Created
            '1' => PaymentStatus::RequiresCapture,        // Authorized (pre-auth hold)
            '2' => PaymentStatus::Succeeded,              // Deposited (captured)
            '3' => PaymentStatus::Cancelled,              // Reversed
            '4' => null,                                  // Refunded — handled separately
            '5' => PaymentStatus::RequiresCustomerAction, // ACS (3DS in progress)
            '6' => PaymentStatus::Failed,                 // Declined
            default => null,
        };
    }

    public function testConnection(): array
    {
        try {
            // Use getOrderStatusExtended.do with a dummy orderId — valid auth returns
            // an error response (order not found), invalid auth returns auth error.
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(10)
                ->post(rtrim($this->baseUrl, '/').'/getOrderStatusExtended.do', array_merge(
                    $this->authParams(),
                    ['orderId' => '00000000-0000-0000-0000-000000000000'],
                ));

            $body = $response->json() ?? [];
            $errorCode = (int) ($body['errorCode'] ?? -1);

            // errorCode 5 = Access denied → bad credentials
            // errorCode 6 = Order not found → credentials OK
            // errorCode 7 = System error
            if ($errorCode === 5) {
                return ['success' => false, 'message' => $body['errorMessage'] ?? 'Authentication failed'];
            }

            return ['success' => true, 'message' => 'Connection successful'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Format amount — RBS expects kopecks (integer string), and the system stores in kopecks.
     */
    public function formatAmount(int $amount): string
    {
        return (string) $amount;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * RBS orderNumber is limited to 32 characters.
     * Strip hyphens first (common in ULIDs/UUIDs) to preserve more meaningful chars.
     */
    private function truncateOrderNumber(string $orderNumber): string
    {
        $stripped = str_replace('-', '', $orderNumber);

        return mb_substr($stripped, 0, 32);
    }

    /**
     * @return array<string, string>
     */
    private function authParams(): array
    {
        if (! empty($this->credentials['token'])) {
            return ['token' => $this->credentials['token']];
        }

        return [
            'userName' => $this->credentials['username'] ?? '',
            'password' => $this->credentials['password'] ?? '',
        ];
    }

    /**
     * Send form-encoded POST request to the RBS gateway.
     *
     * @param  array<string, mixed>  $params
     * @return array{success: bool, transaction_id: ?string, message: ?string, code: ?string, data: array<string, mixed>}
     */
    private function makeRequest(string $endpoint, array $params): array
    {
        try {
            $url = rtrim($this->baseUrl, '/').'/'.ltrim($endpoint, '/');

            $response = Http::asForm()
                ->acceptJson()
                ->timeout(30)
                ->post($url, array_merge($this->authParams(), $params));

            $body = $response->json() ?? [];

            $errorCode = $body['errorCode'] ?? null;

            // For register.do / registerPreAuth.do: success means orderId is present and no errorCode
            // For other endpoints: errorCode 0 = success
            if ($errorCode !== null && (int) $errorCode !== 0) {
                return [
                    'success' => false,
                    'transaction_id' => $body['orderId'] ?? null,
                    'message' => $body['errorMessage'] ?? 'RBS error',
                    'code' => (string) $errorCode,
                    'data' => $body,
                ];
            }

            return [
                'success' => true,
                'transaction_id' => $body['orderId'] ?? null,
                'message' => $body['errorMessage'] ?? 'ok',
                'code' => (string) ($errorCode ?? '0'),
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
     * Generate SBP QR code for an already registered order.
     */
    private function createSbpSession(string $orderId, array $params): PaymentSessionResult|array
    {
        $qrResult = $this->makeRequest('sbp/c2b/qr/dynamic/get.do', [
            'mdOrder' => $orderId,
            'qrWidth' => $params['qr_width'] ?? 300,
            'qrHeight' => $params['qr_height'] ?? 300,
            'qrFormat' => 'image',
        ]);

        if (! $qrResult['success']) {
            return [
                'success' => false,
                'message' => $qrResult['message'] ?? 'SBP QR generation failed',
                'code' => $qrResult['code'] ?? 'connector_error',
            ];
        }

        $renderedQr = $qrResult['data']['renderedQr'] ?? '';

        return PaymentSessionResult::qrInline(
            qrData: $renderedQr,
            format: 'base64_png',
            paymentId: $orderId,
            transactionId: $orderId,
        );
    }

    /**
     * Map ISO 4217 alpha code to numeric code used by RBS.
     */
    private function mapCurrencyCode(string $currency): string
    {
        return match (strtoupper($currency)) {
            'RUB' => '643',
            'USD' => '840',
            'EUR' => '978',
            default => '643',
        };
    }

    /**
     * Human-readable label for RBS orderStatus code.
     */
    private function orderStatusLabel(int $status): string
    {
        return match ($status) {
            0 => 'Created',
            1 => 'Authorized',
            2 => 'Deposited',
            3 => 'Reversed',
            4 => 'Refunded',
            5 => 'ACS',
            6 => 'Declined',
            default => 'Unknown',
        };
    }
}
