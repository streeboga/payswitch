<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\Drivers\TBankConnector;
use Streeboga\PaymentConnectors\PaymentSessionResult;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\SessionResultType;
use Tests\TestCase;

uses(TestCase::class);

// --- Helpers ---

function tbankCredentials(array $overrides = []): array
{
    return array_merge([
        'terminal_key' => 'TinkoffBankTest',
        'password' => 'test_password',
        'base_url' => 'https://securepay.tinkoff.ru/v2',
    ], $overrides);
}

function tbankConnector(array $credentialOverrides = []): TBankConnector
{
    return new TBankConnector(tbankCredentials($credentialOverrides));
}

// --- Token generation ---

test('token generation produces correct SHA-256 hash', function () {
    // Known test case: Token = SHA-256 of concatenated sorted values including Password.
    // Params sent to Init: Amount=100000, Description=Test, OrderId=test_order, PayType=O, TerminalKey=TinkoffBankTest
    // With Password added: Amount, Description, OrderId, Password, PayType, TerminalKey
    // Sorted values concatenated: 100000Testtest_ordertest_passwordOTinkoffBankTest
    $connector = tbankConnector();

    $expectedToken = hash('sha256', '100000Testtest_ordertest_passwordOTinkoffBankTest');

    Http::fake([
        'securepay.tinkoff.ru/*' => Http::response([
            'Success' => true,
            'ErrorCode' => '0',
            'PaymentId' => 12345,
            'PaymentURL' => 'https://securepay.tinkoff.ru/pay/12345',
            'Status' => 'NEW',
        ]),
    ]);

    $connector->purchase([
        'payment_id' => 'test_order',
        'amount' => 100000,
        'description' => 'Test',
    ]);

    Http::assertSent(function ($request) use ($expectedToken) {
        $body = json_decode($request->body(), true);

        return $body['Token'] === $expectedToken;
    });
});

// --- Capabilities ---

test('capabilities returns correct descriptor', function () {
    $caps = TBankConnector::capabilities();

    expect($caps)->toBeInstanceOf(ConnectorCapabilities::class)
        ->and($caps->amountUnit)->toBe(AmountUnit::Kopecks)
        ->and($caps->fallbackSessionType)->toBe(SessionResultType::ServerRedirect)
        ->and($caps->directMethods)->toBeEmpty()
        ->and($caps->sbpMethod)->not->toBeNull()
        ->and($caps->sbpMethod->sessionType)->toBe(SessionResultType::QrInline)
        ->and($caps->displayName('ru'))->toBe('Т-Банк')
        ->and($caps->displayName('en'))->toBe('T-Bank');
});

// --- getName ---

test('getName returns tbank', function () {
    expect(tbankConnector()->getName())->toBe('tbank');
});

// --- purchase (Init with PayType=O) ---

test('purchase calls Init with PayType O and correct params', function () {
    Http::fake([
        'securepay.tinkoff.ru/*' => Http::response([
            'Success' => true,
            'ErrorCode' => '0',
            'PaymentId' => 99001,
            'PaymentURL' => 'https://securepay.tinkoff.ru/pay/99001',
            'Status' => 'NEW',
        ]),
    ]);

    $connector = tbankConnector();
    $result = $connector->purchase([
        'payment_id' => 'pay_purchase_123',
        'amount' => 50000,
        'description' => 'Test payment',
        'return_url' => 'https://merchant.com/success',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['transaction_id'])->toBe('99001');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/Init')
            && $body['TerminalKey'] === 'TinkoffBankTest'
            && $body['Amount'] === 50000
            && $body['OrderId'] === 'pay_purchase_123'
            && $body['PayType'] === 'O'
            && $body['SuccessURL'] === 'https://merchant.com/success';
    });
});

