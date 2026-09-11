<?php

declare(strict_types=1);

use Streeboga\PaymentConnectors\Drivers\CloudPaymentsConnector;
use Streeboga\PaymentConnectors\Drivers\RbsConnector;
use Streeboga\PaymentConnectors\Drivers\RobokassaConnector;
use Streeboga\PaymentConnectors\Drivers\StripeConnector;
use Streeboga\PaymentConnectors\Drivers\TBankConnector;
use Streeboga\PaymentConnectors\Drivers\TestConnector;
use Streeboga\PaymentConnectors\Drivers\TochkaConnector;
use Streeboga\PaymentConnectors\Drivers\YooKassaConnector;

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Connectors
    |--------------------------------------------------------------------------
    |
    | Each connector package registers itself here via its ServiceProvider.
    | To override a built-in connector or add a new one, publish this config
    | and add/modify the entry:
    |
    |   'tbank' => \App\Connectors\TBankConnector::class,
    |
    */

    'connectors' => [
        'stripe' => StripeConnector::class,
        'cloudpayments' => CloudPaymentsConnector::class,
        'yookassa' => YooKassaConnector::class,
        'sberbank' => RbsConnector::class,
        'alfabank' => RbsConnector::class,
        'tbank' => TBankConnector::class,
        'robokassa' => RobokassaConnector::class,
        'tochka' => TochkaConnector::class,
        'test' => TestConnector::class,
        'test_sbp' => TestConnector::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Wallets Service
    |--------------------------------------------------------------------------
    |
    | Internal API of the platform wallets service. A payment carrying
    | `wallet_key` in its metadata credits that wallet once it succeeds.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Unsigned Webhooks
    |--------------------------------------------------------------------------
    |
    | Lets connectors whose provider may omit a signature header accept a webhook
    | without one. Off unless someone says otherwise: APP_ENV is not a security
    | boundary, a live stand can be flagged anything.
    |
    */

    'allow_unsigned_webhooks' => (bool) env('PAYSWITCH_ALLOW_UNSIGNED_WEBHOOKS', false),

    'wallets' => [
        'url' => env('WALLETS_SERVICE_URL'),
        'internal_secret' => env('WALLETS_INTERNAL_SECRET'),
    ],

];
