<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\Drivers\CloudPaymentsConnector;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Tests\TestCase;

uses(TestCase::class);

function cloudPaymentsConnector(): CloudPaymentsConnector
{
    return new CloudPaymentsConnector(['public_id' => 'pk_test_123', 'api_secret' => 'test_secret_456']);
}

test('getName returns cloudpayments', function () {
    expect(cloudPaymentsConnector()->getName())->toBe('cloudpayments');
});

test('purchase sends correct request and parses success', function () {
    Http::fake([
        'api.cloudpayments.ru/payments/cards/charge' => Http::response([
            'Success' => true,
            'Model' => [
                'TransactionId' => 504_012_345,
                'Amount' => 50.00,
                'Currency' => 'RUB',
            ],
            'Message' => null,
        ]),
    ]);

    $result = cloudPaymentsConnector()->purchase([
        'amount' => 5000,
        'currency' => 'RUB',
        'payment_method_data' => ['card' => [
            'cryptogram' => 'test_cryptogram_packet',
            'card_holder_name' => 'JOHN DOE',
        ]],
        'ip_address' => '203.0.113.1',
        'description' => 'Test payment',
        'payment_id' => 'pay_01TEST',
        'metadata' => ['order' => 'ORD-123'],
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['transaction_id'])->toBe(504_012_345);
    expect($result['code'])->toBe('ok');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/payments/cards/charge')
            && $request['Amount'] == 50
            && $request['Currency'] === 'RUB'
            && $request['CardCryptogramPacket'] === 'test_cryptogram_packet'
            && $request['Name'] === 'JOHN DOE'
            && $request['IpAddress'] === '203.0.113.1'
            && $request['Description'] === 'Test payment'
            && $request['InvoiceId'] === 'pay_01TEST';
    });
});

test('authorize sends request to /payments/cards/auth', function () {
    Http::fake([
        'api.cloudpayments.ru/payments/cards/auth' => Http::response([
            'Success' => true,
            'Model' => [
                'TransactionId' => 504_012_999,
                'Amount' => 100.00,
                'Currency' => 'RUB',
            ],
        ]),
    ]);

    $result = cloudPaymentsConnector()->authorize([
        'amount' => 10000,
        'currency' => 'RUB',
        'payment_method_data' => ['card' => [
            'cryptogram' => 'auth_cryptogram',
            'card_holder_name' => 'JANE DOE',
        ]],
        'ip_address' => '203.0.113.2',
        'description' => 'Auth payment',
        'payment_id' => 'pay_02TEST',
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['transaction_id'])->toBe(504_012_999);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/payments/cards/auth');
    });
});

test('capture sends correct request', function () {
    Http::fake([
        'api.cloudpayments.ru/payments/confirm' => Http::response([
            'Success' => true,
            'Model' => [
                'TransactionId' => 504_012_999,
            ],
        ]),
    ]);

    $result = cloudPaymentsConnector()->capture([
        'transaction_id' => 504_012_999,
        'amount' => 10000,
    ]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/payments/confirm')
            && $request['TransactionId'] == 504_012_999
            && $request['Amount'] == 100;
    });
});

test('refund sends correct request', function () {
    Http::fake([
        'api.cloudpayments.ru/payments/refund' => Http::response([
            'Success' => true,
            'Model' => [
                'TransactionId' => 504_012_345,
            ],
        ]),
    ]);

    $result = cloudPaymentsConnector()->refund([
        'transaction_id' => 504_012_345,
        'amount' => 3000,
    ]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/payments/refund')
            && $request['TransactionId'] == 504_012_345
            && $request['Amount'] == 30;
    });
});

test('void sends request to /payments/void', function () {
    Http::fake([
        'api.cloudpayments.ru/payments/void' => Http::response([
            'Success' => true,
            'Model' => [
                'TransactionId' => 504_012_999,
            ],
        ]),
    ]);

    $result = cloudPaymentsConnector()->void([
        'transaction_id' => 504_012_999,
    ]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/payments/void')
            && $request['TransactionId'] == 504_012_999;
    });
});

