<?php

declare(strict_types=1);

namespace App\Infrastructure\Routing\Persistence;

use App\Domain\Routing\Contracts\RoutingPolicyRepositoryInterface;
use App\Domain\Routing\Enums\PaymentMethod;
use App\Domain\Routing\Enums\RoutingStrategy;
use App\Models\AcquirerRoutingPolicy;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class EloquentRoutingPolicyRepository implements RoutingPolicyRepositoryInterface
{
    private const CACHE_PREFIX = 'acq_routing:policy';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {
    }

    public function resolve(PaymentMethod $method, ?string $accountId = null): AcquirerRoutingPolicy
    {
        $ttl = max(5, (int) config('acquirer_routing.cache.policy_ttl', 120));
        $cacheKey = sprintf('%s:%s:%s', self::CACHE_PREFIX, $method->value, $accountId ?? 'global');

        try {
            $resolved = $this->cache->remember($cacheKey, $ttl, function () use ($method, $accountId) {
                if ($accountId !== null) {
                    $accountPolicy = AcquirerRoutingPolicy::query()
                        ->where('scope', AcquirerRoutingPolicy::SCOPE_ACCOUNT)
                        ->where('account_id', $accountId)
                        ->where('payment_method', $method->value)
                        ->first();

                    if ($accountPolicy) {
                        return $accountPolicy;
                    }
                }

                return AcquirerRoutingPolicy::query()
                    ->where('scope', AcquirerRoutingPolicy::SCOPE_GLOBAL)
                    ->where('payment_method', $method->value)
                    ->first();
            });
        } catch (\Throwable $e) {
            Log::warning('Falha ao resolver política de roteamento; usando padrões', [
                'method' => $method->value,
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            $resolved = null;
        }

        return $resolved ?? AcquirerRoutingPolicy::defaultFor($method, $accountId);
    }

    public function allGlobal(): array
    {
        $stored = AcquirerRoutingPolicy::query()
            ->where('scope', AcquirerRoutingPolicy::SCOPE_GLOBAL)
            ->get()
            ->keyBy('payment_method');

        $policies = [];

        foreach (PaymentMethod::cases() as $method) {
            $policies[] = $stored->get($method->value) ?? AcquirerRoutingPolicy::defaultFor($method);
        }

        return $policies;
    }

    public function forAccount(string $accountId): array
    {
        return AcquirerRoutingPolicy::query()
            ->where('scope', AcquirerRoutingPolicy::SCOPE_ACCOUNT)
            ->where('account_id', $accountId)
            ->get()
            ->all();
    }

    public function save(PaymentMethod $method, ?string $accountId, array $attributes): AcquirerRoutingPolicy
    {
        $scope = $accountId !== null ? AcquirerRoutingPolicy::SCOPE_ACCOUNT : AcquirerRoutingPolicy::SCOPE_GLOBAL;

        $policy = AcquirerRoutingPolicy::query()->updateOrCreate(
            [
                'scope' => $scope,
                'account_id' => $accountId,
                'payment_method' => $method->value,
            ],
            [
                'id' => (string) Str::uuid(),
                'strategy' => RoutingStrategy::tryNormalize($attributes['strategy'] ?? null)->value,
                'failover_enabled' => (bool) ($attributes['failover_enabled'] ?? true),
                'max_attempts' => max(1, min(10, (int) ($attributes['max_attempts'] ?? 3))),
                'total_budget_ms' => max(1000, min(120000, (int) ($attributes['total_budget_ms'] ?? 25000))),
                'strict_health_check' => (bool) ($attributes['strict_health_check'] ?? true),
                'settings' => is_array($attributes['settings'] ?? null) ? $attributes['settings'] : [],
            ]
        );

        $this->flush($method, $accountId);

        return $policy;
    }

    public function delete(PaymentMethod $method, ?string $accountId): void
    {
        AcquirerRoutingPolicy::query()
            ->where('scope', $accountId !== null ? AcquirerRoutingPolicy::SCOPE_ACCOUNT : AcquirerRoutingPolicy::SCOPE_GLOBAL)
            ->where('account_id', $accountId)
            ->where('payment_method', $method->value)
            ->delete();

        $this->flush($method, $accountId);
    }

    private function flush(PaymentMethod $method, ?string $accountId): void
    {
        try {
            $this->cache->forget(sprintf('%s:%s:%s', self::CACHE_PREFIX, $method->value, $accountId ?? 'global'));

            // A global change also invalidates accounts that inherit from it.
            if ($accountId === null) {
                $this->cache->forget(sprintf('%s:%s:global', self::CACHE_PREFIX, $method->value));
            }
        } catch (\Throwable) {
            // Falling through to a stale read for a few seconds is acceptable.
        }
    }
}