test('purchase returns error on failure', function () {
    Http::fake([
        'securepay.tinkoff.ru/*' => Http::response([
            'Success' => false,
            'ErrorCode' => '10',
            'Message' => 'Invalid amount',
        ]),
    ]);

    $connector = tbankConnector();
    $result = $connector->purchase([
        'payment_id' => 'pay_fail',
        'amount' => -100,
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Invalid amount')
        ->and($result['code'])->toBe('10');
});

// --- authorize (Init with PayType=T) ---

test('authorize calls Init with PayType T', function () {
    Http::fake([
        'securepay.tinkoff.ru/*' => Http::response([
            'Success' => true,
            'ErrorCode' => '0',
            'PaymentId' => 99002,
            'PaymentURL' => 'https://securepay.tinkoff.ru/pay/99002',
            'Status' => 'NEW',
        ]),
    ]);

    $connector = tbankConnector();
    $result = $connector->authorize([
        'payment_id' => 'pay_auth_456',
        'amount' => 30000,
        'description' => 'Pre-auth',
        'return_url' => 'https://merchant.com/return',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['transaction_id'])->toBe('99002');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/Init')
            && $body['PayType'] === 'T';
    });
});

// --- capture (Confirm) ---

test('capture calls Confirm with PaymentId and Amount, Amount is signed', function () {
    Http::fake([
        'securepay.tinkoff.ru/*' => Http::response([
            'Success' => true,
            'ErrorCode' => '0',
            'Status' => 'CONFIRMED',
            'PaymentId' => 99002,
        ]),
    ]);

    $connector = tbankConnector();
    $result = $connector->capture([
        'transaction_id' => '99002',
        'amount' => 30000,
    ]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();
        // Без Amount T-Bank списывает всю авторизацию, а payswitch записал бы частичное.
        $expectedToken = hash('sha256', '30000test_password99002TinkoffBankTest');

        return str_contains($request->url(), '/Confirm')
            && $body['PaymentId'] === '99002'
            && $body['Amount'] === 30000
            && $body['TerminalKey'] === 'TinkoffBankTest'
            && $body['Token'] === $expectedToken;
    });
});

// --- void (Cancel without Amount) ---

test('void calls Cancel without Amount', function () {
    Http::fake([
        'securepay.tinkoff.ru/*' => Http::response([
            'Success' => true,
            'ErrorCode' => '0',
            'Status' => 'REVERSED',
            'PaymentId' => 99003,
            'OriginalAmount' => 50000,
            'NewAmount' => 0,
        ]),
    ]);

    $connector = tbankConnector();
    $result = $connector->void([
        'transaction_id' => '99003',
    ]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/Cancel')
            && $body['PaymentId'] === '99003'
            && ! isset($body['Amount']);
    });
});

// --- refund (Cancel with Amount) ---

test('refund calls Cancel with Amount for partial refund', function () {
    Http::fake([
        'securepay.tinkoff.ru/*' => Http::response([
            'Success' => true,
            'ErrorCode' => '0',
            'Status' => 'PARTIAL_REFUNDED',
            'PaymentId' => 99004,
            'OriginalAmount' => 50000,
            'NewAmount' => 30000,
        ]),
    ]);

    $connector = tbankConnector();
    $result = $connector->refund([
        'transaction_id' => '99004',
        'amount' => 20000,
    ]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/Cancel')
            && $body['PaymentId'] === '99004'
            && $body['Amount'] === 20000;
    });
});

// --- getPaymentStatus (GetState) ---

test('getPaymentStatus calls GetState and returns data', function () {
    Http::fake([
        'securepay.tinkoff.ru/*' => Http::response([
            'Success' => true,
            'ErrorCode' => '0',
            'Status' => 'CONFIRMED',
            'PaymentId' => 99005,
            'Amount' => 10000,
        ]),
    ]);

    $connector = tbankConnector();
    $result = $connector->getPaymentStatus([
        'transaction_id' => '99005',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['transaction_id'])->toBe('99005')
        ->and($result['data']['Status'])->toBe('CONFIRMED');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/GetState');
    });
});

// --- createPaymentSession (card — serverRedirect) ---

test('createPaymentSession returns serverRedirect for card payments', function () {
    Http::fake([
        'securepay.tinkoff.ru/*' => Http::response([
            'Success' => true,
            'ErrorCode' => '0',
            'PaymentId' => 88001,
            'PaymentURL' => 'https://securepay.tinkoff.ru/pay/88001',
            'Status' => 'NEW',
        ]),
    ]);

    $connector = tbankConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_session_card',
        'amount' => 15000,
        'currency' => 'RUB',
        'return_url' => 'https://merchant.com/return',
        'description' => 'Session test',
    ]);

    expect($result)->toBeInstanceOf(PaymentSessionResult::class)
        ->and($result->type)->toBe(SessionResultType::ServerRedirect)
        ->and($result->toArray()['url'])->toBe('https://securepay.tinkoff.ru/pay/88001')
        ->and($result->toArray()['transaction_id'])->toBe('88001');
});

