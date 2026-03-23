<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\Drivers\RbsConnector;
use Streeboga\PaymentConnectors\PaymentSessionResult;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\SessionResultType;
use Tests\TestCase;

uses(TestCase::class);

// --- Helpers ---

function rbsCredentials(array $overrides = []): array
{
    return array_merge([
        'username' => 'test_user',
        'password' => 'test_pass',
        'base_url' => 'https://3dsec.sberbank.ru/payment/rest',
    ], $overrides);
}

function rbsConnector(array $credentialOverrides = []): RbsConnector
{
    return new RbsConnector(rbsCredentials($credentialOverrides));
}

// --- Capabilities ---

test('capabilities returns correct descriptor', function () {
    $caps = RbsConnector::capabilities();

    expect($caps)->toBeInstanceOf(ConnectorCapabilities::class)
        ->and($caps->amountUnit)->toBe(AmountUnit::Kopecks)
        ->and($caps->fallbackSessionType)->toBe(SessionResultType::ServerRedirect)
        ->and($caps->directMethods)->toBeEmpty()
        ->and($caps->sbpMethod)->not->toBeNull()
        ->and($caps->sbpMethod->sessionType)->toBe(SessionResultType::QrInline)
        ->and($caps->displayName('ru'))->toBe('Сбербанк')
        ->and($caps->displayName('en'))->toBe('Sberbank');
});

// --- Amount formatting ---

test('formatAmount returns kopecks as-is (no division)', function () {
    $connector = rbsConnector();

    expect($connector->formatAmount(5000))->toBe('5000')
        ->and($connector->formatAmount(100))->toBe('100')
        ->and($connector->formatAmount(1))->toBe('1')
        ->and($connector->formatAmount(0))->toBe('0');
});

// --- Auth ---

test('username/password auth params are included in requests', function () {
    Http::fake([
        '3dsec.sberbank.ru/*' => Http::response([
            'orderId' => 'order-123',
            'formUrl' => 'https://3dsec.sberbank.ru/payment/merchants/pay.html?mdOrder=order-123',
        ]),
    ]);

    $connector = rbsConnector();
    $connector->purchase([
        'payment_id' => 'pay_test',
        'amount' => 10000,
        'return_url' => 'https://merchant.com/return',
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'register.do')
            && $request->isForm()
            && $request['userName'] === 'test_user'
            && $request['password'] === 'test_pass';
    });
});

test('token auth is used when token credential is provided', function () {
    Http::fake([
        '3dsec.sberbank.ru/*' => Http::response([
            'orderId' => 'order-123',
            'formUrl' => 'https://3dsec.sberbank.ru/payment/merchants/pay.html?mdOrder=order-123',
        ]),
    ]);

    $connector = rbsConnector(['token' => 'my-api-token', 'username' => '', 'password' => '']);
    $connector->purchase([
        'payment_id' => 'pay_test',
        'amount' => 10000,
        'return_url' => 'https://merchant.com/return',
    ]);

    Http::assertSent(function ($request) {
        return $request['token'] === 'my-api-token'
            && ! isset($request['userName']);
    });
});

// --- Custom base URL ---

test('custom base_url from credentials is used', function () {
    Http::fake([
        'pay.alfabank.ru/*' => Http::response([
            'orderId' => 'alfa-order-1',
            'formUrl' => 'https://pay.alfabank.ru/payment/merchants/pay.html?mdOrder=alfa-order-1',
        ]),
    ]);

    $connector = new RbsConnector([
        'username' => 'alfa_user',
        'password' => 'alfa_pass',
        'base_url' => 'https://pay.alfabank.ru/payment/rest',
    ]);

    $connector->purchase([
        'payment_id' => 'pay_alfa',
        'amount' => 5000,
        'return_url' => 'https://merchant.com/return',
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'pay.alfabank.ru/payment/rest/register.do');
    });
});

// --- purchase ---

test('purchase calls register.do with correct form params', function () {
    Http::fake([
        '3dsec.sberbank.ru/*' => Http::response([
            'orderId' => 'rbs-order-abc',
            'formUrl' => 'https://3dsec.sberbank.ru/payment/merchants/pay.html?mdOrder=rbs-order-abc',
        ]),
    ]);

    $connector = rbsConnector();
    $result = $connector->purchase([
        'payment_id' => 'pay_123',
        'amount' => 50000,
        'currency' => 'RUB',
        'return_url' => 'https://merchant.com/success',
        'description' => 'Test payment',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['transaction_id'])->toBe('rbs-order-abc')
        ->and($result['code'])->toBe('ok');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'register.do')
            && $request['orderNumber'] === 'pay_123'
            && $request['amount'] === '50000'
            && $request['currency'] === '643'
            && $request['returnUrl'] === 'https://merchant.com/success'
            && $request['description'] === 'Test payment';
    });
});

