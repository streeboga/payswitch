<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin API Key
    |--------------------------------------------------------------------------
    |
    | The API key used for administrative operations.
    |
    */

    'admin_api_key' => env('PAYSWITCH_ADMIN_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | The current Payswitch environment: sandbox or production.
    |
    */

    'environment' => env('PAYSWITCH_ENVIRONMENT', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | ID Prefixes
    |--------------------------------------------------------------------------
    |
    | Prefixes for various entity identifiers to make them easily recognizable.
    |
    */

    'id_prefixes' => [
        'payment' => 'pay_',
        'customer' => 'cus_',
        'refund' => 'ref_',
        'profile' => 'pro_',
        'merchant' => 'merchant_',
        'merchant_account' => 'mca_',
        'organization' => 'org_',
        'sender' => 'snd_',
        'product' => 'prod_',
        'api_key' => 'pk_',
        'event' => 'evt_',
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Settings
    |--------------------------------------------------------------------------
    */

    'payment' => [
        'session_expiry' => 900, // seconds
        'id_length' => 26,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Settings
    |--------------------------------------------------------------------------
    */

    'webhook' => [
        'retry_schedule' => [60, 300, 1800, 7200, 21600, 43200, 86400], // seconds
        'max_retries' => 7,
        'timeout' => 30, // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    'rate_limit' => [
        'api' => [
            'max_attempts' => 60,
            'decay_seconds' => 60,
        ],
        'webhooks' => [
            'max_attempts' => 100,
            'decay_seconds' => 60,
        ],
    ],

];
