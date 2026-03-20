<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout', 'two-factor-challenge'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Not needed: the dashboard uses Vite proxy in dev (same-origin)
    // and shares a domain in production, so no cross-origin cookie sending.
    // The payment widget uses api-key header auth, not cookies.
    'supports_credentials' => false,

];
