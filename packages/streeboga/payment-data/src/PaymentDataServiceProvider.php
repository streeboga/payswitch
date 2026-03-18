<?php

namespace Streeboga\PaymentData;

use Illuminate\Support\ServiceProvider;

class PaymentDataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/payswitch.php',
            'payswitch',
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/payswitch.php' => config_path('payswitch.php'),
        ], 'payswitch-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'payswitch-migrations');
    }
}
