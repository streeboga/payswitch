<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\Drivers\CloudPaymentsConnector;
use Tests\TestCase;

uses(TestCase::class);

function cloudPayments3dsConnector(): CloudPaymentsConnector
{
    return new CloudPaymentsConnector(['public_id' => 'pk_test_123', 'api_secret' => 'test_secret_456']);
}

test('purchase returns requires_action when CloudPayments responds with AcsUrl', function () {
    Http::fake([
        'api.cloudpayments.ru/payments/cards/charge' => Http::response([
            'Success' => false,
            'Message' => null,
            'Model' => [
                'TransactionId' => 504_099_001,
                'AcsUrl' => 'https://acs.bank.com/3ds?md=xyz',
                'PaReq' => 'eJxVUt1ugjAUvjfxHQjX0FJANJlLnC5z',
            ],
        ]),
    ]);

    $result = cloudPayments3dsConnector()->purchase([
        'amount' => 5000,
        'currency' => 'RUB',
        'payment_method_data' => ['card' => [
            'cryptogram' => 'test_cryptogram_3ds',
            'card_holder_name' => 'JOHN DOE',
        ]],
        'ip_address' => '203.0.113.1',
        'description' => 'Test 3DS payment',
        'payment_id' => 'pay_3DS_CP_TEST',
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['code'])->toBe('requires_action');
    expect($result['transaction_id'])->toBe(504_099_001);
    expect($result['data']['redirect_url'])->toBe('https://acs.bank.com/3ds?md=xyz');
    expect($result['data']['pa_req'])->toBe('eJxVUt1ugjAUvjfxHQjX0FJANJlLnC5z');
    expect($result['data']['transaction_id'])->toBe(504_099_001);
});
