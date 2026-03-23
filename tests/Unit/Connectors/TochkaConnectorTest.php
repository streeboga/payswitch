<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\Drivers\TochkaConnector;
use Streeboga\PaymentConnectors\PaymentSessionResult;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\SessionResultType;
use Tests\TestCase;

uses(TestCase::class);

// --- Helpers ---

function tochkaCredentials(array $overrides = []): array
{
    return array_merge([
        'token' => 'test_jwt_token',
        'customer_code' => 'cust_123',
        'base_url' => 'https://enter.tochka.com/uapi',
    ], $overrides);
}

function tochkaConnector(array $credentialOverrides = []): TochkaConnector
{
    return new TochkaConnector(tochkaCredentials($credentialOverrides));
}

// --- Capabilities ---

test('capabilities returns correct descriptor', function () {
    $caps = TochkaConnector::capabilities();

    expect($caps)->toBeInstanceOf(ConnectorCapabilities::class)
        ->and($caps->amountUnit)->toBe(AmountUnit::Rubles)
        ->and($caps->fallbackSessionType)->toBe(SessionResultType::ServerRedirect)
        ->and($caps->directMethods)->toHaveKeys(['card', 'sbp'])
        ->and($caps->directMethods['card']->sessionType)->toBe(SessionResultType::ServerRedirect)
        ->and($caps->directMethods['sbp']->sessionType)->toBe(SessionResultType::ServerRedirect)
        ->and($caps->displayName('ru'))->toBe('Точка')
        ->and($caps->displayName('en'))->toBe('Tochka');
});

// --- getName ---

test('getName returns tochka', function () {
    expect(tochkaConnector()->getName())->toBe('tochka');
});

// --- purchase returns not_supported ---

test('purchase returns not_supported', function () {
    $connector = tochkaConnector();
    $result = $connector->purchase([
        'payment_id' => 'pay_123',
        'amount' => 100000,
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('not_supported');
});

// --- authorize returns not_supported ---

test('authorize returns not_supported', function () {
    $connector = tochkaConnector();
    $result = $connector->authorize([
        'payment_id' => 'pay_123',
        'amount' => 100000,
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('not_supported');
});

// --- createPaymentSession ---

test('createPaymentSession sends Data-wrapped JSON body with Bearer auth', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'Data' => [
                'operationId' => 'op_001',
                'paymentLink' => 'https://enter.tochka.com/pay/op_001',
            ],
        ]),
    ]);

    $connector = tochkaConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_session_001',
        'amount' => 150000, // 1500.00 RUB in kopecks
        'description' => 'Order #123',
        'return_url' => 'https://merchant.com/success',
        'currency' => 'RUB',
    ]);

    expect($result)->toBeInstanceOf(PaymentSessionResult::class)
        ->and($result->type)->toBe(SessionResultType::ServerRedirect)
        ->and($result->toArray()['url'])->toBe('https://enter.tochka.com/pay/op_001')
        ->and($result->toArray()['transaction_id'])->toBe('op_001');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/acquiring/v1.0/payments')
            && $request->hasHeader('Authorization', 'Bearer test_jwt_token')
            && isset($body['Data'])
            && $body['Data']['amount'] === '1500.00'
            && $body['Data']['customerCode'] === 'cust_123'
            && $body['Data']['purpose'] === 'Order #123'
            && $body['Data']['redirectUrl'] === 'https://merchant.com/success'
            && $body['Data']['paymentLinkId'] === 'pay_session_001'
            && $body['Data']['paymentMode'] === ['card']; // default
    });
});

test('createPaymentSession sets paymentMode as array for card', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'Data' => [
                'operationId' => 'op_002',
                'paymentLink' => 'https://enter.tochka.com/pay/op_002',
            ],
        ]),
    ]);

    $connector = tochkaConnector();
    $connector->createPaymentSession([
        'payment_id' => 'pay_card',
        'amount' => 50000,
        'return_url' => 'https://merchant.com/return',
        'payment_method' => 'card',
    ]);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['Data']['paymentMode'] === ['card'];
    });
});

test('createPaymentSession sets paymentMode as array for sbp', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'Data' => [
                'operationId' => 'op_003',
                'paymentLink' => 'https://enter.tochka.com/pay/op_003',
            ],
        ]),
    ]);

    $connector = tochkaConnector();
    $connector->createPaymentSession([
        'payment_id' => 'pay_sbp',
        'amount' => 50000,
        'return_url' => 'https://merchant.com/return',
        'payment_method' => 'sbp',
    ]);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['Data']['paymentMode'] === ['sbp'];
    });
});

