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
    ],

];