// --- createPaymentSession (SBP — qrInline) ---

test('createPaymentSession returns qrInline for SBP payments', function () {
    Http::fake([
        'securepay.tinkoff.ru/v2/Init' => Http::response([
            'Success' => true,
            'ErrorCode' => '0',
            'PaymentId' => 88002,
            'PaymentURL' => 'https://securepay.tinkoff.ru/pay/88002',
            'Status' => 'NEW',
        ]),
        'securepay.tinkoff.ru/v2/GetQr' => Http::response([
            'Success' => true,
            'ErrorCode' => '0',
            'Data' => '<svg>QR_CODE_SVG</svg>',
        ]),
    ]);

    $connector = tbankConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_sbp',
        'amount' => 20000,
        'currency' => 'RUB',
        'return_url' => 'https://merchant.com/return',
        'payment_method' => 'sbp',
    ]);

    expect($result)->toBeInstanceOf(PaymentSessionResult::class)
        ->and($result->type)->toBe(SessionResultType::QrInline)
        ->and($result->toArray()['qr_data'])->toBe('<svg>QR_CODE_SVG</svg>')
        ->and($result->toArray()['format'])->toBe('svg')
        ->and($result->toArray()['payment_id'])->toBe('88002')
        ->and($result->toArray()['transaction_id'])->toBe('88002');
});

test('createPaymentSession returns error when Init fails', function () {
    Http::fake([
        'securepay.tinkoff.ru/*' => Http::response([
            'Success' => false,
            'ErrorCode' => '9999',
            'Message' => 'Terminal not found',
        ]),
    ]);

    $connector = tbankConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_fail',
        'amount' => 10000,
        'return_url' => 'https://merchant.com/return',
    ]);

    expect($result)->toBeArray()
        ->and($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Terminal not found');
});

