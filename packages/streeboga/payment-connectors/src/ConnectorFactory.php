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
        'yookassa' => Drivers\YooKassaConnector::class,
    ];

    /**
     * Resolve the driver class name without instantiation.
     *
     * @return class-string<ConnectorInterface>|null
     */
    public static function resolveClass(string $connectorName): ?string
    {
        return self::$drivers[$connectorName] ?? null;
    }

    public static function resolve(MerchantConnectorAccount $mca): ConnectorInterface
    {
        $driverClass = self::$drivers[$mca->connector_name] ?? null;

        if (! $driverClass) {
            throw new \InvalidArgumentException("Unknown connector: {$mca->connector_name}");
        }

        $credentials = $mca->connector_account_details;
        // Already an array from encrypted:array cast
        if (! is_array($credentials)) {
            $credentials = [];
        }

        return new $driverClass($credentials);
    }

    public static function register(string $name, string $driverClass): void
    {
        self::$drivers[$name] = $driverClass;
    }
}
