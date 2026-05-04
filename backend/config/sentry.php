<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sentry Laravel SDK Configuration
    |--------------------------------------------------------------------------
    |
    | Set SENTRY_LARAVEL_DSN in your .env file to enable error tracking.
    | Leave empty to disable Sentry.
    |
    */

    'dsn' => env('SENTRY_LARAVEL_DSN', ''),

    'release' => env('SENTRY_RELEASE', 'unknown'),

    'environment' => env('APP_ENV', 'production'),

    // Performance monitoring (0.0 = off, 1.0 = 100% of transactions)
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.2),

    // Profiling (requires traces to be enabled)
    'profiles_sample_rate' => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.1),

    'send_default_pii' => false,

    'breadcrumbs' => [
        'logs'         => true,
        'sql_queries'  => true,
        'sql_bindings' => false,
        'queue_info'   => true,
        'command_info' => true,
    ],

    // Ignore common non-actionable exceptions
    'ignore_exceptions' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Validation\ValidationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
    ],
];
