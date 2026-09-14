<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors\Drivers;

use Illuminate\Support\Facades\Cache;
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
 * Base URL: https://enter.tochka.com/uapi
 * All amounts are in rubles (decimal). Auth via Bearer JWT token.
 * All request bodies are wrapped in {"Data": {...}}, responses read from response["Data"].
 */
final class TochkaConnector implements ConnectorInterface
{
    /** @see https://developers.tochka.com/docs/tochka-api/opisanie-metodov/vebhuki */
    private const PUBLIC_KEY_URL = 'https://enter.tochka.com/doc/openapi/static/keys/public';

    /** DER encoding of OID 1.2.840.113549.1.1.1 (rsaEncryption). */
    private const OID_RSA_ENCRYPTION = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";

    private string $token;

    private string $customerCode;

    private string $baseUrl;

    /** @var array<string, mixed> */
    private array $credentials;

    /** Claims of the last webhook that passed verifyWebhookSignature(). */
    /** @var array<string, mixed> */
    private array $webhookClaims = [];

    /** @param array<string, mixed> $credentials */
    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
        $this->token = $credentials['token'] ?? '';
        $this->customerCode = $credentials['customer_code'] ?? '';
        $this->baseUrl = $credentials['base_url'] ?? 'https://enter.tochka.com/uapi';
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

        $body = [
            'amount' => $this->formatAmount($params['amount'] ?? 0),
        ];

        $result = $this->makeRequest(
            'POST',
            "/acquiring/v1.0/payments/{$paymentId}/refund",
            $body,
        );

        // 5xx — исход неизвестен, а не отказ. Ключа идемпотентности у Точки нет.
        if (! $result['success'] && preg_match('/^5\d\d$/', (string) $result['code'])) {
            $result['code'] = 'connector_error';
        }

