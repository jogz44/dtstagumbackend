<?php

return [
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'HR-Document-Tracking-System-main/backend/public/api/*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => ['*'],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