test('purchase returns error when register.do fails', function () {
    Http::fake([
        '3dsec.sberbank.ru/*' => Http::response([
            'errorCode' => '1',
            'errorMessage' => 'Order number already used',
        ]),
    ]);

    $connector = rbsConnector();
    $result = $connector->purchase([
        'payment_id' => 'pay_dup',
        'amount' => 10000,
        'return_url' => 'https://merchant.com/return',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Order number already used')
        ->and($result['code'])->toBe('1');
});

// --- authorize ---

test('authorize calls registerPreAuth.do', function () {
    Http::fake([
        '3dsec.sberbank.ru/*' => Http::response([
            'orderId' => 'rbs-preauth-order',
            'formUrl' => 'https://3dsec.sberbank.ru/payment/merchants/pay.html?mdOrder=rbs-preauth-order',
        ]),
    ]);

    $connector = rbsConnector();
    $result = $connector->authorize([
        'payment_id' => 'pay_preauth',
        'amount' => 30000,
        'return_url' => 'https://merchant.com/return',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['transaction_id'])->toBe('rbs-preauth-order');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'registerPreAuth.do')
            && $request['orderNumber'] === 'pay_preauth'
            && $request['amount'] === '30000';
    });
});

// --- capture ---

test('capture calls deposit.do with orderId and amount', function () {
    Http::fake([
        '3dsec.sberbank.ru/*' => Http::response([
            'errorCode' => '0',
            'errorMessage' => 'Success',
        ]),
    ]);

    $connector = rbsConnector();
    $result = $connector->capture([
        'transaction_id' => 'rbs-order-to-capture',
        'amount' => 25000,
    ]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'deposit.do')
            && $request['orderId'] === 'rbs-order-to-capture'
            && $request['amount'] === '25000';
    });
});

// --- refund ---

test('refund calls refund.do with orderId and amount', function () {
    Http::fake([
        '3dsec.sberbank.ru/*' => Http::response([
            'errorCode' => '0',
            'errorMessage' => 'Success',
        ]),
    ]);

    $connector = rbsConnector();
    $result = $connector->refund([
        'transaction_id' => 'rbs-order-to-refund',
        'amount' => 5000,
    ]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'refund.do')
            && $request['orderId'] === 'rbs-order-to-refund'
            && $request['amount'] === '5000';
    });
});

// --- void ---

test('void calls reverse.do with orderId', function () {
    Http::fake([
        '3dsec.sberbank.ru/*' => Http::response([
            'errorCode' => '0',
            'errorMessage' => 'Success',
        ]),
    ]);

    $connector = rbsConnector();
    $result = $connector->void([
        'transaction_id' => 'rbs-order-to-reverse',
    ]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'reverse.do')
            && $request['orderId'] === 'rbs-order-to-reverse';
    });
});

// --- getPaymentStatus ---

test('getPaymentStatus calls getOrderStatusExtended.do and maps status codes', function () {
    Http::fake([
        '3dsec.sberbank.ru/*' => Http::response([
            'orderStatus' => 2,
            'orderId' => 'rbs-order-status',
            'actionCode' => 0,
            'amount' => 50000,
            'errorCode' => '0',
        ]),
    ]);

    $connector = rbsConnector();
    $result = $connector->getPaymentStatus([
        'transaction_id' => 'rbs-order-status',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['transaction_id'])->toBe('rbs-order-status')
        ->and($result['message'])->toBe('Deposited')
        ->and($result['code'])->toBe('2');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'getOrderStatusExtended.do')
            && $request['orderId'] === 'rbs-order-status';
    });
});

test('getPaymentStatus maps all RBS status codes to internal statuses', function () {
    $connector = rbsConnector();

    expect($connector->mapPaymentStatusToInternal('0'))->toBe(PaymentStatus::Processing)
        ->and($connector->mapPaymentStatusToInternal('1'))->toBe(PaymentStatus::RequiresCapture)
        ->and($connector->mapPaymentStatusToInternal('2'))->toBe(PaymentStatus::Succeeded)
        ->and($connector->mapPaymentStatusToInternal('3'))->toBe(PaymentStatus::Cancelled)
        ->and($connector->mapPaymentStatusToInternal('4'))->toBeNull()
        ->and($connector->mapPaymentStatusToInternal('5'))->toBe(PaymentStatus::RequiresCustomerAction)
        ->and($connector->mapPaymentStatusToInternal('6'))->toBe(PaymentStatus::Failed)
        ->and($connector->mapPaymentStatusToInternal('99'))->toBeNull();
});

