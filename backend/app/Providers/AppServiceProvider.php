<?php

namespace App\Providers;

use App\Services\SecurityLogService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Auth repositories
        $this->app->bind(
            \App\Domain\Auth\Repositories\UserRepositoryInterface::class,
            \Infrastructure\Persistence\Repositories\EloquentUserRepository::class
        );
        $this->app->bind(
            \Domain\Auth\Repositories\UserRepositoryInterface::class,
            \Infrastructure\Persistence\Repositories\EloquentUserRepository::class
        );

        // Payment repositories
        $this->app->bind(
            \App\Domain\Payment\Repositories\ChargeRepositoryInterface::class,
            \Infrastructure\Persistence\Repositories\EloquentChargeRepository::class
        );
        $this->app->bind(
            \Domain\Payment\Repositories\ChargeRepositoryInterface::class,
            \Infrastructure\Persistence\Repositories\EloquentChargeRepository::class
        );
        $this->app->bind(
            \App\Domain\Payment\Repositories\TransactionRepositoryInterface::class,
            \Infrastructure\Persistence\Repositories\EloquentTransactionRepository::class
        );
        $this->app->bind(
            \Domain\Payment\Repositories\TransactionRepositoryInterface::class,
            \Infrastructure\Persistence\Repositories\EloquentTransactionRepository::class
        );

        $this->app->singleton(SecurityLogService::class, function ($app) {
            return new SecurityLogService();
        });
    }

    public function boot(): void
    {
        //
    }
}