test('handles failed payment response', function () {
    Http::fake([
        'api.cloudpayments.ru/payments/cards/charge' => Http::response([
            'Success' => false,
            'Message' => 'Declined',
            'Model' => [
                'TransactionId' => 504_000_111,
                'ReasonCode' => 5051,
                'CardHolderMessage' => 'Insufficient funds',
            ],
        ]),
    ]);

    $result = cloudPaymentsConnector()->purchase([
        'amount' => 5000,
        'currency' => 'RUB',
        'payment_method_data' => ['card' => ['cryptogram' => 'bad_crypto']],
        'payment_id' => 'pay_03TEST',
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['code'])->toBe(5051);
    expect($result['transaction_id'])->toBe(504_000_111);
});

test('handles server error gracefully', function () {
    Http::fake([
        'api.cloudpayments.ru/payments/cards/charge' => Http::response(null, 500),
    ]);

    $result = cloudPaymentsConnector()->purchase([
        'amount' => 5000,
        'currency' => 'RUB',
        'payment_method_data' => ['card' => ['cryptogram' => 'test']],
        'payment_id' => 'pay_04TEST',
    ]);

    expect($result['success'])->toBeFalse();
});

test('verifyWebhookSignature validates correct HMAC-SHA256', function () {
    $connector = cloudPaymentsConnector();
    $payload = '{"TransactionId":504012345,"InvoiceId":"pay_01TEST","Amount":50.00}';
    $secret = 'test_secret_456';
    $validHmac = base64_encode(hash_hmac('sha256', $payload, $secret, true));

    expect($connector->verifyWebhookSignature($payload, ['content-hmac' => $validHmac]))->toBeTrue();
});

test('verifyWebhookSignature rejects invalid signature', function () {
    $connector = cloudPaymentsConnector();
    $payload = '{"TransactionId":504012345}';

    expect($connector->verifyWebhookSignature($payload, ['content-hmac' => 'invalid_base64_hmac']))->toBeFalse();
});

test('verifyWebhookSignature rejects a missing Content-HMAC header by default', function () {
    config()->set('payswitch.allow_unsigned_webhooks', false);

    expect(cloudPaymentsConnector()->verifyWebhookSignature('{"Amount":100}', []))->toBeFalse();
});

test('verifyWebhookSignature rejects a missing header regardless of APP_ENV', function () {
    config()->set('payswitch.allow_unsigned_webhooks', false);
    app()->detectEnvironment(fn () => 'local');

    expect(cloudPaymentsConnector()->verifyWebhookSignature('{"Amount":100}', []))->toBeFalse();
});

test('verifyWebhookSignature allows a missing header only when the flag says so', function () {
    config()->set('payswitch.allow_unsigned_webhooks', true);

    expect(cloudPaymentsConnector()->verifyWebhookSignature('{"Amount":100}', []))->toBeTrue();
});

test('mapWebhookEventToStatus maps correctly', function () {
    $c = cloudPaymentsConnector();

    expect($c->mapWebhookEventToStatus('payment.succeeded'))->toBe(PaymentStatus::Succeeded);
    expect($c->mapWebhookEventToStatus('payment.canceled'))->toBe(PaymentStatus::Cancelled);
    expect($c->mapWebhookEventToStatus('payment.waiting_for_capture'))->toBe(PaymentStatus::RequiresCapture);
    expect($c->mapWebhookEventToStatus('unknown.event'))->toBeNull();
});

test('getPaymentStatus asks /payments/get by TransactionId and exposes Model.Status', function () {
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/CloudPayments/payments_get_completed.json'), true);
    Http::fake(['api.cloudpayments.ru/payments/get' => Http::response($fixture)]);

    $c = cloudPaymentsConnector();
    $result = $c->getPaymentStatus(['transaction_id' => '504735239']);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.cloudpayments.ru/payments/get'
        && $request['TransactionId'] === '504735239');
    expect($result['success'])->toBeTrue()
        ->and($result['transaction_id'])->toBe(504735239)
        ->and($result['data']['status'])->toBe('Completed')
        ->and($c->mapPaymentStatusToInternal($result['data']['status']))->toBe(PaymentStatus::Succeeded);
});

test('getPaymentStatus without a transaction id finds the payment by InvoiceId', function () {
    Http::fake(['api.cloudpayments.ru/v2/payments/find' => Http::response([
        'Success' => true,
        'Model' => [
            ['TransactionId' => 1, 'InvoiceId' => 'pay_01X', 'Status' => 'Declined'],
            ['TransactionId' => 2, 'InvoiceId' => 'pay_01X', 'Status' => 'Completed'],
        ],
    ])]);

    $result = cloudPaymentsConnector()->getPaymentStatus(['payment_id' => 'pay_01X']);

    Http::assertSent(fn ($request) => $request['InvoiceId'] === 'pay_01X');
    expect($result['transaction_id'])->toBe(2)
        ->and($result['data']['status'])->toBe('Completed');
});

test('mapPaymentStatusToInternal knows every Model.Status', function () {
    $c = cloudPaymentsConnector();

    expect($c->mapPaymentStatusToInternal('AwaitingAuthentication'))->toBe(PaymentStatus::RequiresCustomerAction)
        ->and($c->mapPaymentStatusToInternal('Authorized'))->toBe(PaymentStatus::RequiresCapture)
        ->and($c->mapPaymentStatusToInternal('Completed'))->toBe(PaymentStatus::Succeeded)
        ->and($c->mapPaymentStatusToInternal('Cancelled'))->toBe(PaymentStatus::Cancelled)
        ->and($c->mapPaymentStatusToInternal('Declined'))->toBe(PaymentStatus::Failed);
});

test('extractPaymentIdFromWebhook extracts InvoiceId', function () {
    $c = cloudPaymentsConnector();

    // Direct InvoiceId
    expect($c->extractPaymentIdFromWebhook(['InvoiceId' => 'pay_01ABC']))->toBe('pay_01ABC');

    // Nested in data
    expect($c->extractPaymentIdFromWebhook(['data' => ['InvoiceId' => 'pay_02DEF']]))->toBe('pay_02DEF');

    // Missing
    expect($c->extractPaymentIdFromWebhook(['other' => 'value']))->toBeNull();
});

test('uses basic auth with publicId and apiSecret', function () {
    Http::fake([
        'api.cloudpayments.ru/payments/cards/charge' => Http::response([
            'Success' => true,
            'Model' => ['TransactionId' => 1],
        ]),
    ]);

    cloudPaymentsConnector()->purchase([
        'amount' => 1000,
        'currency' => 'RUB',
        'payment_method_data' => ['card' => ['cryptogram' => 'test']],
        'payment_id' => 'pay_05TEST',
    ]);

    Http::assertSent(function ($request) {
        $authHeader = $request->header('Authorization')[0] ?? '';

        return str_starts_with($authHeader, 'Basic ');
    });
});