// --- createPaymentSession ---

test('createPaymentSession returns serverRedirect for card payments', function () {
    Http::fake([
        '3dsec.sberbank.ru/*' => Http::response([
            'orderId' => 'session-order-123',
            'formUrl' => 'https://3dsec.sberbank.ru/payment/merchants/pay.html?mdOrder=session-order-123',
        ]),
    ]);

    $connector = rbsConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_session',
        'amount' => 15000,
        'currency' => 'RUB',
        'return_url' => 'https://merchant.com/return',
        'description' => 'Session test',
    ]);

    expect($result)->toBeInstanceOf(PaymentSessionResult::class)
        ->and($result->type)->toBe(SessionResultType::ServerRedirect)
        ->and($result->toArray()['url'])->toBe('https://3dsec.sberbank.ru/payment/merchants/pay.html?mdOrder=session-order-123')
        ->and($result->toArray()['transaction_id'])->toBe('session-order-123');
});

test('createPaymentSession returns qrInline for SBP payments', function () {
    Http::fake([
        '3dsec.sberbank.ru/payment/rest/register.do' => Http::response([
            'orderId' => 'sbp-order-123',
            'formUrl' => 'https://3dsec.sberbank.ru/payment/merchants/pay.html?mdOrder=sbp-order-123',
        ]),
        '3dsec.sberbank.ru/payment/rest/sbp/c2b/qr/dynamic/get.do' => Http::response([
            'errorCode' => '0',
            'payload' => 'https://qr.nspk.ru/...',
            'renderedQr' => 'iVBORw0KGgoAAAANSUhEUgAAA==',
            'qrId' => 'qr-abc-123',
        ]),
    ]);

    $connector = rbsConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_sbp',
        'amount' => 20000,
        'currency' => 'RUB',
        'return_url' => 'https://merchant.com/return',
        'payment_method' => 'sbp',
    ]);

    expect($result)->toBeInstanceOf(PaymentSessionResult::class)
        ->and($result->type)->toBe(SessionResultType::QrInline)
        ->and($result->toArray()['qr_data'])->toBe('iVBORw0KGgoAAAANSUhEUgAAA==')
        ->and($result->toArray()['format'])->toBe('base64_png')
        ->and($result->toArray()['payment_id'])->toBe('sbp-order-123')
        ->and($result->toArray()['transaction_id'])->toBe('sbp-order-123');
});

test('createPaymentSession returns error when register.do fails', function () {
    Http::fake([
        '3dsec.sberbank.ru/*' => Http::response([
            'errorCode' => '1',
            'errorMessage' => 'Duplicate order',
        ]),
    ]);

    $connector = rbsConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_dup',
        'amount' => 10000,
        'return_url' => 'https://merchant.com/return',
    ]);

    expect($result)->toBeArray()
        ->and($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Duplicate order');
});

test('createPaymentSession returns error on exception', function () {
    Http::fake(function () {
        throw new RuntimeException('Connection timeout');
    });

    $connector = rbsConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_fail',
        'amount' => 10000,
        'return_url' => 'https://merchant.com/return',
    ]);

    expect($result)->toBeArray()
        ->and($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('connector_error');
});

// --- Webhook ---

test('mapWebhookEventToStatus maps RBS operations correctly', function () {
    $connector = rbsConnector();

    expect($connector->mapWebhookEventToStatus('deposited'))->toBe(PaymentStatus::Succeeded)
        ->and($connector->mapWebhookEventToStatus('approved'))->toBe(PaymentStatus::RequiresCapture)
        ->and($connector->mapWebhookEventToStatus('reversed'))->toBe(PaymentStatus::Cancelled)
        ->and($connector->mapWebhookEventToStatus('declined'))->toBe(PaymentStatus::Failed)
        ->and($connector->mapWebhookEventToStatus('refunded'))->toBeNull()
        ->and($connector->mapWebhookEventToStatus('unknown'))->toBeNull();
});

test('extractPaymentIdFromWebhook extracts from jsonParams', function () {
    $connector = rbsConnector();

    $paymentId = $connector->extractPaymentIdFromWebhook([
        'orderNumber' => 'pay_01ABC',
        'jsonParams' => json_encode(['payment_id' => 'pay_internal_id']),
    ]);

    expect($paymentId)->toBe('pay_internal_id');
});

