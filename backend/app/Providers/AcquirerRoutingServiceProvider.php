<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Routing\AcquirerHealthMonitor;
use App\Application\Routing\PaymentRoutingService;
use App\Application\Routing\RoutingLogger;
use App\Domain\Routing\Contracts\AcquirerCatalogInterface;
use App\Domain\Routing\Contracts\AcquirerCredentialRepositoryInterface;
use App\Domain\Routing\Contracts\CircuitBreakerInterface;
use App\Domain\Routing\Contracts\CredentialVaultInterface;
use App\Domain\Routing\Contracts\HealthStateRepositoryInterface;
use App\Domain\Routing\Contracts\MetricsRepositoryInterface;
use App\Domain\Routing\Contracts\ProviderRegistryInterface;
use App\Domain\Routing\Contracts\RateLimiterInterface;
use App\Domain\Routing\Contracts\RotationCursorInterface;
use App\Domain\Routing\Contracts\RoutingPolicyRepositoryInterface;
use App\Domain\Routing\Services\SmartAcquirerRouter;
use App\Domain\Routing\Strategies\BestApprovalRateStrategy;
use App\Domain\Routing\Strategies\LeastLatencyStrategy;
use App\Domain\Routing\Strategies\LeastUtilizationStrategy;
use App\Domain\Routing\Strategies\PriorityStrategy;
use App\Domain\Routing\Strategies\RoundRobinStrategy;
use App\Domain\Routing\Strategies\StrategyRegistry;
use App\Domain\Routing\Strategies\WeightedRoundRobinStrategy;
use App\Infrastructure\Routing\Catalog\DatabaseAcquirerCatalog;
use App\Infrastructure\Routing\Persistence\CacheRotationCursor;
use App\Infrastructure\Routing\Persistence\EloquentAcquirerCredentialRepository;
use App\Infrastructure\Routing\Persistence\EloquentHealthStateRepository;
use App\Infrastructure\Routing\Persistence\EloquentMetricsRepository;
use App\Infrastructure\Routing\Persistence\EloquentRoutingPolicyRepository;
use App\Infrastructure\Routing\Providers\ProviderRegistry;
use App\Infrastructure\Routing\Resilience\AcquirerCircuitBreaker;
use App\Infrastructure\Routing\Resilience\CacheRateLimiter;
use App\Infrastructure\Routing\Security\CredentialVaultFactory;
use App\Models\AcquirerConfig;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the acquirer routing engine.
 *
 * Everything is bound to an interface, so any piece can be swapped in tests or
 * replaced in production (a Redis-native rate limiter, a different metrics
 * backend) without touching a single consumer.
 */
class AcquirerRoutingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/acquirer_routing.php', 'acquirer_routing');

        $this->app->singleton(CacheRepository::class . ':acquirer_routing', static fn (): CacheRepository => Cache::store());

        $this->registerSecurity();
        $this->registerPersistence();
        $this->registerResilience();
        $this->registerStrategies();
        $this->registerRouting();
    }

    public function boot(): void
    {
        // Editing an acquirer must take effect immediately: drop the catalog
        // cache and any memoised provider instance holding stale credentials.
        AcquirerConfig::saved(function (AcquirerConfig $config): void {
            $this->flushRoutingCaches($config->acquirer_key);
        });

        AcquirerConfig::deleted(function (AcquirerConfig $config): void {
            $this->flushRoutingCaches($config->acquirer_key);
        });
    }

    private function registerSecurity(): void
    {
        $this->app->singleton(
            CredentialVaultInterface::class,
            static fn (): CredentialVaultInterface => CredentialVaultFactory::make()
        );

        $this->app->singleton(
            \App\Domain\Routing\Services\CredentialSchemaResolver::class,
            static fn (): \App\Domain\Routing\Services\CredentialSchemaResolver => new \App\Domain\Routing\Services\CredentialSchemaResolver()
        );

        $this->app->singleton(
            AcquirerCredentialRepositoryInterface::class,
            static fn ($app): AcquirerCredentialRepositoryInterface => new EloquentAcquirerCredentialRepository(
                $app->make(CredentialVaultInterface::class),
                $app->make(\App\Domain\Routing\Services\CredentialSchemaResolver::class),
            )
        );
    }

    private function registerPersistence(): void
    {
        $this->app->singleton(
            HealthStateRepositoryInterface::class,
            static fn (): HealthStateRepositoryInterface => new EloquentHealthStateRepository(Cache::store())
        );

        $this->app->singleton(
            MetricsRepositoryInterface::class,
            static fn (): MetricsRepositoryInterface => new EloquentMetricsRepository()
        );

        $this->app->singleton(
            RoutingPolicyRepositoryInterface::class,
            static fn (): RoutingPolicyRepositoryInterface => new EloquentRoutingPolicyRepository(Cache::store())
        );

        $this->app->singleton(
            RotationCursorInterface::class,
            static fn (): RotationCursorInterface => new CacheRotationCursor(Cache::store())
        );

        $this->app->singleton(
            AcquirerCatalogInterface::class,
            static fn ($app): AcquirerCatalogInterface => new DatabaseAcquirerCatalog(
                Cache::store(),
                $app->make(HealthStateRepositoryInterface::class),
                $app->make(MetricsRepositoryInterface::class),
                $app->make(RateLimiterInterface::class),
            )
        );
    }

    private function registerResilience(): void
    {
        $this->app->singleton(
            RateLimiterInterface::class,
            static fn (): RateLimiterInterface => new CacheRateLimiter(Cache::store())
        );

        $this->app->singleton(
            CircuitBreakerInterface::class,
            static fn ($app): CircuitBreakerInterface => new AcquirerCircuitBreaker(
                Cache::store(),
                $app->make(HealthStateRepositoryInterface::class),
            )
        );
    }

    private function registerStrategies(): void
    {
        $this->app->singleton(StrategyRegistry::class, static function ($app): StrategyRegistry {
            $scoring = (array) config('acquirer_routing.scoring', []);
            $cursor = $app->make(RotationCursorInterface::class);

            return new StrategyRegistry([
                new PriorityStrategy(),
                new RoundRobinStrategy($cursor),
                new WeightedRoundRobinStrategy($cursor),
                new LeastLatencyStrategy(
                    minSamples: (int) ($scoring['min_samples'] ?? 20),
                    latencyCeilingMs: (int) ($scoring['latency_ceiling_ms'] ?? 8000),
                ),
                new BestApprovalRateStrategy(
                    minSamples: (int) ($scoring['min_samples'] ?? 20),
                ),
                new LeastUtilizationStrategy(),
            ]);
        });
    }

    private function registerRouting(): void
    {
        $this->app->singleton(
            ProviderRegistryInterface::class,
            static fn ($app): ProviderRegistryInterface => new ProviderRegistry(
                $app->make(AcquirerCredentialRepositoryInterface::class),
                $app->make(\App\Domain\Routing\Services\CredentialSchemaResolver::class),
            )
        );

        $this->app->singleton(
            SmartAcquirerRouter::class,
            static fn ($app): SmartAcquirerRouter => new SmartAcquirerRouter(
                $app->make(AcquirerCatalogInterface::class),
                $app->make(RoutingPolicyRepositoryInterface::class),
                $app->make(StrategyRegistry::class),
                $app->make(CircuitBreakerInterface::class),
            )
        );

        $this->app->singleton(RoutingLogger::class, static fn (): RoutingLogger => new RoutingLogger());

        $this->app->singleton(
            PaymentRoutingService::class,
            static fn ($app): PaymentRoutingService => new PaymentRoutingService(
                $app->make(SmartAcquirerRouter::class),
                $app->make(ProviderRegistryInterface::class),
                $app->make(CircuitBreakerInterface::class),
                $app->make(RateLimiterInterface::class),
                $app->make(MetricsRepositoryInterface::class),
                $app->make(HealthStateRepositoryInterface::class),
                $app->make(RoutingLogger::class),
            )
        );

        $this->app->singleton(
            \App\Application\Routing\AcquirerBalanceService::class,
            static fn ($app): \App\Application\Routing\AcquirerBalanceService => new \App\Application\Routing\AcquirerBalanceService(
                $app->make(ProviderRegistryInterface::class),
                $app->make(AcquirerCatalogInterface::class),
                Cache::store(),
            )
        );

        $this->app->singleton(
            AcquirerHealthMonitor::class,
            static fn ($app): AcquirerHealthMonitor => new AcquirerHealthMonitor(
                $app->make(ProviderRegistryInterface::class),
                $app->make(HealthStateRepositoryInterface::class),
                $app->make(CircuitBreakerInterface::class),
            )
        );
    }

    private function flushRoutingCaches(?string $acquirerKey): void
    {
        try {
            if ($this->app->resolved(AcquirerCatalogInterface::class)) {
                $this->app->make(AcquirerCatalogInterface::class)->flush();
            }

            if ($this->app->resolved(ProviderRegistryInterface::class)) {
                $this->app->make(ProviderRegistryInterface::class)->flush($acquirerKey);
            }
        } catch (\Throwable) {
            // A stale cache entry expires on its own within seconds.
        }
    }
}
