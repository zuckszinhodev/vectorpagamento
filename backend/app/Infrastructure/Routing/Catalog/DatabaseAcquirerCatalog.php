<?php

declare(strict_types=1);

namespace App\Infrastructure\Routing\Catalog;

use App\Domain\Routing\Contracts\AcquirerCatalogInterface;
use App\Domain\Routing\Contracts\HealthStateRepositoryInterface;
use App\Domain\Routing\Contracts\MetricsRepositoryInterface;
use App\Domain\Routing\Contracts\RateLimiterInterface;
use App\Domain\Routing\Enums\CircuitState;
use App\Domain\Routing\Enums\HealthState;
use App\Domain\Routing\Enums\PaymentMethod;
use App\Domain\Routing\ValueObjects\AcquirerCandidate;
use App\Domain\Routing\ValueObjects\AcquirerMetrics;
use App\Domain\Routing\ValueObjects\RoutingContext;
use App\Models\AcquirerConfig;
use App\Models\AcquirerRoutingProfile;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Assembles routing candidates from `acquirer_configs` + `acquirer_routing_profiles`,
 * enriched with live health, metrics and TPS.
 *
 * Every acquirer enabled in the database becomes a candidate — there is no
 * hard-coded list anywhere, which is what makes "add an acquirer from the
 * panel and it starts receiving traffic" actually true.
 */
final class DatabaseAcquirerCatalog implements AcquirerCatalogInterface
{
    private const CACHE_KEY = 'acq_routing:catalog:configs';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly HealthStateRepositoryInterface $health,
        private readonly MetricsRepositoryInterface $metrics,
        private readonly RateLimiterInterface $rateLimiter,
    ) {
    }

    public function candidatesFor(RoutingContext $context): array
    {
        $configs = $this->loadConfigs()
            ->filter(fn (array $row): bool => $this->supportsMethod($row, $context->method));

        if ($context->isRestricted()) {
            $allowed = array_flip($context->restrictToAcquirers);
            $configs = $configs->filter(static fn (array $row): bool => isset($allowed[$row['acquirer_key']]));
        }

        if ($configs->isEmpty()) {
            return [];
        }

        return $this->buildCandidates($configs->values()->all(), $context->method);
    }

    public function all(): array
    {
        $configs = $this->loadConfigs()->values()->all();

        if ($configs === []) {
            return [];
        }

        return $this->buildCandidates($configs, PaymentMethod::PIX);
    }

    public function flush(): void
    {
        try {
            $this->cache->forget(self::CACHE_KEY);
        } catch (\Throwable $e) {
            Log::warning('Falha ao limpar cache do catálogo de adquirentes', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $configs
     * @return array<int, AcquirerCandidate>
     */
    private function buildCandidates(array $configs, PaymentMethod $method): array
    {
        $keys = array_column($configs, 'acquirer_key');

        // One aggregate query for all candidates instead of N.
        $metricsByKey = $this->metrics->forAcquirers($keys, $method);
        $healthByKey = $this->health->all();

        $candidates = [];

        foreach ($configs as $row) {
            $key = (string) $row['acquirer_key'];
            $state = $healthByKey->get($key);

            $candidates[] = new AcquirerCandidate(
                key: $key,
                displayName: (string) ($row['display_name'] ?? $key),
                configId: (string) $row['id'],
                priority: (int) $row['priority'],
                weight: (int) $row['weight'],
                health: $state?->healthState() ?? HealthState::UNKNOWN,
                circuit: $state?->circuitState() ?? CircuitState::CLOSED,
                metrics: $metricsByKey[$key] ?? AcquirerMetrics::empty($key),
                maxTps: $row['max_tps'],
                maxConcurrent: $row['max_concurrent'],
                timeoutMs: (int) $row['timeout_ms'],
                connectTimeoutMs: (int) $row['connect_timeout_ms'],
                maxRetries: (int) $row['max_retries'],
                minAmountCents: $row['min_amount_cents'],
                maxAmountCents: $row['max_amount_cents'],
                currentTps: $this->rateLimiter->currentTps($key),
                rules: is_array($row['rules']) ? $row['rules'] : [],
            );
        }

        return $candidates;
    }

    /**
     * Enabled acquirers joined with their routing profile, cached briefly.
     *
     * Acquirers without a profile row fall back to the configured defaults, so
     * routing works the moment an acquirer is created.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function loadConfigs(): Collection
    {
        $ttl = max(5, (int) config('acquirer_routing.cache.catalog_ttl', 60));

        try {
            return $this->cache->remember(self::CACHE_KEY, $ttl, function (): Collection {
                $defaults = (array) config('acquirer_routing.profile_defaults', []);

                $profiles = AcquirerRoutingProfile::query()
                    ->get()
                    ->keyBy('acquirer_config_id');

                return AcquirerConfig::query()
                    ->where('enabled', true)
                    ->orderBy('priority')
                    ->get()
                    ->map(function (AcquirerConfig $config) use ($profiles, $defaults): array {
                        $profile = $profiles->get($config->id);

                        // routing_enabled lets an operator keep an acquirer
                        // configured but temporarily out of rotation.
                        if ($profile && !$profile->routing_enabled) {
                            return [];
                        }

                        return [
                            'id' => $config->id,
                            'acquirer_key' => $config->acquirer_key,
                            'display_name' => $config->display_name,
                            'supported_methods' => $config->supported_methods ?? [],
                            'priority' => $profile->priority ?? (int) ($config->priority ?? $defaults['priority'] ?? 100),
                            'weight' => $profile->weight ?? (int) ($defaults['weight'] ?? 1),
                            'max_tps' => $profile->max_tps ?? $defaults['max_tps'] ?? null,
                            'max_concurrent' => $profile->max_concurrent ?? $defaults['max_concurrent'] ?? null,
                            'timeout_ms' => $profile->timeout_ms ?? (int) ($defaults['timeout_ms'] ?? 15000),
                            'connect_timeout_ms' => $profile->connect_timeout_ms ?? (int) ($defaults['connect_timeout_ms'] ?? 3000),
                            'max_retries' => $profile->max_retries ?? (int) ($defaults['max_retries'] ?? 1),
                            'min_amount_cents' => $profile->min_amount_cents ?? null,
                            'max_amount_cents' => $profile->max_amount_cents ?? null,
                            'rules' => $profile->rules ?? [],
                        ];
                    })
                    ->filter(static fn (array $row): bool => $row !== [])
                    ->values();
            });
        } catch (\Throwable $e) {
            Log::error('Falha ao carregar catálogo de adquirentes', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function supportsMethod(array $row, PaymentMethod $method): bool
    {
        $supported = $row['supported_methods'] ?? [];

        if (!is_array($supported) || $supported === []) {
            // No declared methods means "everything" — matches the historical
            // behaviour of acquirers seeded before the column existed.
            return true;
        }

        $normalized = array_map(static fn ($m): string => strtolower(trim((string) $m)), $supported);

        foreach ($method->aliases() as $alias) {
            if (in_array($alias, $normalized, true)) {
                return true;
            }
        }

        // Backward compatibility: acquirers that only declared 'pix' were
        // historically used for PIX OUT as well.
        if ($method === PaymentMethod::PIX_OUT && in_array('pix', $normalized, true)) {
            return true;
        }

        return false;
    }
}
