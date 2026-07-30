<?php

declare(strict_types=1);

namespace App\Infrastructure\Routing\Resilience;

use App\Domain\Routing\Contracts\CircuitBreakerInterface;
use App\Domain\Routing\Contracts\HealthStateRepositoryInterface;
use App\Domain\Routing\Enums\CircuitState;
use App\Domain\Routing\Enums\FailureReason;
use App\Models\AcquirerRoutingProfile;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

/**
 * Circuit breaker with per-acquirer thresholds read from the routing profile.
 *
 * Hot-path state lives in the cache (fast, shared across workers); every state
 * transition is also written to `acquirer_health_states` so the dashboard and
 * the router see the same truth and it survives a cache flush.
 *
 * Two properties the previous implementation lacked:
 *   - every acquirer has a breaker, including ones added at runtime;
 *   - half-open admits a bounded number of probes instead of letting every
 *     concurrent request through the moment the cooldown expires.
 */
final class AcquirerCircuitBreaker implements CircuitBreakerInterface
{
    private const PREFIX = 'acq_routing:cb';
    private const STATE_TTL = 3600;

    /** @var array<string, AcquirerRoutingProfile> */
    private array $profileCache = [];

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly HealthStateRepositoryInterface $health,
    ) {
    }

    public function allows(string $acquirerKey): bool
    {
        $state = $this->state($acquirerKey);

        if ($state === CircuitState::CLOSED) {
            return true;
        }

        if ($state === CircuitState::HALF_OPEN) {
            return $this->admitProbe($acquirerKey);
        }

        // OPEN: check whether the cooldown has elapsed.
        $openedAt = (int) $this->cache->get($this->key($acquirerKey, 'opened_at'), 0);
        $cooldown = $this->profile($acquirerKey)->cb_cooldown_seconds ?? 60;

        if ($openedAt > 0 && (time() - $openedAt) >= $cooldown) {
            $this->transitionTo($acquirerKey, CircuitState::HALF_OPEN, 'cooldown elapsed');

            return $this->admitProbe($acquirerKey);
        }

        return false;
    }

    public function state(string $acquirerKey): CircuitState
    {
        $cached = $this->cache->get($this->key($acquirerKey, 'state'));

        if (is_string($cached)) {
            return CircuitState::tryNormalize($cached);
        }

        // Cache cold (restart, flush): rehydrate from the durable store so an
        // open circuit is not silently forgotten.
        $persisted = $this->health->find($acquirerKey)?->circuitState() ?? CircuitState::CLOSED;

        $this->cache->put($this->key($acquirerKey, 'state'), $persisted->value, self::STATE_TTL);

        if ($persisted === CircuitState::OPEN) {
            $openedAt = $this->health->find($acquirerKey)?->circuit_opened_at?->timestamp ?? time();
            $this->cache->put($this->key($acquirerKey, 'opened_at'), $openedAt, self::STATE_TTL);
        }

        return $persisted;
    }

    public function recordSuccess(string $acquirerKey, int $latencyMs = 0): void
    {
        $state = $this->state($acquirerKey);

        $this->cache->put($this->key($acquirerKey, 'failures'), 0, self::STATE_TTL);

        if ($state === CircuitState::CLOSED) {
            return;
        }

        $successes = $this->increment($acquirerKey, 'successes');
        $required = max(1, $this->profile($acquirerKey)->cb_success_threshold ?? 2);

        if ($successes >= $required) {
            $this->transitionTo($acquirerKey, CircuitState::CLOSED, "recuperada após {$successes} sucessos consecutivos");
        }
    }

    public function recordFailure(string $acquirerKey, FailureReason $reason, ?string $error = null): void
    {
        // A declined card is a healthy acquirer answering correctly.
        if (!$reason->countsAgainstCircuit()) {
            return;
        }

        $this->cache->put($this->key($acquirerKey, 'successes'), 0, self::STATE_TTL);

        $state = $this->state($acquirerKey);

        if ($state === CircuitState::HALF_OPEN) {
            $this->transitionTo($acquirerKey, CircuitState::OPEN, "probe falhou: {$reason->label()}");

            return;
        }

        $failures = $this->increment($acquirerKey, 'failures');
        $threshold = max(1, $this->profile($acquirerKey)->cb_failure_threshold ?? 5);

        if ($failures >= $threshold) {
            $this->transitionTo(
                $acquirerKey,
                CircuitState::OPEN,
                sprintf('%d falhas consecutivas (limite %d): %s', $failures, $threshold, $error ?? $reason->label())
            );
        }
    }

    public function reset(string $acquirerKey, string $reason = 'manual'): void
    {
        $this->cache->put($this->key($acquirerKey, 'failures'), 0, self::STATE_TTL);
        $this->cache->put($this->key($acquirerKey, 'successes'), 0, self::STATE_TTL);
        $this->cache->forget($this->key($acquirerKey, 'probes'));

        $this->transitionTo($acquirerKey, CircuitState::CLOSED, $reason);
    }

    public function trip(string $acquirerKey, string $reason = 'manual'): void
    {
        $this->transitionTo($acquirerKey, CircuitState::OPEN, $reason);
    }

    /**
     * Admit at most cb_half_open_max_calls probes per cooldown window.
     *
     * The counter is created with a TTL equal to the cooldown, so it expires on
     * its own if the probes never report back.
     */
    private function admitProbe(string $acquirerKey): bool
    {
        $profile = $this->profile($acquirerKey);
        $maxProbes = max(1, $profile->cb_half_open_max_calls ?? 1);
        $key = $this->key($acquirerKey, 'probes');

        try {
            $current = $this->cache->get($key);

            if ($current === null) {
                $this->cache->put($key, 1, max(5, $profile->cb_cooldown_seconds ?? 60));

                return true;
            }

            $count = $this->cache->increment($key);

            return is_int($count) ? $count <= $maxProbes : true;
        } catch (\Throwable $e) {
            Log::warning('Falha ao controlar probes do circuit breaker; permitindo tentativa', [
                'acquirer' => $acquirerKey,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    private function transitionTo(string $acquirerKey, CircuitState $state, string $reason): void
    {
        $previous = $this->cache->get($this->key($acquirerKey, 'state'));

        $this->cache->put($this->key($acquirerKey, 'state'), $state->value, self::STATE_TTL);

        if ($state === CircuitState::OPEN) {
            $this->cache->put($this->key($acquirerKey, 'opened_at'), time(), self::STATE_TTL);
            $this->cache->forget($this->key($acquirerKey, 'probes'));
        }

        if ($state === CircuitState::CLOSED) {
            $this->cache->forget($this->key($acquirerKey, 'opened_at'));
            $this->cache->forget($this->key($acquirerKey, 'probes'));
        }

        if ($previous === $state->value) {
            return;
        }

        Log::log(
            $state === CircuitState::OPEN ? 'error' : 'warning',
            "Circuit breaker da adquirente '{$acquirerKey}': {$state->label()}",
            ['acquirer' => $acquirerKey, 'from' => $previous, 'to' => $state->value, 'reason' => $reason]
        );

        $this->health->recordCircuitTransition($acquirerKey, $state, $reason);
    }

    private function increment(string $acquirerKey, string $counter): int
    {
        $key = $this->key($acquirerKey, $counter);

        try {
            $value = $this->cache->increment($key);

            if (!is_int($value)) {
                $this->cache->put($key, 1, self::STATE_TTL);

                return 1;
            }

            return $value;
        } catch (\Throwable $e) {
            Log::warning('Falha ao incrementar contador do circuit breaker', [
                'acquirer' => $acquirerKey,
                'counter' => $counter,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function profile(string $acquirerKey): AcquirerRoutingProfile
    {
        if (isset($this->profileCache[$acquirerKey])) {
            return $this->profileCache[$acquirerKey];
        }

        $profile = null;

        try {
            $profile = AcquirerRoutingProfile::query()->where('acquirer_key', $acquirerKey)->first();
        } catch (\Throwable $e) {
            Log::warning('Falha ao carregar perfil de roteamento; usando padrões', [
                'acquirer' => $acquirerKey,
                'error' => $e->getMessage(),
            ]);
        }

        $profile ??= new AcquirerRoutingProfile(array_merge(
            (array) config('acquirer_routing.profile_defaults', []),
            ['acquirer_key' => $acquirerKey]
        ));

        return $this->profileCache[$acquirerKey] = $profile;
    }

    private function key(string $acquirerKey, string $suffix): string
    {
        return sprintf('%s:%s:%s', self::PREFIX, $acquirerKey, $suffix);
    }
}
