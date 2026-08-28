<?php

return [
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'HR-Document-Tracking-System-main/backend/public/api/*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:8080',
        'http://127.0.0.1:8080',
        'http://localhost:9000',
        'http://localhost:9001',
        'http://localhost:9002',
        'http://localhost:9003',
        'http://localhost:9004',
        'http://127.0.0.1:9000',
        'http://127.0.0.1:9001',
        'http://127.0.0.1:9002',
        'http://127.0.0.1:9003',
        'http://127.0.0.1:9004',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
