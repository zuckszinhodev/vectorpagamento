<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenRouter Configuration
    |--------------------------------------------------------------------------
    |
    | Used for AI-powered features like landing page generation
    |
    */
    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'google/gemini-2.0-flash-001'),
        'wazerai_model' => env('WAZERAI_MODEL', 'google/gemini-2.0-flash-001'),
        'wazerai_fast_model' => env('WAZERAI_FAST_MODEL', 'google/gemini-2.0-flash-001'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Together.ai Configuration (Image Generation)
    |--------------------------------------------------------------------------
    |
    | Used for AI image generation in landing pages. Free FLUX.1-schnell model.
    | Get your free API key at: https://api.together.xyz/settings/api-keys
    |
    */
    'together' => [
        'api_key' => env('TOGETHER_API_KEY'),
        'image_model' => env('TOGETHER_IMAGE_MODEL', 'black-forest-labs/FLUX.1-schnell-Free'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI Configuration
    |--------------------------------------------------------------------------
    |
    | Used for AI-powered features like landing page generation
    |
    */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'model' => env('OPENAI_MODEL', 'gpt-4-turbo-preview'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic (Claude) Configuration
    |--------------------------------------------------------------------------
    |
    | Alternative AI provider for landing page generation
    |
    */
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-3-sonnet-20240229'),
    ],

    /*
    |--------------------------------------------------------------------------
    | UTMify Configuration
    |--------------------------------------------------------------------------
    |
    | UTM tracking & attribution service. API tokens are stored per-account
    | in the utmify_integrations table. This section holds global defaults.
    |
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/api/v1/auth/google/callback'),
    ],

    'utmify' => [
        'endpoint' => env('UTMIFY_ENDPOINT', 'https://api.utmify.com.br/api-credentials/orders'),
        'platform' => env('UTMIFY_PLATFORM', 'GatePro'),
        'timeout'  => (int) env('UTMIFY_TIMEOUT', 15),
    ],

];
