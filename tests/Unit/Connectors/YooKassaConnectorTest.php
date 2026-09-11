<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\Drivers\YooKassaConnector;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Tests\TestCase;

uses(TestCase::class);

function yooKassaConnector(): YooKassaConnector
{
    return new YooKassaConnector(['shop_id' => '123456', 'secret_key' => 'test_secret']);
}

test('getName returns yookassa', function () {
    expect(yooKassaConnector()->getName())->toBe('yookassa');
});

test('purchase sends correct request and parses success', function () {
    Http::fake([
        'api.yookassa.ru/v3/payments' => Http::response([
            'id' => 'yk_txn_abc123',
            'status' => 'succeeded',
            'amount' => ['value' => '50.00', 'currency' => 'RUB'],
        ]),
    ]);

    $result = yooKassaConnector()->purchase([
        'amount' => 5000,
        'currency' => 'RUB',
        'payment_method' => 'card',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
        'description' => 'Test payment',
        'payment_id' => 'pay_01TEST',
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['transaction_id'])->toBe('yk_txn_abc123');
    expect($result['code'])->toBe('ok');
});

test('authorize sends capture=false', function () {
    Http::fake([
        'api.yookassa.ru/v3/payments' => Http::response([
            'id' => 'yk_txn_auth456',
            'status' => 'waiting_for_capture',
            'amount' => ['value' => '100.00', 'currency' => 'RUB'],
        ]),
    ]);

    $result = yooKassaConnector()->authorize([
        'amount' => 10000,
        'currency' => 'RUB',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
        'payment_id' => 'pay_02TEST',
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['transaction_id'])->toBe('yk_txn_auth456');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['capture'] === false;
    });
});

test('capture sends correct request', function () {
    Http::fake([
        'api.yookassa.ru/v3/payments/yk_txn_auth456/capture' => Http::response([
            'id' => 'yk_txn_auth456',
            'status' => 'succeeded',
        ]),
    ]);

    $result = yooKassaConnector()->capture([
        'amount' => 10000,
        'currency' => 'RUB',
        'transaction_id' => 'yk_txn_auth456',
    ]);

    expect($result['success'])->toBeTrue();
});

test('refund sends correct request', function () {
    Http::fake([
        'api.yookassa.ru/v3/refunds' => Http::response([
            'id' => 'yk_ref_789',
            'status' => 'succeeded',
            'payment_id' => 'yk_txn_abc123',
        ]),
    ]);

    $result = yooKassaConnector()->refund([
        'amount' => 3000,
        'currency' => 'RUB',
        'transaction_id' => 'yk_txn_abc123',
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['transaction_id'])->toBe('yk_ref_789');
});

test('void sends cancel request to YooKassa', function () {
    Http::fake([
        'api.yookassa.ru/v3/payments/yk_txn_auth456/cancel' => Http::response([
            'id' => 'yk_txn_auth456',
            'status' => 'canceled',
        ]),
    ]);

    $result = yooKassaConnector()->void([
        'transaction_id' => 'yk_txn_auth456',
        'payment_id' => 'pay_VOID_TEST',
    ]);

    expect($result['success'])->toBeFalse(); // YooKassa 'canceled' maps to not-success
    expect($result['transaction_id'])->toBe('yk_txn_auth456');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/payments/yk_txn_auth456/cancel')
            && $request->hasHeader('Idempotence-Key')
            && $request->header('Idempotence-Key')[0] === 'pay_VOID_TEST_void';
    });
});

