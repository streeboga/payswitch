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
use Streeboga\PaymentOmnipay\Robokassa\RobokassaConnector as OmnipayRobokassaConnector;

$test_connectors_enabled = (bool) env('PAYSWITCH_TEST_CONNECTORS', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true));

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
        // PAYSWITCH_ROBOKASSA_DRIVER=omnipay switches to the Omnipay adapter (streeboga/payment-omnipay).
        // The legacy driver stays the default until the adapter is verified with live passwords.
        'robokassa' => env('PAYSWITCH_ROBOKASSA_DRIVER', 'legacy') === 'omnipay'
            ? OmnipayRobokassaConnector::class
            : RobokassaConnector::class,
        'tochka' => TochkaConnector::class,
        'test' => TestConnector::class,
        'test_sbp' => TestConnector::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Connectable Connectors
    |--------------------------------------------------------------------------
    |
    | Which connectors a merchant may add. Drivers not verified live stay in the
    | registry above but cannot be chosen. Verified live: CloudPayments (test
    | cards) and the test connectors.
    |
    */

    'connectable' => array_values(array_filter(
        explode(',', (string) env('PAYSWITCH_CONNECTABLE', 'cloudpayments,test,test_sbp')),
        fn (string $name): bool => $test_connectors_enabled || ! in_array($name, ['test', 'test_sbp'], true),
    )),

    /*
    |--------------------------------------------------------------------------
    | Test Connectors
    |--------------------------------------------------------------------------
    |
    | `test`/`test_sbp` approve a payment with no money and verify no webhook
    | signature. Only local/testing by default; in production the app refuses to
    | boot with them on (AppServiceProvider) and the factory will not build them.
    |
    */

    'test_connectors_enabled' => $test_connectors_enabled,

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

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | С каждым Idempotency-Key сохраняется хэш тела запроса. Включено — повтор с
    | тем же ключом и другим телом получает тот же 422 idempotency_key_reused, что
    | и повтор с другой суммой. Выключено — сравнивается только сумма (и валюта
    | или платёж), а расхождение тела пишется в лог: по нему видно, сломает ли
    | включение чьи-то повторы.
    |
    */

    'idempotency' => [
        'compare_request_hash' => (bool) env('PAYSWITCH_IDEMPOTENCY_COMPARE_REQUEST_HASH', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Robokassa test shop
    |--------------------------------------------------------------------------
    |
    | Read only by `payswitch:robokassa-mca`, which copies them into a merchant's
    | connector account. Empty by default; real values live in .env, never in git.
    |
    */

    'robokassa_account' => [
        'login' => env('ROBOKASSA_LOGIN', ''),
        'password1' => env('ROBOKASSA_PASSWORD_1', ''),
        'password2' => env('ROBOKASSA_PASSWORD_2', ''),
        'hash_algo' => env('ROBOKASSA_HASH_ALGO', 'md5'),
        'test_mode' => (bool) env('ROBOKASSA_TEST_MODE', true),
        'fiscal' => (bool) env('ROBOKASSA_FISCAL', false),
    ],
];
