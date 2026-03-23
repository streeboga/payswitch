<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\HttpConnector;
use Streeboga\PaymentConnectors\PaymentSessionResult;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\SessionResultType;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Stub connector for testing HttpConnector base class.
 */
class StubHttpConnector extends HttpConnector
{
    protected string $baseUrl = 'https://api.stub-psp.com';

    public static function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            defaultDisplayName: ['en' => 'Stub PSP', 'ru' => 'Стаб ПСП'],
            logoPath: '/logos/stub.svg',
            directMethods: [],
            fallbackSessionType: SessionResultType::ServerRedirect,
            amountUnit: AmountUnit::MinorUnits,
        );
    }

    public function getName(): string
    {
        return 'stub';
    }

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        return true;
    }

    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus
    {
        return null;
    }

    public function extractPaymentIdFromWebhook(array $payload): ?string
    {
        return $payload['payment_id'] ?? null;
    }

    public function mapPaymentStatusToInternal(string $rawStatus): ?PaymentStatus
    {
        return null;
    }

    protected function configureRequest(): array
    {
        return ['token' => 'Bearer secret-token-123'];
    }

    protected function buildPurchaseRequest(array $params): array
    {
        return [
            'endpoint' => '/v1/payments',
            'body' => [
                'amount' => $params['amount'],
                'currency' => $params['currency'] ?? 'USD',
            ],
            'method' => 'POST',
        ];
    }

    protected function parsePurchaseResponse(array $body): array
    {
        return [
            'success' => ($body['status'] ?? '') === 'ok',
            'transaction_id' => $body['id'] ?? null,
            'message' => $body['message'] ?? null,
            'code' => $body['code'] ?? null,
            'data' => $body,
        ];
    }

    protected function buildCreateSessionRequest(array $params): array
    {
        return [
            'endpoint' => '/v1/sessions',
            'body' => [
                'amount' => $params['amount'],
                'return_url' => $params['return_url'] ?? '',
            ],
            'method' => 'POST',
        ];
    }

    protected function parseCreateSessionResponse(array $body): PaymentSessionResult
    {
        return PaymentSessionResult::serverRedirect(
            url: $body['redirect_url'],
            transactionId: $body['session_id'] ?? null,
        );
    }
}

/**
 * Stub connector that uses Rubles amount unit (divides by 100).
 */
class StubRublesConnector extends StubHttpConnector
{
    public static function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            defaultDisplayName: ['en' => 'Stub Rubles'],
            logoPath: '/logos/stub.svg',
            directMethods: [],
            fallbackSessionType: SessionResultType::ServerRedirect,
            amountUnit: AmountUnit::Rubles,
        );
    }
}

/**
 * Stub connector that uses basic auth.
 */
class StubBasicAuthConnector extends StubHttpConnector
{
    protected function configureRequest(): array
    {
        return ['auth' => ['user123', 'pass456']];
    }
}

/**
 * Stub connector that uses custom headers.
 */
class StubHeadersConnector extends StubHttpConnector
{
    protected function configureRequest(): array
    {
        return ['headers' => ['X-Api-Key' => 'my-api-key', 'X-Shop-Id' => 'shop-42']];
    }
}

// --- Tests ---

test('purchase makes correct HTTP call and returns parsed response', function () {
    Http::fake([
        'api.stub-psp.com/v1/payments' => Http::response([
            'status' => 'ok',
            'id' => 'txn_abc123',
            'message' => 'Payment created',
            'code' => 'success',
        ]),
    ]);

    $connector = new StubHttpConnector(['api_key' => 'test']);
    $result = $connector->purchase(['amount' => 5000, 'currency' => 'USD']);

    expect($result)
        ->toBeArray()
        ->and($result['success'])->toBeTrue()
        ->and($result['transaction_id'])->toBe('txn_abc123')
        ->and($result['message'])->toBe('Payment created')
        ->and($result['data']['status'])->toBe('ok');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.stub-psp.com/v1/payments'
            && $request->method() === 'POST'
            && $request['amount'] === 5000
            && $request['currency'] === 'USD';
    });
});

test('configureRequest token auth is applied to HTTP calls', function () {
    Http::fake([
        'api.stub-psp.com/*' => Http::response(['status' => 'ok', 'id' => 'txn_1']),
    ]);

    $connector = new StubHttpConnector(['api_key' => 'test']);
    $connector->purchase(['amount' => 1000]);

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer secret-token-123');
    });
});

test('configureRequest basic auth is applied to HTTP calls', function () {
    Http::fake([
        'api.stub-psp.com/*' => Http::response(['status' => 'ok', 'id' => 'txn_1']),
    ]);

    $connector = new StubBasicAuthConnector(['api_key' => 'test']);
    $connector->purchase(['amount' => 1000]);

    Http::assertSent(function ($request) {
        $header = $request->header('Authorization')[0] ?? '';

        return str_starts_with($header, 'Basic ');
    });
});

