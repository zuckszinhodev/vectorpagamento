<?php

namespace App\Providers;

use App\Services\SecurityLogService;
use Illuminate\Support\ServiceProvider;
use App\Domain\Auth\Repositories\UserRepositoryInterface;
use App\Infrastructure\Persistence\Repositories\EloquentUserRepository;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register with full namespace
        $this->app->bind(
            \App\Domain\Auth\Repositories\UserRepositoryInterface::class,
            \Infrastructure\Persistence\Repositories\EloquentUserRepository::class
        );
        
        // Register with Domain alias (for imports without App\)
        $this->app->bind(
            \Domain\Auth\Repositories\UserRepositoryInterface::class,
            \Infrastructure\Persistence\Repositories\EloquentUserRepository::class
        );

        // Register SecurityLogService as singleton
        $this->app->singleton(SecurityLogService::class, function ($app) {
            return new SecurityLogService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
