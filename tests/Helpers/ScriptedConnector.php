<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\ConnectorFactory;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\SessionResultType;

/**
 * Коннектор, отвечающий заранее заданным: массивом результата или исключением.
 *
 * Не заданный метод отвечает успехом. Все вызовы пишутся в $calls.
 */
final class ScriptedConnector implements ConnectorInterface
{
    /** @var array<string, array<string, mixed>|\Throwable|\Closure> */
    public static array $script = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public static array $calls = [];

    public function __construct(?array $credentials = []) {}

    /**
     * @param  array<string, array<string, mixed>|\Throwable|\Closure>  $script
     */
    public static function register(string $name, array $script = []): void
    {
        self::$script = $script;
        self::$calls = [];
        ConnectorFactory::register($name, self::class);
    }

    /** @return list<array<string, mixed>> */
    public static function callsTo(string $method): array
    {
        return array_values(array_map(fn ($c) => $c[1], array_filter(self::$calls, fn ($c) => $c[0] === $method)));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function answer(string $method, array $params): array
    {
        self::$calls[] = [$method, $params];
        $scripted = self::$script[$method] ?? ['success' => true, 'transaction_id' => "scripted_{$method}_".count(self::$calls), 'code' => 'ok', 'message' => 'ok', 'data' => []];

        if ($scripted instanceof \Closure) {
            $scripted = $scripted($params);
        }

        if ($scripted instanceof \Throwable) {
            throw $scripted;
        }

        return $scripted;
    }

    public static function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            defaultDisplayName: ['en' => 'Scripted'],
            logoPath: '/logos/test.svg',
            directMethods: [],
            fallbackSessionType: SessionResultType::ServerRedirect,
            amountUnit: AmountUnit::MinorUnits,
        );
    }

    public function getName(): string
    {
        return 'scripted';
    }

    public function authorize(array $params): array
    {
        return $this->answer('authorize', $params);
    }

    public function purchase(array $params): array
    {
        return $this->answer('purchase', $params);
    }

    public function capture(array $params): array
    {
        return $this->answer('capture', $params);
    }

    public function refund(array $params): array
    {
        return $this->answer('refund', $params);
    }

    public function void(array $params): array
    {
        return $this->answer('void', $params);
    }

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        return false;
    }

    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus
    {
        return null;
    }

    public function extractPaymentIdFromWebhook(array $payload): ?string
    {
        return null;
    }

    public function getPaymentStatus(array $params): array
    {
        return $this->answer('getPaymentStatus', $params);
    }

    public function mapPaymentStatusToInternal(string $rawStatus): ?PaymentStatus
    {
        return null;
    }

    public function createPaymentSession(array $params): array
    {
        return $this->answer('createPaymentSession', $params);
    }

    public function testConnection(): array
    {
        return ['success' => true, 'message' => 'ok'];
    }
}
