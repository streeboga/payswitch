<?php

declare(strict_types=1);

use Streeboga\PaymentConnectors\Drivers\CloudPaymentsConnector;

test('getName returns cloudpayments', function () {
    $connector = new CloudPaymentsConnector(['public_id' => 'pk_test', 'api_secret' => 'secret']);
    expect($connector->getName())->toBe('cloudpayments');
});

test('connector can be instantiated with credentials', function () {
    $connector = new CloudPaymentsConnector([
        'public_id' => 'pk_test_123',
        'api_secret' => 'test_secret_456',
    ]);

    expect($connector)->toBeInstanceOf(CloudPaymentsConnector::class);
});