test('configureRequest custom headers are applied to HTTP calls', function () {
    Http::fake([
        'api.stub-psp.com/*' => Http::response(['status' => 'ok', 'id' => 'txn_1']),
    ]);

    $connector = new StubHeadersConnector(['api_key' => 'test']);
    $connector->purchase(['amount' => 1000]);

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-Api-Key', 'my-api-key')
            && $request->hasHeader('X-Shop-Id', 'shop-42');
    });
});

test('formatAmount works for MinorUnits (as-is)', function () {
    $connector = new StubHttpConnector(['api_key' => 'test']);

    expect($connector->formatAmount(5000))->toBe('5000')
        ->and($connector->formatAmount(100))->toBe('100')
        ->and($connector->formatAmount(0))->toBe('0');
});

test('formatAmount works for Rubles (divides by 100)', function () {
    $connector = new StubRublesConnector(['api_key' => 'test']);

    expect($connector->formatAmount(5000))->toBe('50.00')
        ->and($connector->formatAmount(100))->toBe('1.00')
        ->and($connector->formatAmount(1))->toBe('0.01')
        ->and($connector->formatAmount(0))->toBe('0.00')
        ->and($connector->formatAmount(12345))->toBe('123.45');
});

test('createPaymentSession returns PaymentSessionResult', function () {
    Http::fake([
        'api.stub-psp.com/v1/sessions' => Http::response([
            'redirect_url' => 'https://psp.com/pay/session-xyz',
            'session_id' => 'sess_xyz',
        ]),
    ]);

    $connector = new StubHttpConnector(['api_key' => 'test']);
    $result = $connector->createPaymentSession([
        'amount' => 5000,
        'return_url' => 'https://merchant.com/return',
    ]);

    expect($result)->toBeInstanceOf(PaymentSessionResult::class)
        ->and($result->type)->toBe(SessionResultType::ServerRedirect)
        ->and($result->toArray()['url'])->toBe('https://psp.com/pay/session-xyz')
        ->and($result->toArray()['transaction_id'])->toBe('sess_xyz');
});

test('purchase returns connector_error on exception', function () {
    Http::fake(function () {
        throw new RuntimeException('Connection timeout');
    });

    $connector = new StubHttpConnector(['api_key' => 'test']);
    $result = $connector->purchase(['amount' => 5000]);

    expect($result)
        ->toBeArray()
        ->and($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('connector_error')
        ->and($result['message'])->toBe('Connection timeout');
});

test('createPaymentSession returns error result on exception', function () {
    Http::fake(function () {
        throw new RuntimeException('Session creation failed');
    });

    $connector = new StubHttpConnector(['api_key' => 'test']);
    $result = $connector->createPaymentSession(['amount' => 5000]);

    // On exception, createPaymentSession should still return PaymentSessionResult or handle gracefully
    // Since it can't create a proper result, it should throw or return a fallback
    expect($result)->toBeArray()
        ->and($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('connector_error')
        ->and($result['message'])->toBe('Session creation failed');
});

test('authorize delegates to purchase methods by default', function () {
    Http::fake([
        'api.stub-psp.com/v1/payments' => Http::response([
            'status' => 'ok',
            'id' => 'txn_auth_1',
        ]),
    ]);

    $connector = new StubHttpConnector(['api_key' => 'test']);
    $result = $connector->authorize(['amount' => 3000, 'currency' => 'EUR']);

    expect($result['success'])->toBeTrue()
        ->and($result['transaction_id'])->toBe('txn_auth_1');
});

test('testConnection sends GET to baseUrl', function () {
    Http::fake([
        'api.stub-psp.com' => Http::response(['status' => 'ok'], 200),
    ]);

    $connector = new StubHttpConnector(['api_key' => 'test']);
    $result = $connector->testConnection();

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.stub-psp.com';
    });
});

test('testConnection returns failure on HTTP error', function () {
    Http::fake([
        'api.stub-psp.com' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $connector = new StubHttpConnector(['api_key' => 'test']);
    $result = $connector->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBeString();
});

test('GET method is used when specified in build request', function () {
    Http::fake([
        'api.stub-psp.com/*' => Http::response(['status' => 'ok', 'id' => 'txn_1']),
    ]);

    $connector = new class(['key' => 'val']) extends StubHttpConnector
    {
        protected function buildGetStatusRequest(array $params): array
        {
            return [
                'endpoint' => '/v1/payments/'.$params['transaction_id'],
                'body' => [],
                'method' => 'GET',
            ];
        }
    };

    $connector->getPaymentStatus(['transaction_id' => 'txn_123']);

    Http::assertSent(function ($request) {
        return $request->method() === 'GET'
            && str_contains($request->url(), '/v1/payments/txn_123');
    });
});