test('handles failed payment', function () {
    Http::fake([
        'api.yookassa.ru/v3/payments' => Http::response([
            'id' => 'yk_txn_fail',
            'status' => 'canceled',
            'cancellation_details' => [
                'party' => 'issuer',
                'reason' => 'insufficient_funds',
            ],
        ]),
    ]);

    $result = yooKassaConnector()->purchase([
        'amount' => 5000,
        'currency' => 'RUB',
        'payment_method_data' => ['card' => ['card_number' => '4242424242424242', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
        'payment_id' => 'pay_03TEST',
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['code'])->toBe('insufficient_funds');
});

test('handles api error response', function () {
    Http::fake([
        'api.yookassa.ru/v3/payments' => Http::response([
            'type' => 'error',
            'code' => 'invalid_request',
            'description' => 'Missing required parameter',
        ], 400),
    ]);

    $result = yooKassaConnector()->purchase([
        'amount' => 5000,
        'currency' => 'RUB',
        'payment_method_data' => ['card' => []],
        'payment_id' => 'pay_04TEST',
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['code'])->toBe('invalid_request');
    expect($result['message'])->toBe('Missing required parameter');
});

test('handles server error response', function () {
    Http::fake([
        'api.yookassa.ru/v3/payments' => Http::response(null, 500),
    ]);

    $result = yooKassaConnector()->purchase([
        'amount' => 5000,
        'currency' => 'RUB',
        'payment_method_data' => ['card' => []],
        'payment_id' => 'pay_05TEST',
    ]);

    expect($result['success'])->toBeFalse();
});

test('mapWebhookEventToStatus maps correctly', function () {
    $c = yooKassaConnector();

    expect($c->mapWebhookEventToStatus('payment.succeeded'))->toBe(PaymentStatus::Succeeded);
    expect($c->mapWebhookEventToStatus('payment.canceled'))->toBe(PaymentStatus::Cancelled);
    expect($c->mapWebhookEventToStatus('payment.waiting_for_capture'))->toBe(PaymentStatus::RequiresCapture);
    expect($c->mapWebhookEventToStatus('unknown.event'))->toBeNull();
});

test('extractPaymentIdFromWebhook extracts payment_id', function () {
    $c = yooKassaConnector();

    expect($c->extractPaymentIdFromWebhook([
        'object' => ['metadata' => ['payment_id' => 'pay_01ABC']],
    ]))->toBe('pay_01ABC');

    expect($c->extractPaymentIdFromWebhook(['object' => []]))->toBeNull();
});

// --- Webhook authenticity (YooKassa signs nothing; the source IP is all there is) ---

test('verifyWebhookSignature accepts notifications from published YooKassa addresses', function (string $ip) {
    expect(yooKassaConnector()->verifyWebhookSignature('{}', [YooKassaConnector::SOURCE_IP_HEADER => $ip]))->toBeTrue();
})->with(['185.71.76.5', '185.71.77.30', '77.75.153.100', '77.75.156.11', '77.75.156.35', '77.75.154.200', '2a02:5180::1']);

test('verifyWebhookSignature rejects notifications from anywhere else', function (string $ip) {
    expect(yooKassaConnector()->verifyWebhookSignature('{}', [YooKassaConnector::SOURCE_IP_HEADER => $ip]))->toBeFalse();
})->with(['1.2.3.4', '185.71.76.32', '77.75.156.12', '77.75.154.127', '2a03:5180::1', '127.0.0.1']);

test('verifyWebhookSignature rejects a notification with no source address', function () {
    expect(yooKassaConnector()->verifyWebhookSignature('{}', []))->toBeFalse();
});

test('verifyWebhookSignature honours a webhook_ips override', function () {
    $connector = new YooKassaConnector(['shop_id' => '1', 'secret_key' => 's', 'webhook_ips' => ['10.0.0.0/8']]);

    expect($connector->verifyWebhookSignature('{}', [YooKassaConnector::SOURCE_IP_HEADER => '10.1.2.3']))->toBeTrue()
        ->and($connector->verifyWebhookSignature('{}', [YooKassaConnector::SOURCE_IP_HEADER => '185.71.76.5']))->toBeFalse();
});

test('verifyWebhookSignature rejects everything when the override is empty', function () {
    $connector = new YooKassaConnector(['shop_id' => '1', 'secret_key' => 's', 'webhook_ips' => []]);

    expect($connector->verifyWebhookSignature('{}', [YooKassaConnector::SOURCE_IP_HEADER => '185.71.76.5']))->toBeFalse();
});

test('purchase uses payment_id as Idempotence-Key', function () {
    Http::fake([
        'api.yookassa.ru/v3/payments' => Http::response([
            'id' => 'yk_txn_idem', 'status' => 'succeeded',
        ]),
    ]);

    yooKassaConnector()->purchase([
        'amount' => 5000,
        'currency' => 'RUB',
        'payment_method_data' => ['card' => ['card_number' => '4242424242424242', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
        'payment_id' => 'pay_IDEM_TEST',
    ]);

    Http::assertSent(function ($request) {
        return $request->hasHeader('Idempotence-Key')
            && $request->header('Idempotence-Key')[0] === 'pay_IDEM_TEST';
    });
});

test('purchase with token uses payment_method_id', function () {
    Http::fake([
        'api.yookassa.ru/v3/payments' => Http::response([
            'id' => 'yk_txn_token',
            'status' => 'succeeded',
        ]),
    ]);

    yooKassaConnector()->purchase([
        'amount' => 5000,
        'currency' => 'RUB',
        'token' => 'pm_saved_card_123',
        'payment_id' => 'pay_06TEST',
    ]);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return ($body['payment_method_id'] ?? null) === 'pm_saved_card_123'
            && ! isset($body['payment_method_data']);
    });
});
