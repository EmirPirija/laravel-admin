<?php

$appCorsEnabled = filter_var(env('CORS_APP_LAYER_ENABLED', false), FILTER_VALIDATE_BOOL);

return [
    // U productionu CORS najčešće radi na Nginx sloju. Ako oba sloja dodaju header,
    // browser odbija odgovor zbog duplog Access-Control-Allow-Origin.
    // Laravel CORS ostaje opcionalan preko env varijable.
    'paths' => $appCorsEnabled
        ? [
            'api/*',
            'sanctum/csrf-cookie',
            'broadcasting/auth',
        ]
        : [],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://lmx.ba',
        'https://www.lmx.ba',
        'https://admin.lmx.ba',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
