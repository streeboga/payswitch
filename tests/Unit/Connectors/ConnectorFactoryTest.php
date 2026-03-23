<?php

declare(strict_types=1);

use Streeboga\PaymentConnectors\ConnectorFactory;
use Streeboga\PaymentConnectors\Drivers\CloudPaymentsConnector;
use Streeboga\PaymentConnectors\Drivers\StripeConnector;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;

beforeEach(function () {
    // Unit tests have no Laravel config — register drivers manually
    ConnectorFactory::flush();
    ConnectorFactory::register('stripe', StripeConnector::class);
});

test('ConnectorFactory resolves StripeConnector for stripe', function () {
    $mca = Mockery::mock(MerchantConnectorAccount::class)->makePartial();
    $mca->shouldReceive('getAttribute')->with('connector_name')->andReturn('stripe');
    $mca->shouldReceive('getAttribute')->with('connector_account_details')->andReturn('{"api_key":"sk_test_123"}');

    $connector = ConnectorFactory::resolve($mca);

    expect($connector)->toBeInstanceOf(StripeConnector::class);
});

test('ConnectorFactory throws for unknown connector', function () {
    $mca = Mockery::mock(MerchantConnectorAccount::class)->makePartial();
    $mca->shouldReceive('getAttribute')->with('connector_name')->andReturn('unknown_gateway');

    ConnectorFactory::resolve($mca);
})->throws(InvalidArgumentException::class, 'Unknown connector: unknown_gateway');

test('StripeConnector getName returns stripe', function () {
    $mca = Mockery::mock(MerchantConnectorAccount::class)->makePartial();
    $mca->shouldReceive('getAttribute')->with('connector_name')->andReturn('stripe');
    $mca->shouldReceive('getAttribute')->with('connector_account_details')->andReturn('{"api_key":"sk_test_123"}');

    $connector = ConnectorFactory::resolve($mca);

    expect($connector->getName())->toBe('stripe');
});

test('ConnectorFactory::register adds drivers at runtime', function () {
    expect(ConnectorFactory::resolveClass('newpsp'))->toBeNull();

    ConnectorFactory::register('newpsp', StripeConnector::class);

    expect(ConnectorFactory::resolveClass('newpsp'))->toBe(StripeConnector::class);
});

test('ConnectorFactory::registered returns all driver names', function () {
    ConnectorFactory::register('cloudpayments', CloudPaymentsConnector::class);

    $names = ConnectorFactory::registered();

    expect($names)->toContain('stripe');
    expect($names)->toContain('cloudpayments');
});