test('createPaymentSession sets preAuthorization for authorize flow', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'Data' => [
                'operationId' => 'op_004',
                'paymentLink' => 'https://enter.tochka.com/pay/op_004',
            ],
        ]),
    ]);

    $connector = tochkaConnector();
    $connector->createPaymentSession([
        'payment_id' => 'pay_preauth',
        'amount' => 100000,
        'return_url' => 'https://merchant.com/return',
        'pre_authorization' => true,
    ]);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['Data']['preAuthorization'] === true;
    });
});

test('createPaymentSession returns error when API fails', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'Data' => [
                'message' => 'Invalid customer code',
                'code' => 'VALIDATION_ERROR',
            ],
        ], 400),
    ]);

    $connector = tochkaConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_fail',
        'amount' => 10000,
        'return_url' => 'https://merchant.com/return',
    ]);

    expect($result)->toBeArray()
        ->and($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Invalid customer code');
});

test('createPaymentSession returns error on exception', function () {
    Http::fake(function () {
        throw new RuntimeException('Connection timeout');
    });

    $connector = tochkaConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_timeout',
        'amount' => 10000,
        'return_url' => 'https://merchant.com/return',
    ]);

    expect($result)->toBeArray()
        ->and($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('connector_error');
});

// --- capture ---

test('capture calls correct endpoint with Data wrapper', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'Data' => [
                'operationId' => 'op_005',
                'status' => 'APPROVED',
            ],
        ]),
    ]);

    $connector = tochkaConnector();
    $result = $connector->capture([
        'transaction_id' => 'op_005',
    ]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/acquiring/v1.0/payments/op_005/capture')
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test_jwt_token');
    });
});

// --- refund ---

test('refund calls refund endpoint with required amount', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'Data' => [
                'operationId' => 'op_006',
                'status' => 'REFUNDED',
            ],
        ]),
    ]);

    $connector = tochkaConnector();
    $result = $connector->refund([
        'transaction_id' => 'op_006',
        'amount' => 50000, // 500.00 RUB partial refund
    ]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/acquiring/v1.0/payments/op_006/refund')
            && $body['Data']['amount'] === '500.00';
    });
});

test('refund sends zero amount when not provided', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'Data' => [
                'operationId' => 'op_007',
                'status' => 'REFUNDED',
            ],
        ]),
    ]);

    $connector = tochkaConnector();
    $result = $connector->refund([
        'transaction_id' => 'op_007',
    ]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/acquiring/v1.0/payments/op_007/refund')
            && $body['Data']['amount'] === '0.00';
    });
});

// --- void ---

test('void returns not_supported', function () {
    $connector = tochkaConnector();
    $result = $connector->void([
        'transaction_id' => 'op_008',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('not_supported');
});

// --- getPaymentStatus ---

test('getPaymentStatus calls GET and returns data from Data envelope', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'Data' => [
                'operationId' => 'op_009',
                'status' => 'APPROVED',
                'amount' => 1500.00,
            ],
        ]),
    ]);

    $connector = tochkaConnector();
    $result = $connector->getPaymentStatus([
        'transaction_id' => 'op_009',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['status'])->toBe('APPROVED');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/acquiring/v1.0/payments/op_009')
            && $request->method() === 'GET';
    });
});

// --- Amount formatting ---

test('amount formatted as rubles from kopecks', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'Data' => [
                'operationId' => 'op_010',
                'paymentLink' => 'https://enter.tochka.com/pay/op_010',
            ],
        ]),
    ]);

    $connector = tochkaConnector();
    $connector->createPaymentSession([
        'payment_id' => 'pay_amount',
        'amount' => 10050, // 100.50 RUB
        'return_url' => 'https://merchant.com/return',
    ]);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['Data']['amount'] === '100.50';
    });
});

test('amount zero formatted correctly', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'Data' => [
                'operationId' => 'op_011',
                'paymentLink' => 'https://enter.tochka.com/pay/op_011',
            ],
        ]),
    ]);

    $connector = tochkaConnector();
    $connector->createPaymentSession([
        'payment_id' => 'pay_zero',
        'amount' => 0,
        'return_url' => 'https://merchant.com/return',
    ]);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['Data']['amount'] === '0.00';
    });
});

// --- Status mapping ---

