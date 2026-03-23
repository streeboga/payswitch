<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors;

use Illuminate\Support\ServiceProvider;

class PaymentConnectorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        ConnectorFactory::boot(
            config('payswitch.connectors', []),
        );
    }
}
