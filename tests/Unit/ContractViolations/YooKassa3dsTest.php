<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\Drivers\YooKassaConnector;
use Tests\TestCase;

uses(TestCase::class);

function yooKassa3dsConnector(): YooKassaConnector
{
    return new YooKassaConnector(['shop_id' => '123456', 'secret_key' => 'test_secret']);
}

test('purchase returns requires_action when YooKassa responds with pending and confirmation_url', function () {
    Http::fake([
        'api.yookassa.ru/v3/payments' => Http::response([
            'id' => 'yk_txn_3ds_001',
            'status' => 'pending',
            'confirmation' => [
                'type' => 'redirect',
                'confirmation_url' => 'https://yookassa.ru/3ds/confirm?token=abc123',
            ],
            'amount' => ['value' => '50.00', 'currency' => 'RUB'],
        ]),
    ]);

    $result = yooKassa3dsConnector()->purchase([
        'amount' => 5000,
        'currency' => 'RUB',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
        'description' => 'Test 3DS payment',
        'payment_id' => 'pay_3DS_TEST',
        'return_url' => 'https://example.com/return',
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['code'])->toBe('requires_action');
    expect($result['transaction_id'])->toBe('yk_txn_3ds_001');
    expect($result['data']['redirect_url'])->toBe('https://yookassa.ru/3ds/confirm?token=abc123');
});

test('authorize returns requires_action when YooKassa responds with pending and confirmation_url', function () {
    Http::fake([
        'api.yookassa.ru/v3/payments' => Http::response([
            'id' => 'yk_txn_3ds_002',
            'status' => 'pending',
            'confirmation' => [
                'type' => 'redirect',
                'confirmation_url' => 'https://yookassa.ru/3ds/confirm?token=def456',
            ],
            'amount' => ['value' => '100.00', 'currency' => 'RUB'],
        ]),
    ]);

    $result = yooKassa3dsConnector()->authorize([
        'amount' => 10000,
        'currency' => 'RUB',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
        'payment_id' => 'pay_3DS_AUTH',
        'return_url' => 'https://example.com/return',
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['code'])->toBe('requires_action');
    expect($result['transaction_id'])->toBe('yk_txn_3ds_002');
    expect($result['data']['redirect_url'])->toBe('https://yookassa.ru/3ds/confirm?token=def456');
});
