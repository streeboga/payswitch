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

test('verifyWebhookSignature returns true', function () {
    expect(yooKassaConnector()->verifyWebhookSignature('payload', []))->toBeTrue();
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
