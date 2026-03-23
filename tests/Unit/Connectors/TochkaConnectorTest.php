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
        'base_url' => 'https://enter.tochka.com/api',
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

test('createPaymentSession sends correct JSON body with Bearer auth', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'paymentId' => 'pmt_001',
            'paymentUrl' => 'https://enter.tochka.com/pay/pmt_001',
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
        ->and($result->toArray()['url'])->toBe('https://enter.tochka.com/pay/pmt_001')
        ->and($result->toArray()['transaction_id'])->toBe('pmt_001');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/acquiring/v1.0/payments')
            && $request->hasHeader('Authorization', 'Bearer test_jwt_token')
            && $body['amount'] === '1500.00'
            && $body['customerCode'] === 'cust_123'
            && $body['purpose'] === 'Order #123'
            && $body['redirectUrl'] === 'https://merchant.com/success'
            && $body['paymentLinkId'] === 'pay_session_001';
    });
});

test('createPaymentSession sets paymentMode for card', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'paymentId' => 'pmt_002',
            'paymentUrl' => 'https://enter.tochka.com/pay/pmt_002',
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

        return $body['paymentMode'] === 'card';
    });
});

test('createPaymentSession sets paymentMode for sbp', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'paymentId' => 'pmt_003',
            'paymentUrl' => 'https://enter.tochka.com/pay/pmt_003',
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

        return $body['paymentMode'] === 'sbp';
    });
});

test('createPaymentSession sets preAuthorization for authorize flow', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'paymentId' => 'pmt_004',
            'paymentUrl' => 'https://enter.tochka.com/pay/pmt_004',
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

        return $body['preAuthorization'] === true;
    });
});

test('createPaymentSession returns error when API fails', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'message' => 'Invalid customer code',
            'code' => 'VALIDATION_ERROR',
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

test('capture calls correct endpoint with transaction_id', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'paymentId' => 'pmt_005',
            'status' => 'APPROVED',
        ]),
    ]);

    $connector = tochkaConnector();
    $result = $connector->capture([
        'transaction_id' => 'pmt_005',
    ]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/acquiring/v1.0/payments/pmt_005/capture')
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test_jwt_token');
    });
});

// --- refund ---

test('refund calls cancel endpoint with amount for partial refund', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'paymentId' => 'pmt_006',
            'status' => 'REFUNDED',
        ]),
    ]);

    $connector = tochkaConnector();
    $result = $connector->refund([
        'transaction_id' => 'pmt_006',
        'amount' => 50000, // 500.00 RUB partial refund
    ]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/acquiring/v1.0/payments/pmt_006/cancel')
            && $body['amount'] === '500.00';
    });
});

test('refund calls cancel endpoint without amount for full refund', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'paymentId' => 'pmt_007',
            'status' => 'REFUNDED',
        ]),
    ]);

    $connector = tochkaConnector();
    $result = $connector->refund([
        'transaction_id' => 'pmt_007',
    ]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/acquiring/v1.0/payments/pmt_007/cancel')
            && ! isset($body['amount']);
    });
});

// --- void ---

test('void calls cancel endpoint without amount', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'paymentId' => 'pmt_008',
        ]),
    ]);

    $connector = tochkaConnector();
    $result = $connector->void([
        'transaction_id' => 'pmt_008',
    ]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/acquiring/v1.0/payments/pmt_008/cancel')
            && $request->method() === 'POST';
    });
});

// --- getPaymentStatus ---

test('getPaymentStatus calls GET and returns mapped status', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'paymentId' => 'pmt_009',
            'status' => 'APPROVED',
            'amount' => 1500.00,
        ]),
    ]);

    $connector = tochkaConnector();
    $result = $connector->getPaymentStatus([
        'transaction_id' => 'pmt_009',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['status'])->toBe('APPROVED');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/acquiring/v1.0/payments/pmt_009')
            && $request->method() === 'GET';
    });
});

// --- Amount formatting ---

test('amount formatted as rubles from kopecks', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'paymentId' => 'pmt_010',
            'paymentUrl' => 'https://enter.tochka.com/pay/pmt_010',
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

        return $body['amount'] === '100.50';
    });
});

test('amount zero formatted correctly', function () {
    Http::fake([
        'enter.tochka.com/*' => Http::response([
            'paymentId' => 'pmt_011',
            'paymentUrl' => 'https://enter.tochka.com/pay/pmt_011',
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

        return $body['amount'] === '0.00';
    });
});

// --- Status mapping ---

test('mapPaymentStatusToInternal maps all Tochka statuses', function () {
    $connector = tochkaConnector();

    expect($connector->mapPaymentStatusToInternal('AUTHORIZED'))->toBe(PaymentStatus::RequiresCapture)
        ->and($connector->mapPaymentStatusToInternal('APPROVED'))->toBe(PaymentStatus::Succeeded)
        ->and($connector->mapPaymentStatusToInternal('REFUNDED'))->toBeNull()
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
        'paymentId' => 'pmt_tochka_001',
        'paymentLinkId' => 'pay_01ABC',
        'status' => 'APPROVED',
    ]);

    expect($paymentId)->toBe('pay_01ABC');
});

test('extractPaymentIdFromWebhook returns null when paymentLinkId missing', function () {
    $connector = tochkaConnector();

    $paymentId = $connector->extractPaymentIdFromWebhook([
        'eventType' => 'acquiringInternetPayment',
        'paymentId' => 'pmt_tochka_001',
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
        ->toHaveKey('paymentId')
        ->toHaveKey('paymentLinkId')
        ->toHaveKey('status')
        ->toHaveKey('amount')
        ->and($fixture['eventType'])->toBe('acquiringInternetPayment')
        ->and($fixture['status'])->toBe('APPROVED');
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
        'transaction_id' => 'pmt_fail',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('connector_error')
        ->and($result['message'])->toBe('Connection refused');
});
