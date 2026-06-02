<?php

$frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
$allowLocalOrigins = filter_var(
    env('CORS_ALLOW_LOCAL_ORIGINS', env('APP_ENV', 'production') !== 'production'),
    FILTER_VALIDATE_BOOLEAN
);
$allowAllOrigins = filter_var(env('CORS_ALLOW_ALL_ORIGINS', false), FILTER_VALIDATE_BOOLEAN);
$supportsCredentials = filter_var(env('CORS_SUPPORTS_CREDENTIALS', !$allowAllOrigins), FILTER_VALIDATE_BOOLEAN);
$allowWildcardSubdomains = filter_var(env('CORS_ALLOW_VECTOR_WILDCARD', true), FILTER_VALIDATE_BOOLEAN);
$extraAllowedOrigins = array_filter(array_map(
    static fn ($origin) => trim((string) $origin),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
));

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => ['api/*', 'broadcasting/*', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowAllOrigins
        ? ['*']
        : array_values(array_unique(array_filter([
            $frontendUrl,
            'https://vectorpagamentos.com',
            'https://app.vectorpagamentos.com',
            'https://api.vectorpagamentos.com',
            ...$extraAllowedOrigins,
            // Dev origins only when not in production
            ...($allowLocalOrigins ? [
                'http://localhost:3000',
                'http://localhost:3001',
                'http://localhost:5173',
                'http://127.0.0.1:3000',
                'http://127.0.0.1:5173',
            ] : []),
        ]))),

    'allowed_origins_patterns' => $allowWildcardSubdomains
        ? [
            '#^https://.*\.vectorpagamentos\.com$#',
        ]
        : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-Idempotency-Replay',
    ],

    'max_age' => 86400,

    'supports_credentials' => $supportsCredentials,

];