test('mapPaymentStatusToInternal maps all Tochka statuses', function () {
    $connector = tochkaConnector();

    expect($connector->mapPaymentStatusToInternal('CREATED'))->toBe(PaymentStatus::Processing)
        ->and($connector->mapPaymentStatusToInternal('AUTHORIZED'))->toBe(PaymentStatus::RequiresCapture)
        ->and($connector->mapPaymentStatusToInternal('APPROVED'))->toBe(PaymentStatus::Succeeded)
        ->and($connector->mapPaymentStatusToInternal('EXPIRED'))->toBe(PaymentStatus::Failed)
        ->and($connector->mapPaymentStatusToInternal('REFUNDED'))->toBeNull()
        ->and($connector->mapPaymentStatusToInternal('ON-REFUND'))->toBeNull()
        ->and($connector->mapPaymentStatusToInternal('REFUNDED_PARTIALLY'))->toBeNull()
        ->and($connector->mapPaymentStatusToInternal('WAIT_FULL_PAYMENT'))->toBe(PaymentStatus::Processing)
        ->and($connector->mapPaymentStatusToInternal('UNKNOWN'))->toBeNull();
});

// --- Webhook ---

test('mapWebhookEventToStatus maps Tochka event types', function () {
    $connector = tochkaConnector();

    expect($connector->mapWebhookEventToStatus('acquiringInternetPayment'))->toBe(PaymentStatus::Succeeded)
        ->and($connector->mapWebhookEventToStatus('incomingSbpPayment'))->toBe(PaymentStatus::Succeeded)
        ->and($connector->mapWebhookEventToStatus('unknownEvent'))->toBeNull();
});

test('extractPaymentIdFromWebhook extracts paymentLinkId', function () {
    $connector = tochkaConnector();

    $paymentId = $connector->extractPaymentIdFromWebhook([
        'eventType' => 'acquiringInternetPayment',
        'operationId' => 'op_tochka_001',
        'paymentLinkId' => 'pay_01ABC',
        'status' => 'APPROVED',
    ]);

    expect($paymentId)->toBe('pay_01ABC');
});

test('extractPaymentIdFromWebhook returns null when paymentLinkId missing', function () {
    $connector = tochkaConnector();

    $paymentId = $connector->extractPaymentIdFromWebhook([
        'eventType' => 'acquiringInternetPayment',
        'operationId' => 'op_tochka_001',
    ]);

    expect($paymentId)->toBeNull();
});

test('verifyWebhookSignature returns true (MVP)', function () {
    $connector = tochkaConnector();

    expect($connector->verifyWebhookSignature('{"test": true}', []))->toBeTrue();
});

// --- Webhook fixture ---

test('webhook fixture matches expected format', function () {
    $fixture = json_decode(
        file_get_contents(base_path('tests/Fixtures/Webhooks/tochka_acquiring_payment.json')),
        true,
    );

    expect($fixture)
        ->toHaveKey('eventType')
        ->toHaveKey('paymentLinkId')
        ->toHaveKey('status')
        ->toHaveKey('amount')
        ->and($fixture['eventType'])->toBe('acquiringInternetPayment')
        ->and($fixture['status'])->toBe('APPROVED');
});

// --- Base URL ---

test('default base URL uses uapi prefix', function () {
    $connector = new TochkaConnector([
        'token' => 'test',
        'customer_code' => 'cust',
    ]);

    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'Data' => [
                'operationId' => 'op_url',
                'status' => 'APPROVED',
            ],
        ]),
    ]);

    $connector->getPaymentStatus(['transaction_id' => 'op_url']);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'enter.tochka.com/uapi/acquiring');
    });
});

// --- testConnection ---

test('testConnection returns success when credentials are valid', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'message' => 'Payment not found',
        ], 404),
    ]);

    $connector = tochkaConnector();
    $result = $connector->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful');
});

test('testConnection returns failure on auth error (401)', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'message' => 'Unauthorized',
        ], 401),
    ]);

    $connector = tochkaConnector();
    $result = $connector->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Authentication failed');
});

test('testConnection returns failure on auth error (403)', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'message' => 'Forbidden',
        ], 403),
    ]);

    $connector = tochkaConnector();
    $result = $connector->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Authentication failed');
});

test('testConnection returns failure on network error', function () {
    Http::fake(function () {
        throw new RuntimeException('DNS resolution failed');
    });

    $connector = tochkaConnector();
    $result = $connector->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('DNS resolution failed');
});

// --- Error handling ---

test('connector error on network failure returns proper structure', function () {
    Http::fake(function () {
        throw new RuntimeException('Connection refused');
    });

    $connector = tochkaConnector();
    $result = $connector->capture([
        'transaction_id' => 'op_fail',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('connector_error')
        ->and($result['message'])->toBe('Connection refused');
});
