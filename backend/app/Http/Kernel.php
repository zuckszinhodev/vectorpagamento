<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        \App\Http\Middleware\TrustProxies::class,
        \App\Http\Middleware\ForceCorsHeaders::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        \App\Http\Middleware\SecurityHeaders::class,
        \App\Http\Middleware\CheckBlockedIp::class, // SECURITY: Block banned IPs globally
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            \App\Http\Middleware\JsonPayloadProtection::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];

    /**
     * The application's middleware aliases.
     *
     * @var array<string, class-string|string>
     */
    protected $middlewareAliases = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'precognitive' => \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        'auth.apikey' => \App\Http\Middleware\AuthApiKey::class,
        'auth.jwt_or_apikey' => \App\Http\Middleware\AuthJwtOrApiKey::class,
        'permission' => \App\Http\Middleware\CheckPermission::class,
        'role' => \App\Http\Middleware\CheckRole::class,
        'mfa.enabled' => \App\Http\Middleware\EnsureMfaEnabled::class,
        'idempotency' => \App\Http\Middleware\Idempotency::class,
        'kyc.approved' => \App\Http\Middleware\RequireKycApproved::class,
        'resolve.domain' => \App\Http\Middleware\ResolveCustomDomain::class,
        'api.permission' => \App\Http\Middleware\CheckApiPermission::class,
        'check.status' => \App\Http\Middleware\CheckUserStatus::class,
        'rate.api' => \App\Http\Middleware\RateLimitApi::class,
        'audit' => \App\Http\Middleware\AuditLog::class,
        'auth.strict_rate' => \App\Http\Middleware\StrictAuthRateLimiter::class,
        'admin.audit' => \App\Http\Middleware\AdminAccessLog::class,
        'admin.permission' => \App\Http\Middleware\AdminPermission::class,
        'admin.rate' => \App\Http\Middleware\AdminRateLimit::class,
        'check.blocked_ip' => \App\Http\Middleware\CheckBlockedIp::class,
        'verify.signature' => \App\Http\Middleware\VerifyRequestSignature::class,
        'json.protect' => \App\Http\Middleware\JsonPayloadProtection::class,
    ];
}
