<?php

return [

    // Widget routes own their own CORS via ValidateWidgetDomain.
    // Dashboard XHRs are same-origin and need no CORS handling.
    'paths' => ['sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        rtrim((string) env('APP_URL', 'http://127.0.0.1:8001'), '/'),
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