test('createPaymentSession returns error on exception', function () {
    Http::fake(function () {
        throw new RuntimeException('Connection timeout');
    });

    $connector = tbankConnector();
    $result = $connector->createPaymentSession([
        'payment_id' => 'pay_timeout',
        'amount' => 10000,
        'return_url' => 'https://merchant.com/return',
    ]);

    expect($result)->toBeArray()
        ->and($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('connector_error');
});

// --- Status mapping ---

test('mapPaymentStatusToInternal maps all T-Bank statuses', function () {
    $connector = tbankConnector();

    expect($connector->mapPaymentStatusToInternal('NEW'))->toBe(PaymentStatus::Processing)
        ->and($connector->mapPaymentStatusToInternal('AUTHORIZED'))->toBe(PaymentStatus::RequiresCapture)
        ->and($connector->mapPaymentStatusToInternal('CONFIRMED'))->toBe(PaymentStatus::Succeeded)
        ->and($connector->mapPaymentStatusToInternal('REVERSED'))->toBe(PaymentStatus::Cancelled)
        ->and($connector->mapPaymentStatusToInternal('CANCELED'))->toBe(PaymentStatus::Cancelled)
        ->and($connector->mapPaymentStatusToInternal('REFUNDED'))->toBeNull()
        ->and($connector->mapPaymentStatusToInternal('PARTIAL_REFUNDED'))->toBeNull()
        ->and($connector->mapPaymentStatusToInternal('REJECTED'))->toBe(PaymentStatus::Failed)
        ->and($connector->mapPaymentStatusToInternal('AUTH_FAIL'))->toBe(PaymentStatus::Failed)
        ->and($connector->mapPaymentStatusToInternal('DEADLINE_EXPIRED'))->toBe(PaymentStatus::Failed)
        ->and($connector->mapPaymentStatusToInternal('UNKNOWN'))->toBeNull();
});

// --- Webhook ---

test('mapWebhookEventToStatus maps T-Bank statuses', function () {
    $connector = tbankConnector();

    expect($connector->mapWebhookEventToStatus('CONFIRMED'))->toBe(PaymentStatus::Succeeded)
        ->and($connector->mapWebhookEventToStatus('AUTHORIZED'))->toBe(PaymentStatus::RequiresCapture)
        ->and($connector->mapWebhookEventToStatus('REVERSED'))->toBe(PaymentStatus::Cancelled)
        ->and($connector->mapWebhookEventToStatus('REJECTED'))->toBe(PaymentStatus::Failed)
        ->and($connector->mapWebhookEventToStatus('REFUNDED'))->toBeNull();
});

test('extractPaymentIdFromWebhook extracts OrderId', function () {
    $connector = tbankConnector();

    $paymentId = $connector->extractPaymentIdFromWebhook([
        'TerminalKey' => 'TinkoffBankTest',
        'OrderId' => 'pay_01ABC',
        'PaymentId' => 2304882,
        'Status' => 'CONFIRMED',
    ]);

    expect($paymentId)->toBe('pay_01ABC');
});

test('verifyWebhookSignature validates token correctly', function () {
    $connector = tbankConnector();

    // Build a webhook payload with correct Token
    $params = [
        'TerminalKey' => 'TinkoffBankTest',
        'OrderId' => 'pay_01ABC',
        'Success' => true,
        'Status' => 'CONFIRMED',
        'PaymentId' => 2304882,
        'ErrorCode' => '0',
        'Amount' => 10000,
    ];

    // Generate expected token: add Password, sort, concat values, sha256
    $tokenParams = $params;
    $tokenParams['Password'] = 'test_password';
    ksort($tokenParams);
    $values = implode('', array_map(
        fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v,
        array_values($tokenParams),
    ));
    $expectedToken = hash('sha256', $values);

    $params['Token'] = $expectedToken;

    $payload = json_encode($params);

    expect($connector->verifyWebhookSignature($payload, []))->toBeTrue();
});

test('token matches the worked example in the T-Bank notification docs', function () {
    // https://developer.tbank.ru/eacq/intro/developer/notification — note Success arrives as a
    // JSON boolean and is concatenated as "true".
    $params = [
        'TerminalKey' => '1234567890DEMO',
        'OrderId' => '000000',
        'Success' => true,
        'Status' => 'AUTHORIZED',
        'PaymentId' => '0000000',
        'ErrorCode' => '0',
        'Amount' => '1111',
        'CardId' => '000000',
        'Pan' => '200000******0000',
        'ExpDate' => '1111',
        'RebillId' => '000000',
    ];
    $params['Token'] = '1c0964277d0213349243065a0d5b838b8e90d2d25f740d0f2767836e710e80c8';

    $connector = tbankConnector(['password' => '11111111111']);

    expect($connector->verifyWebhookSignature((string) json_encode($params), []))->toBeTrue();
});

test('verifyWebhookSignature rejects a flipped Success flag', function () {
    $params = [
        'TerminalKey' => '1234567890DEMO',
        'OrderId' => '000000',
        'Success' => false,
        'Status' => 'AUTHORIZED',
        'PaymentId' => '0000000',
        'ErrorCode' => '0',
        'Amount' => '1111',
        'CardId' => '000000',
        'Pan' => '200000******0000',
        'ExpDate' => '1111',
        'RebillId' => '000000',
        'Token' => '1c0964277d0213349243065a0d5b838b8e90d2d25f740d0f2767836e710e80c8',
    ];

    expect(tbankConnector(['password' => '11111111111'])->verifyWebhookSignature((string) json_encode($params), []))->toBeFalse();
});

test('verifyWebhookSignature rejects invalid token', function () {
    $connector = tbankConnector();

    $params = [
        'TerminalKey' => 'TinkoffBankTest',
        'OrderId' => 'pay_01ABC',
        'Status' => 'CONFIRMED',
        'PaymentId' => 2304882,
        'Token' => 'invalid_token_hash',
    ];

    $payload = json_encode($params);

    expect($connector->verifyWebhookSignature($payload, []))->toBeFalse();
});

test('verifyWebhookSignature rejects payload without Token', function () {
    $connector = tbankConnector();

    $params = [
        'TerminalKey' => 'TinkoffBankTest',
        'OrderId' => 'pay_01ABC',
    ];

    $payload = json_encode($params);

    expect($connector->verifyWebhookSignature($payload, []))->toBeFalse();
});

// --- Webhook fixture ---

test('webhook fixture matches expected format', function () {
    $fixture = json_decode(
        file_get_contents(base_path('tests/Fixtures/Webhooks/tbank_payment_confirmed.json')),
        true,
    );

    expect($fixture)
        ->toHaveKey('TerminalKey')
        ->toHaveKey('OrderId')
        ->toHaveKey('PaymentId')
        ->toHaveKey('Status')
        ->toHaveKey('Token')
        ->and($fixture['Status'])->toBe('CONFIRMED')
        ->and($fixture['Success'])->toBeTrue();
});

// --- Amount is passed as kopecks (integer, no conversion) ---

test('amount is passed as kopecks without conversion', function () {
    Http::fake([
        'securepay.tinkoff.ru/*' => Http::response([
            'Success' => true,
            'ErrorCode' => '0',
            'PaymentId' => 77001,
            'PaymentURL' => 'https://securepay.tinkoff.ru/pay/77001',
            'Status' => 'NEW',
        ]),
    ]);

    $connector = tbankConnector();
    $connector->purchase([
        'payment_id' => 'pay_kopecks',
        'amount' => 10050,
        'description' => 'Kopecks test',
    ]);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['Amount'] === 10050;
    });
});

