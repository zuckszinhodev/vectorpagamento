<?php

use Illuminate\Support\Str;

return [

    'name' => env('APP_NAME', 'Vector Pagamentos'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'frontend_url' => env('FRONTEND_URL', 'https://app.vectorpagamentos.com'),
    'api_rate_limit' => (int) env('API_RATE_LIMIT_PER_MINUTE', 60),
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),
    'asset_url' => env('ASSET_URL'),
    'timezone' => 'America/Sao_Paulo',
    'locale' => 'pt_BR',
    'fallback_locale' => 'en',
    'faker_locale' => 'pt_BR',
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',

    // Custom Domain Configuration
    'checkout_domain' => env('CHECKOUT_DOMAIN', 'checkout.vectorpagamentos.com'),
    'checkout_ips' => env('CHECKOUT_IPS', ''),
    'member_area_domain' => env('MEMBER_AREA_DOMAIN', 'members.vectorpagamentos.com'),
    'member_area_cname' => env('MEMBER_AREA_CNAME', 'members.vectorpagamentos.com'),
    'vectorpagamentos_subdomain_base' => env('VECTORPAGAMENTOS_SUBDOMAIN_BASE', 'vectorpagamentos.com'),

    'providers' => [
        Illuminate\Auth\AuthServiceProvider::class,
        Illuminate\Broadcasting\BroadcastServiceProvider::class,
        Illuminate\Bus\BusServiceProvider::class,
        Illuminate\Cache\CacheServiceProvider::class,
        Illuminate\Foundation\Providers\ConsoleSupportServiceProvider::class,
        Illuminate\Cookie\CookieServiceProvider::class,
        Illuminate\Database\DatabaseServiceProvider::class,
        Illuminate\Encryption\EncryptionServiceProvider::class,
        Illuminate\Filesystem\FilesystemServiceProvider::class,
        Illuminate\Foundation\Providers\FoundationServiceProvider::class,
        Illuminate\Hashing\HashServiceProvider::class,
        Illuminate\Mail\MailServiceProvider::class,
        Illuminate\Notifications\NotificationServiceProvider::class,
        Illuminate\Pagination\PaginationServiceProvider::class,
        Illuminate\Pipeline\PipelineServiceProvider::class,
        Illuminate\Queue\QueueServiceProvider::class,
        Illuminate\Redis\RedisServiceProvider::class,
        Illuminate\Auth\Passwords\PasswordResetServiceProvider::class,
        Illuminate\Session\SessionServiceProvider::class,
        Illuminate\Translation\TranslationServiceProvider::class,
        Illuminate\Validation\ValidationServiceProvider::class,
        Illuminate\View\ViewServiceProvider::class,

        // Application Service Providers
        App\Providers\AppServiceProvider::class,
        App\Providers\AcquirerRoutingServiceProvider::class,
        App\Providers\RouteServiceProvider::class,
        
        // Third Party Service Providers
        Tymon\JWTAuth\Providers\LaravelServiceProvider::class,
    ],

    'aliases' => [
        'App' => Illuminate\Support\Facades\App::class,
        'Arr' => Illuminate\Support\Arr::class,
        'Artisan' => Illuminate\Support\Facades\Artisan::class,
        'Auth' => Illuminate\Support\Facades\Auth::class,
        'Blade' => Illuminate\Support\Facades\Blade::class,
        'Cache' => Illuminate\Support\Facades\Cache::class,
        'Config' => Illuminate\Support\Facades\Config::class,
        'DB' => Illuminate\Support\Facades\DB::class,
        'Hash' => Illuminate\Support\Facades\Hash::class,
        'Http' => Illuminate\Support\Facades\Http::class,
        'Log' => Illuminate\Support\Facades\Log::class,
        'Queue' => Illuminate\Support\Facades\Queue::class,
        'Redis' => Illuminate\Support\Facades\Redis::class,
        'Route' => Illuminate\Support\Facades\Route::class,
        'Storage' => Illuminate\Support\Facades\Storage::class,
        'URL' => Illuminate\Support\Facades\URL::class,
        'Validator' => Illuminate\Support\Facades\Validator::class,
        'View' => Illuminate\Support\Facades\View::class,
    ],

];
