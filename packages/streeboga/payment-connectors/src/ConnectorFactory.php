<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors;

use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;

final class ConnectorFactory
{
    /** @var array<string, class-string<ConnectorInterface>> */
    private static array $drivers = [];

    private static bool $booted = false;

    /**
     * Boot from config. Called by PaymentConnectorsServiceProvider.
     *
     * @param  array<string, class-string<ConnectorInterface>>  $connectors
     */
    public static function boot(array $connectors): void
    {
        self::$drivers = array_merge(self::$drivers, $connectors);
        self::$booted = true;
    }

    /**
     * Register a single connector at runtime (e.g. from a package ServiceProvider).
     *
     * @param  class-string<ConnectorInterface>  $driverClass
     */
    public static function register(string $name, string $driverClass): void
    {
        self::$drivers[$name] = $driverClass;
    }

    /**
     * Resolve a connector driver class name without instantiation.
     *
     * @return class-string<ConnectorInterface>|null
     */
    public static function resolveClass(string $connectorName): ?string
    {
        self::ensureBooted();

        return self::$drivers[$connectorName] ?? null;
    }

    /**
     * Resolve and instantiate a connector for the given merchant account.
     */
    public static function resolve(MerchantConnectorAccount $mca): ConnectorInterface
    {
        self::ensureBooted();

        $driverClass = self::$drivers[$mca->connector_name] ?? null;

        if (! $driverClass) {
            throw new \InvalidArgumentException("Unknown connector: {$mca->connector_name}");
        }

        $credentials = $mca->connector_account_details;
        if (! is_array($credentials)) {
            $credentials = [];
        }

        return new $driverClass($credentials);
    }

    /**
     * Get all registered connector names.
     *
     * @return array<string>
     */
    public static function registered(): array
    {
        self::ensureBooted();

        return array_keys(self::$drivers);
    }

    /**
     * Lazy-boot from config if not yet booted.
     */
    private static function ensureBooted(): void
    {
        if (self::$booted) {
            return;
        }

        // Boot from config if Laravel app is available
        if (function_exists('config') && app()->bound('config')) {
            self::boot(config('payswitch.connectors', []));
        }
    }

    /**
     * Reset state (for testing only).
     */
    public static function flush(): void
    {
        self::$drivers = [];
        self::$booted = false;
    }
}
