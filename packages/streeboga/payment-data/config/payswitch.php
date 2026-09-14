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
        'payment_method' => 'pm_',
        'routing_rule' => 'rule_',
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
        'retry_schedule' => [1, 5, 5, 10, 10, 10, 10, 10, 60, 60, 60, 60, 60, 360, 360, 360], // minutes (job converts to seconds)
        'max_attempts' => 16,
        'timeout' => 30, // seconds
        // Слать старую подпись x-webhook-signature-512 рядом с новой (С7).
        // На время миграции — true: приёмники, ещё не понимающие v2, проверяют
        // по ней. Выключается последним шагом, когда все приёмники требуют v2.
        'send_legacy_signature' => env('PAYSWITCH_WEBHOOK_SEND_LEGACY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    'rate_limit' => [
        'admin' => 30,      // requests per minute for Admin API Key
        'secret' => 120,    // requests per minute for Secret API Key
        'publishable' => 60, // requests per minute for Publishable Key, per merchant and IP
        'unauthenticated' => 30, // failed API key attempts per minute per IP
    ],

];