test('extractPaymentIdFromWebhook falls back to orderNumber', function () {
    $connector = rbsConnector();

    $paymentId = $connector->extractPaymentIdFromWebhook([
        'orderNumber' => 'pay_01ABC',
    ]);

    expect($paymentId)->toBe('pay_01ABC');
});

test('verifyWebhookSignature always returns true', function () {
    $connector = rbsConnector();

    expect($connector->verifyWebhookSignature('any payload', []))->toBeTrue();
});

// --- Webhook fixture ---

test('webhook fixture matches expected format', function () {
    $fixture = json_decode(
        file_get_contents(base_path('tests/Fixtures/Webhooks/rbs_payment_deposited.json')),
        true,
    );

    expect($fixture)
        ->toHaveKey('mdOrder')
        ->toHaveKey('orderNumber')
        ->toHaveKey('operation')
        ->and($fixture['operation'])->toBe('deposited')
        ->and($fixture['status'])->toBe(1);
});

// --- Error handling ---

test('error responses with non-zero errorCode return success false', function () {
    Http::fake([
        '3dsec.sberbank.ru/*' => Http::response([
            'errorCode' => '7',
            'errorMessage' => 'System error',
        ]),
    ]);

    $connector = rbsConnector();
    $result = $connector->capture([
        'transaction_id' => 'bad-order',
        'amount' => 10000,
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('7')
        ->and($result['message'])->toBe('System error');
});

// --- Currency mapping ---

test('currency codes are mapped to ISO 4217 numeric', function () {
    Http::fake([
        '3dsec.sberbank.ru/*' => Http::response([
            'orderId' => 'usd-order',
            'formUrl' => 'https://3dsec.sberbank.ru/payment/merchants/pay.html?mdOrder=usd-order',
        ]),
    ]);

    $connector = rbsConnector();
    $connector->purchase([
        'payment_id' => 'pay_usd',
        'amount' => 10000,
        'currency' => 'USD',
        'return_url' => 'https://merchant.com/return',
    ]);

    Http::assertSent(function ($request) {
        return $request['currency'] === '840';
    });
});

// --- testConnection ---

test('testConnection returns success when credentials are valid', function () {
    Http::fake([
        '3dsec.sberbank.ru/*' => Http::response([
            'errorCode' => '6',
            'errorMessage' => 'Order not found',
        ]),
    ]);

    $connector = rbsConnector();
    $result = $connector->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful');
});

test('testConnection returns failure on auth error', function () {
    Http::fake([
        '3dsec.sberbank.ru/*' => Http::response([
            'errorCode' => '5',
            'errorMessage' => 'Access denied',
        ]),
    ]);

    $connector = rbsConnector();
    $result = $connector->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Access denied');
});

// --- orderNumber truncation ---

test('orderNumber is truncated to 32 characters with hyphens stripped', function () {
    Http::fake([
        '3dsec.sberbank.ru/*' => Http::response([
            'orderId' => 'order-trunc',
            'formUrl' => 'https://3dsec.sberbank.ru/payment/merchants/pay.html?mdOrder=order-trunc',
        ]),
    ]);

    $connector = rbsConnector();
    // UUID-style: 36 chars with hyphens, 32 without
    $connector->purchase([
        'payment_id' => '01234567-89ab-cdef-0123-456789abcdef',
        'amount' => 10000,
        'return_url' => 'https://merchant.com/return',
    ]);

    Http::assertSent(function ($request) {
        $orderNumber = $request['orderNumber'];

        // Hyphens stripped: "0123456789abcdef0123456789abcdef" (32 chars)
        return strlen($orderNumber) === 32
            && ! str_contains($orderNumber, '-');
    });
});

test('short orderNumber passes through unchanged', function () {
    Http::fake([
        '3dsec.sberbank.ru/*' => Http::response([
            'orderId' => 'order-short',
            'formUrl' => 'https://3dsec.sberbank.ru/payment/merchants/pay.html?mdOrder=order-short',
        ]),
    ]);

    $connector = rbsConnector();
    $connector->purchase([
        'payment_id' => 'pay_123',
        'amount' => 10000,
        'return_url' => 'https://merchant.com/return',
    ]);

    Http::assertSent(function ($request) {
        // No hyphens in "pay_123", so it passes through as-is
        return $request['orderNumber'] === 'pay_123';
    });
});
