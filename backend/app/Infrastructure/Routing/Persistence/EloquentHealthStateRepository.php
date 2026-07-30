<?php

declare(strict_types=1);

namespace App\Infrastructure\Routing\Persistence;

use App\Domain\Routing\Contracts\HealthStateRepositoryInterface;
use App\Domain\Routing\Enums\CircuitState;
use App\Domain\Routing\Enums\HealthState;
use App\Models\AcquirerConfig;
use App\Models\AcquirerEvent;
use App\Models\AcquirerHealthState;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Health state persisted in `acquirer_health_states`, with a short-lived cache
 * in front of the read path so routing does not query it per transaction.
 */
final class EloquentHealthStateRepository implements HealthStateRepositoryInterface
{
    private const CACHE_KEY = 'acq_routing:health:all';
    private const CACHE_TTL = 10;

    /** Weight of a new sample in the exponential moving average of latency. */
    private const LATENCY_EMA_ALPHA = 0.2;

    public function __construct(
        private readonly CacheRepository $cache,
    ) {
    }

    public function all(): Collection
    {
        try {
            return $this->cache->remember(
                self::CACHE_KEY,
                self::CACHE_TTL,
                static fn () => AcquirerHealthState::query()->get()->keyBy('acquirer_key')
            );
        } catch (\Throwable $e) {
            Log::warning('Falha ao ler estados de saúde das adquirentes', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    public function find(string $acquirerKey): ?AcquirerHealthState
    {
        return $this->all()->get($acquirerKey);
    }

    public function ensure(string $acquirerKey, ?string $acquirerConfigId = null): AcquirerHealthState
    {
        $existing = AcquirerHealthState::query()->where('acquirer_key', $acquirerKey)->first();

        if ($existing) {
            return $existing;
        }

        $configId = $acquirerConfigId
            ?? AcquirerConfig::query()->where('acquirer_key', $acquirerKey)->value('id')
            ?? (string) Str::uuid();

        $state = AcquirerHealthState::query()->create([
            'id' => (string) Str::uuid(),
            'acquirer_config_id' => $configId,
            'acquirer_key' => $acquirerKey,
            // UNKNOWN, not OFFLINE: a newly registered acquirer must be allowed
            // to take its first request so it can prove itself.
            'state' => HealthState::UNKNOWN->value,
            'circuit_state' => CircuitState::CLOSED->value,
        ]);

        $this->flush();

        return $state;
    }

    public function recordProbe(string $acquirerKey, bool $healthy, int $latencyMs, ?string $error = null): AcquirerHealthState
    {
        $state = $this->ensure($acquirerKey);
        $previous = $state->healthState();

        $state->checks_total = (int) $state->checks_total + 1;

        if ($healthy) {
            $state->checks_ok = (int) $state->checks_ok + 1;
            $state->consecutive_failures = 0;
            $state->last_success_at = now();
            $state->state = $this->stateForLatency($latencyMs)->value;
        } else {
            $state->consecutive_failures = (int) $state->consecutive_failures + 1;
            $state->last_error = $this->truncateError($error);
            $state->last_error_at = now();

            $offlineAfter = max(1, (int) config('acquirer_routing.health.offline_after_failures', 2));
            $state->state = $state->consecutive_failures >= $offlineAfter
                ? HealthState::OFFLINE->value
                : HealthState::DEGRADED->value;
        }

        $state->last_latency_ms = $latencyMs;
        $state->avg_latency_ms = $this->blendLatency($state->avg_latency_ms, $latencyMs);
        $state->last_check_at = now();
        $state->save();

        $this->flush();

        if ($previous->value !== $state->state) {
            $this->recordEvent(
                $acquirerKey,
                AcquirerEvent::TYPE_STATUS_CHANGE,
                $state->state === HealthState::OFFLINE->value ? 'critical' : 'warning',
                sprintf('Status alterado de %s para %s', $previous->label(), $state->healthState()->label()),
                ['from' => $previous->value, 'to' => $state->state, 'latency_ms' => $latencyMs, 'error' => $error]
            );
        }

        return $state;
    }

    public function recordCircuitTransition(string $acquirerKey, CircuitState $circuit, ?string $reason = null): void
    {
        try {
            $state = $this->ensure($acquirerKey);
            $previous = $state->circuitState();

            if ($previous === $circuit) {
                return;
            }

            $state->circuit_state = $circuit->value;

            match ($circuit) {
                CircuitState::OPEN => $this->applyOpen($state),
                CircuitState::HALF_OPEN => $state->circuit_half_open_at = now(),
                CircuitState::CLOSED => $this->applyClose($state),
            };

            $state->save();
            $this->flush();

            $this->recordEvent(
                $acquirerKey,
                match ($circuit) {
                    CircuitState::OPEN => AcquirerEvent::TYPE_CIRCUIT_OPENED,
                    CircuitState::HALF_OPEN => AcquirerEvent::TYPE_CIRCUIT_HALF_OPEN,
                    CircuitState::CLOSED => AcquirerEvent::TYPE_CIRCUIT_CLOSED,
                },
                $circuit === CircuitState::OPEN ? 'critical' : 'info',
                sprintf('Circuit breaker: %s → %s', $previous->label(), $circuit->label()),
                ['from' => $previous->value, 'to' => $circuit->value, 'reason' => $reason]
            );
        } catch (\Throwable $e) {
            Log::error('Falha ao persistir transição do circuit breaker', [
                'acquirer' => $acquirerKey,
                'to' => $circuit->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function recordTraffic(string $acquirerKey, bool $succeeded, int $latencyMs, ?string $error = null): void
    {
        try {
            $state = $this->ensure($acquirerKey);

            $state->last_latency_ms = $latencyMs;
            $state->avg_latency_ms = $this->blendLatency($state->avg_latency_ms, $latencyMs);

            if ($succeeded) {
                $state->last_success_at = now();

                // Real traffic succeeding is stronger evidence than a probe:
                // an acquirer marked offline that just processed a payment is
                // demonstrably back.
                if ($state->state === HealthState::OFFLINE->value) {
                    $state->state = $this->stateForLatency($latencyMs)->value;
                }
            } else {
                $state->last_error = $this->truncateError($error);
                $state->last_error_at = now();
            }

            $state->save();
            $this->flush();
        } catch (\Throwable $e) {
            Log::warning('Falha ao registrar tráfego no estado de saúde', [
                'acquirer' => $acquirerKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function setState(string $acquirerKey, HealthState $newState, ?string $reason = null): void
    {
        $state = $this->ensure($acquirerKey);
        $previous = $state->healthState();

        if ($previous === $newState) {
            return;
        }

        $state->state = $newState->value;

        if ($newState === HealthState::ONLINE) {
            $state->consecutive_failures = 0;
        }

        $state->save();
        $this->flush();

        $this->recordEvent(
            $acquirerKey,
            AcquirerEvent::TYPE_STATUS_CHANGE,
            $newState === HealthState::OFFLINE ? 'warning' : 'info',
            sprintf('Status definido de %s para %s', $previous->label(), $newState->label()),
            ['from' => $previous->value, 'to' => $newState->value, 'reason' => $reason]
        );
    }

    public function forget(string $acquirerKey): void
    {
        AcquirerHealthState::query()->where('acquirer_key', $acquirerKey)->delete();
        $this->flush();
    }

    private function applyOpen(AcquirerHealthState $state): void
    {
        $state->circuit_opened_at = now();
        $state->circuit_half_open_at = null;
        $state->circuit_trip_count = (int) $state->circuit_trip_count + 1;
        $state->consecutive_successes = 0;
    }

    private function applyClose(AcquirerHealthState $state): void
    {
        $state->circuit_opened_at = null;
        $state->circuit_half_open_at = null;
        $state->consecutive_failures = 0;
    }

    private function stateForLatency(int $latencyMs): HealthState
    {
        $threshold = (int) config('acquirer_routing.health.degraded_latency_ms', 3000);

        return $latencyMs > $threshold ? HealthState::DEGRADED : HealthState::ONLINE;
    }

    /**
     * Exponential moving average — recent samples dominate, but one slow
     * request does not flip the average on its own.
     */
    private function blendLatency(?int $current, int $sample): int
    {
        if ($current === null || $current <= 0) {
            return max(0, $sample);
        }

        return (int) round(($current * (1 - self::LATENCY_EMA_ALPHA)) + ($sample * self::LATENCY_EMA_ALPHA));
    }

    private function truncateError(?string $error): ?string
    {
        if ($error === null) {
            return null;
        }

        return Str::limit(trim($error), 500);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function recordEvent(string $acquirerKey, string $type, string $severity, string $message, array $context = []): void
    {
        try {
            AcquirerEvent::query()->create([
                'id' => (string) Str::uuid(),
                'acquirer_key' => $acquirerKey,
                'type' => $type,
                'severity' => $severity,
                'message' => $message,
                'context' => $context,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Falha ao registrar evento da adquirente', [
                'acquirer' => $acquirerKey,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function flush(): void
    {
        try {
            $this->cache->forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // Cache misses are harmless; the next read hits the database.
        }
    }
}
