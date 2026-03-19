<?php

declare(strict_types=1);

use Streeboga\PaymentConnectors\Drivers\TestConnector;

test('purchase with default card succeeds', function () {
    $connector = new TestConnector;
    $result = $connector->purchase(['amount' => 5000, 'currency' => 'USD']);

    expect($result['success'])->toBeTrue();
    expect($result['transaction_id'])->not->toBeNull();
});

test('purchase with decline card fails', function () {
    $connector = new TestConnector;
    $result = $connector->purchase([
        'card_number' => '4000000000000002',
        'amount' => 5000,
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['code'])->toBe('card_declined');
});

test('purchase with insufficient funds card fails', function () {
    $connector = new TestConnector;
    $result = $connector->purchase([
        'card_number' => '4000000000009995',
        'amount' => 5000,
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['code'])->toBe('insufficient_funds');
});

test('authorize succeeds and returns auth transaction id', function () {
    $connector = new TestConnector;
    $result = $connector->authorize(['amount' => 5000, 'currency' => 'USD']);

    expect($result['success'])->toBeTrue();
    expect($result['transaction_id'])->toContain('test_auth_');
});

test('capture succeeds', function () {
    $connector = new TestConnector;
    $result = $connector->capture(['amount' => 5000, 'transaction_id' => 'test_auth_123']);

    expect($result['success'])->toBeTrue();
});

test('refund succeeds', function () {
    $connector = new TestConnector;
    $result = $connector->refund(['amount' => 3000, 'transaction_id' => 'test_ch_123']);

    expect($result['success'])->toBeTrue();
});

test('void succeeds', function () {
    $connector = new TestConnector;
    $result = $connector->void(['transaction_id' => 'test_auth_123']);

    expect($result['success'])->toBeTrue();
    expect($result['transaction_id'])->toContain('test_void_');
});

test('getName returns test', function () {
    expect((new TestConnector)->getName())->toBe('test');
});