// --- OrderId truncation ---

test('orderId is truncated to 36 characters', function () {
    Http::fake([
        'securepay.tinkoff.ru/*' => Http::response([
            'Success' => true,
            'ErrorCode' => '0',
            'PaymentId' => 77002,
            'PaymentURL' => 'https://securepay.tinkoff.ru/pay/77002',
            'Status' => 'NEW',
        ]),
    ]);

    $connector = tbankConnector();
    $longId = str_repeat('a', 50);
    $connector->purchase([
        'payment_id' => $longId,
        'amount' => 1000,
    ]);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return strlen($body['OrderId']) === 36;
    });
});

// --- testConnection ---

test('testConnection returns success when credentials are valid', function () {
    Http::fake([
        'securepay.tinkoff.ru/*' => Http::response([
            'Success' => false,
            'ErrorCode' => '102',
            'Message' => 'Payment not found',
        ]),
    ]);

    $connector = tbankConnector();
    $result = $connector->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful');
});

test('testConnection returns failure on auth error', function () {
    Http::fake([
        'securepay.tinkoff.ru/*' => Http::response([
            'Success' => false,
            'ErrorCode' => '7',
            'Message' => 'Ошибка авторизации',
        ]),
    ]);

    $connector = tbankConnector();
    $result = $connector->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Ошибка авторизации');
});

// --- Token generation with nested objects ---

test('verifyWebhookSignature ignores nested objects in token generation', function () {
    $connector = tbankConnector();

    // Webhook payload with nested Receipt and DATA objects — these must be
    // excluded from token generation per T-Bank docs.
    $scalarParams = [
        'TerminalKey' => 'TinkoffBankTest',
        'OrderId' => 'pay_receipt',
        'Success' => true,
        'Status' => 'CONFIRMED',
        'PaymentId' => 5550001,
        'ErrorCode' => '0',
        'Amount' => 50000,
    ];

    // Generate expected token from scalar fields only
    $tokenParams = $scalarParams;
    $tokenParams['Password'] = 'test_password';
    ksort($tokenParams);
    $values = implode('', array_map(
        fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v,
        array_values($tokenParams),
    ));
    $expectedToken = hash('sha256', $values);

    // Add nested objects AFTER computing the token — they should be ignored
    $params = $scalarParams;
    $params['Receipt'] = ['Items' => [['Name' => 'Test', 'Price' => 50000]]];
    $params['DATA'] = ['Phone' => '+79001234567'];
    $params['Token'] = $expectedToken;

    $payload = json_encode($params);

    expect($connector->verifyWebhookSignature($payload, []))->toBeTrue();
});

// --- Error handling ---

test('connector error on network failure returns proper structure', function () {
    Http::fake(function () {
        throw new RuntimeException('DNS resolution failed');
    });

    $connector = tbankConnector();
    $result = $connector->purchase([
        'payment_id' => 'pay_net_fail',
        'amount' => 10000,
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe('connector_error')
        ->and($result['message'])->toBe('DNS resolution failed');
});
