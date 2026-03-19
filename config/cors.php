<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout', 'two-factor-challenge'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],

    // Allow any origin for the public payment widget API.
    // Sanctum's stateful domain check protects dashboard session auth independently.
    'allowed_origins_patterns' => ['#.*#'],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
