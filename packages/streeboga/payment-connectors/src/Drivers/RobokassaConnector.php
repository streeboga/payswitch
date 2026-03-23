<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors\Drivers;

use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\DirectMethod;
use Streeboga\PaymentConnectors\PaymentSessionResult;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\SessionResultType;

/**
 * Robokassa connector — form redirect with MD5 signatures.
 *
 * Robokassa has NO REST API for creating payments. Payments are initiated
 * via form redirect with signed params. Webhooks (ResultURL) are verified
 * with a separate password (Password2).
 *
 * @see https://docs.robokassa.ru/
 */
final class RobokassaConnector implements ConnectorInterface
{
    private string $login;

    private string $password1;

    private string $password2;

    /** @param array<string, mixed> $credentials */
    public function __construct(array $credentials)
    {
        $this->login = $credentials['login'] ?? '';
        $this->password1 = $credentials['password1'] ?? '';
        $this->password2 = $credentials['password2'] ?? '';
    }

    public static function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            defaultDisplayName: ['ru' => 'Робокасса', 'en' => 'Robokassa'],
            logoPath: '/logos/robokassa.svg',
            directMethods: [
                'card' => new DirectMethod(SessionResultType::FormRedirect),
                'sbp' => new DirectMethod(SessionResultType::FormRedirect),
            ],
            fallbackSessionType: SessionResultType::FormRedirect,
            amountUnit: AmountUnit::Rubles,
        );
    }

    public function getName(): string
    {
        return 'robokassa';
    }

    public function purchase(array $params): array
    {
        return $this->notSupported();
    }

    public function authorize(array $params): array
    {
        return $this->notSupported();
    }

    public function capture(array $params): array
    {
        return $this->notSupported();
    }

    public function refund(array $params): array
    {
        return $this->notSupported();
    }

    public function void(array $params): array
    {
        return $this->notSupported();
    }

    public function getPaymentStatus(array $params): array
    {
        return $this->notSupported();
    }

    public function createPaymentSession(array $params): PaymentSessionResult|array
    {
        $outSum = $this->formatAmount($params['amount'] ?? 0);
        $invId = $this->generateInvId($params['payment_id'] ?? '');
        $paymentId = $params['payment_id'] ?? '';

        $signature = md5("{$this->login}:{$outSum}:{$invId}:{$this->password1}:Shp_payment_id={$paymentId}");

        $formParams = [
            'MerchantLogin' => $this->login,
            'OutSum' => $outSum,
            'InvId' => $invId,
            'Description' => $params['description'] ?? '',
            'SignatureValue' => $signature,
            'Culture' => 'ru',
            'Encoding' => 'utf-8',
            'Shp_payment_id' => $paymentId,
        ];

        // Pre-select payment method if specified
        $method = $params['payment_method'] ?? null;
        if ($method === 'card') {
            $formParams['IncCurrLabel'] = 'BankCardPSR';
        } elseif ($method === 'sbp') {
            // NOTE: 'SBP' is the commonly used label; verify with real merchant
            // credentials via Robokassa GetCurrencies API. Some docs use 'SBPPSR'.
            $formParams['IncCurrLabel'] = 'SBP';
        }

        return PaymentSessionResult::formRedirect(
            'https://auth.robokassa.ru/Merchant/Index.aspx',
            $formParams,
            'POST',
        );
    }

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        parse_str($payload, $data);

        $outSum = $data['OutSum'] ?? '';
        $invId = $data['InvId'] ?? '';
        $receivedSignature = $data['SignatureValue'] ?? '';

        if ($receivedSignature === '') {
            return false;
        }

        // Rebuild with Shp_ params sorted alphabetically
        $shpParams = [];
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'Shp_')) {
                $shpParams[$key] = $value;
            }
        }
        ksort($shpParams);

        $shpString = '';
        foreach ($shpParams as $key => $value) {
            $shpString .= ":{$key}={$value}";
        }

        $expected = md5("{$outSum}:{$invId}:{$this->password2}{$shpString}");

        return hash_equals(strtolower($expected), strtolower($receivedSignature));
    }

    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus
    {
        // Robokassa ResultURL is only called on successful payment
        return match ($eventType) {
            'result' => PaymentStatus::Succeeded,
            default => null,
        };
    }

    public function extractPaymentIdFromWebhook(array $payload): ?string
    {
        return $payload['Shp_payment_id'] ?? null;
    }

    public function mapPaymentStatusToInternal(string $rawStatus): ?PaymentStatus
    {
        return match ($rawStatus) {
            'completed', 'result' => PaymentStatus::Succeeded,
            default => null,
        };
    }

    public function testConnection(): array
    {
        if ($this->login === '' || $this->password1 === '' || $this->password2 === '') {
            return ['success' => false, 'message' => 'Missing credentials: login, password1, or password2'];
        }

        return ['success' => true, 'message' => 'Credentials format is valid'];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Format amount from kopecks to rubles string (e.g., 10050 → "100.50").
     */
    private function formatAmount(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    /**
     * Generate a numeric InvId from a payment ID string.
     *
     * Uses SHA-256 truncated to 8 hex chars (32-bit) for better distribution
     * than crc32. Deterministic for retries on the same payment_id.
     */
    private function generateInvId(string $paymentId): int
    {
        return hexdec(substr(hash('sha256', $paymentId), 0, 8));
    }

    /**
     * Return a standardized "not supported" response.
     *
     * @return array{success: false, transaction_id: null, message: string, code: string}
     */
    private function notSupported(): array
    {
        return [
            'success' => false,
            'transaction_id' => null,
            'message' => 'Robokassa only supports redirect flow',
            'code' => 'not_supported',
        ];
    }
}
