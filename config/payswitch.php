<?php

use Streeboga\PaymentConnectors\Drivers\CloudPaymentsConnector;
use Streeboga\PaymentConnectors\Drivers\RbsConnector;
use Streeboga\PaymentConnectors\Drivers\RobokassaConnector;
use Streeboga\PaymentConnectors\Drivers\StripeConnector;
use Streeboga\PaymentConnectors\Drivers\TBankConnector;
use Streeboga\PaymentConnectors\Drivers\TestConnector;
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
        'test' => TestConnector::class,
    ],

];
