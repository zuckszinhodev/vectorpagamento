<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * Define your route model bindings, pattern filters, etc.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        Broadcast::routes(['middleware' => ['api', 'auth', 'check.status']]);

        require base_path('routes/channels.php');

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Default API rate limit: 60 req/min per user or IP
        RateLimiter::for('api', function (Request $request) {
            try {
                $userId = $request->user()?->id;
            } catch (\Throwable $e) {
                $userId = null;
            }
            return Limit::perMinute(
                (int) config('app.api_rate_limit', 60)
            )->by($userId ?: $request->ip());
        });

        // Checkout/Payment endpoints: higher limit for checkout flow
        RateLimiter::for('checkout', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // Webhooks: high limit for acquirer callbacks
        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(200)->by($request->ip());
        });

        // Auth endpoints: strict limit to prevent brute force
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Heavy report endpoints: lower limit
        RateLimiter::for('reports', function (Request $request) {
            try {
                $userId = $request->user()?->id;
            } catch (\Throwable $e) {
                $userId = null;
            }
            return Limit::perMinute(15)->by($userId ?: $request->ip());
        });
    }
}