        return $result;
    }

    public function void(array $params): array
    {
        // Tochka has no cancel/void endpoint
        return [
            'success' => false,
            'transaction_id' => null,
            'message' => 'Void/cancel not supported by Tochka — use refund instead',
            'code' => 'not_supported',
            'data' => [],
        ];
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

            // paymentMode must be an array; default to ['card'] when no method specified
            $method = $params['payment_method'] ?? 'card';
            $body['paymentMode'] = [$method];

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

            $paymentLink = $result['data']['paymentLink'] ?? '';
            $operationId = $result['data']['operationId'] ?? null;

            return PaymentSessionResult::serverRedirect(
                url: $paymentLink,
                transactionId: $operationId ? (string) $operationId : null,
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
     * A Tochka webhook body is a bare RS256 JWT string, verified with Tochka's public key.
     *
     * @see https://developers.tochka.com/docs/tochka-api/opisanie-metodov/vebhuki
     */
    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        $parts = explode('.', trim($payload));
        if (count($parts) !== 3) {
            return false;
        }

        [$rawHeader, $rawClaims, $rawSignature] = $parts;

        $header = json_decode((string) self::base64UrlDecode($rawHeader), true);
        // Pin the algorithm: without this "alg": "none" would sail through.
        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256') {
            return false;
        }

        $signature = self::base64UrlDecode($rawSignature);
        $publicKey = $this->webhookPublicKey();
        if ($signature === false || $publicKey === null) {
            return false;
        }

        if (openssl_verify($rawHeader.'.'.$rawClaims, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            return false;
        }

        $claims = json_decode((string) self::base64UrlDecode($rawClaims), true);
        if (! is_array($claims)) {
            return false;
        }

        $this->webhookClaims = $claims;

        return true;
    }

    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus
    {
        // The receiver reads the event name off the decoded JSON body; a Tochka body is a JWT,
        // so it arrives empty and the name comes from the verified claims instead.
        if ($eventType === '') {
            $eventType = (string) ($this->webhookClaims['webhookType'] ?? '');
        }

        return match ($eventType) {
            'acquiringInternetPayment' => PaymentStatus::Succeeded,
            'incomingSbpPayment' => PaymentStatus::Succeeded,
            default => null,
        };
    }

    public function extractPaymentIdFromWebhook(array $payload): ?string
    {
        return $payload['paymentLinkId'] ?? $this->webhookClaims['paymentLinkId'] ?? null;
    }

    public function mapPaymentStatusToInternal(string $rawStatus): ?PaymentStatus
    {
        return match ($rawStatus) {
            'CREATED' => PaymentStatus::Processing,
            'AUTHORIZED' => PaymentStatus::RequiresCapture,
            'APPROVED' => PaymentStatus::Succeeded,
            'EXPIRED' => PaymentStatus::Failed,
            'REFUNDED' => null,
            'ON-REFUND' => null,
            'REFUNDED_PARTIALLY' => null,
            'WAIT_FULL_PAYMENT' => PaymentStatus::Processing,
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
     * All request bodies are wrapped in {"Data": {...}}.
     * All responses are unwrapped from the "Data" envelope.
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
                default => $request->post($url, ['Data' => $data]),
            };

            $rawBody = $response->json() ?? [];

            // Unwrap the Data envelope
            $body = $rawBody['Data'] ?? $rawBody;

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'transaction_id' => $body['operationId'] ?? null,
                    'message' => $body['message'] ?? $body['error'] ?? 'Tochka error (HTTP '.$response->status().')',
                    'code' => (string) ($body['code'] ?? $response->status()),
                    'data' => $body,
                ];
            }

            return [
                'success' => true,
                'transaction_id' => isset($body['operationId']) ? (string) $body['operationId'] : null,
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

    /**
     * Tochka's webhook signing key: a PEM in the connector credentials if one is pinned there,
     * otherwise the JWK Tochka publishes (cached — it changes about never, but not never).
     */
    private function webhookPublicKey(): ?string
    {
        $pinned = $this->credentials['webhook_public_key'] ?? null;
        if (is_string($pinned) && $pinned !== '') {
            return str_contains($pinned, 'BEGIN') ? $pinned : self::jwkToPem($pinned);
        }

        $jwk = Cache::remember('tochka:webhook_jwk', now()->addDay(), function (): ?string {
            $response = Http::timeout(10)->get(self::PUBLIC_KEY_URL);

            return $response->successful() ? $response->body() : null;
        });

        return is_string($jwk) ? self::jwkToPem($jwk) : null;
    }

    /**
     * Turn an RSA JWK into a PEM public key.
     *
     * ponytail: hand-rolled DER instead of pulling in firebase/php-jwt for one key shape.
     * If a second JWT provider shows up, take the library.
     */
    private static function jwkToPem(string $json): ?string
    {
        $jwk = json_decode($json, true);
        if (! is_array($jwk) || ($jwk['kty'] ?? null) !== 'RSA') {
            return null;
        }

        $modulus = self::base64UrlDecode((string) ($jwk['n'] ?? ''));
        $exponent = self::base64UrlDecode((string) ($jwk['e'] ?? ''));
        if ($modulus === false || $exponent === false || $modulus === '' || $exponent === '') {
            return null;
        }

        $rsaKey = self::derSequence(self::derInteger($modulus).self::derInteger($exponent));
        $algorithm = self::derSequence(self::OID_RSA_ENCRYPTION."\x05\x00");
        $spki = self::derSequence($algorithm.self::derBitString($rsaKey));

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($spki), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if (ord($bytes[0]) > 0x7F) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".self::derLength(strlen($bytes)).$bytes;
    }

    private static function derSequence(string $contents): string
    {
        return "\x30".self::derLength(strlen($contents)).$contents;
    }

    private static function derBitString(string $contents): string
    {
        $contents = "\x00".$contents;

        return "\x03".self::derLength(strlen($contents)).$contents;
    }

    private static function base64UrlDecode(string $value): string|false
    {
        $padded = $value.str_repeat('=', (4 - strlen($value) % 4) % 4);

        return base64_decode(strtr($padded, '-_', '+/'), true);
    }
}
