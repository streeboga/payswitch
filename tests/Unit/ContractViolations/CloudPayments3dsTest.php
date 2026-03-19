<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\Drivers\CloudPaymentsConnector;
use Tests\TestCase;

uses(TestCase::class);

function cloudPayments3dsConnector(): CloudPaymentsConnector
{
    return new CloudPaymentsConnector(['public_id' => 'pk_test', 'api_secret' => 'test_secret']);
}

test('purchase returns requires_action when AcsUrl present', function () {
    Http::fake([
        'api.cloudpayments.ru/payments/cards/charge' => Http::response([
            'Success' => false,
            'Message' => '3-D Secure is required',
            'Model' => [
                'TransactionId' => 504735239,
                'PaReq' => 'eJxVUt1ugjAUvjfxHYi3QEGUaOIygRp1c3Nm7g6UWqGj0NIC+vYrOF12cv58X77zJdVFKxo=',
                'AcsUrl' => 'https://bank.com/3ds',
            ],
        ]),
    ]);

    $result = cloudPayments3dsConnector()->purchase([
        'amount' => 5000,
        'currency' => 'RUB',
        'payment_method_data' => ['card' => [
            'cryptogram' => 'test_cryptogram_packet',
            'card_holder_name' => 'JOHN DOE',
        ]],
        'description' => 'Test 3DS payment',
        'payment_id' => 'pay_3ds_cp_test',
        'ip_address' => '203.0.113.1',
    ]);

    expect($result['code'])->toBe('requires_action');
    expect($result['data']['redirect_url'])->toBe('https://bank.com/3ds');
    expect($result['data']['pa_req'])->not->toBeEmpty();
})->skip('BUG #3: CloudPayments AcsUrl in response not detected as 3DS');
