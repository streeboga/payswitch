<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors;

use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;

final class ConnectorFactory
{
    private static array $drivers = [
        'stripe' => Drivers\StripeConnector::class,
        'cloudpayments' => Drivers\CloudPaymentsConnector::class,
        'test' => Drivers\TestConnector::class,
        // 'yookassa' => Drivers\YooKassaConnector::class,
    ];

    public static function resolve(MerchantConnectorAccount $mca): ConnectorInterface
    {
        $driverClass = self::$drivers[$mca->connector_name] ?? null;

        if (! $driverClass) {
            throw new \InvalidArgumentException("Unknown connector: {$mca->connector_name}");
        }

        $credentials = $mca->connector_account_details;
        if (is_string($credentials)) {
            $credentials = json_decode($credentials, true);
        }

        return new $driverClass($credentials);
    }

    public static function register(string $name, string $driverClass): void
    {
        self::$drivers[$name] = $driverClass;
    }
}
