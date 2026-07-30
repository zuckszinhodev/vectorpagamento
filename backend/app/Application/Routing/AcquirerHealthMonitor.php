<?php

declare(strict_types=1);

namespace App\Application\Routing;

use App\Domain\Routing\Contracts\CircuitBreakerInterface;
use App\Domain\Routing\Contracts\HealthStateRepositoryInterface;
use App\Domain\Routing\Contracts\ProviderRegistryInterface;
use App\Domain\Routing\Enums\CircuitState;
use App\Domain\Routing\ValueObjects\ProbeResult;
use App\Models\AcquirerConfig;
use App\Models\AcquirerRoutingProfile;
use Illuminate\Support\Facades\Log;

/**
 * Periodically probes acquirers and keeps their availability up to date.
 *
 * This is also the recovery path for the circuit breaker: an acquirer whose
 * circuit is open gets probed anyway, and a successful probe closes it — so a
 * recovered acquirer returns to rotation automatically instead of waiting for
 * a real transaction to gamble on it.
 */
final class AcquirerHealthMonitor
{
    public function __construct(
        private readonly ProviderRegistryInterface $providers,
        private readonly HealthStateRepositoryInterface $health,
        private readonly CircuitBreakerInterface $circuitBreaker,
    ) {
    }

    /**
     * Probe every enabled acquirer that is due for a check.
     *
     * @return array<string, array<string, mixed>> results keyed by acquirer
     */
    public function checkAll(bool $force = false): array
    {
        if (!config('acquirer_routing.health.enabled', true) && !$force) {
            return [];
        }

        $results = [];

        $configs = AcquirerConfig::query()->where('enabled', true)->get();
        $profiles = AcquirerRoutingProfile::query()->get()->keyBy('acquirer_key');

        foreach ($configs as $config) {
            $profile = $profiles->get($config->acquirer_key);

            if (!$force && !$this->isDue($config->acquirer_key, $profile)) {
                continue;
            }

            if ($profile && !$profile->health_check_enabled && !$force) {
                continue;
            }

            $results[$config->acquirer_key] = $this->check($config->acquirer_key)->toArray();
        }

        return $results;
    }

    /**
     * Probe a single acquirer and apply the consequences.
     */
    public function check(string $acquirerKey): ProbeResult
    {
        $provider = $this->providers->resolve($acquirerKey);

        if ($provider === null) {
            $result = ProbeResult::unhealthy(
                0,
                'Módulo indisponível ou credenciais inválidas/ausentes.'
            );

            $this->health->recordProbe($acquirerKey, false, 0, $result->error);

            return $result;
        }

        try {
            $result = $provider->probe();
        } catch (\Throwable $e) {
            Log::warning('Health check da adquirente lançou exceção', [
                'acquirer' => $acquirerKey,
                'error' => $e->getMessage(),
            ]);

            $result = ProbeResult::unhealthy(0, $e->getMessage());
        }

        // "No safe probe available" is not evidence of anything — recording it
        // as a failure is exactly how the old job marked every acquirer down.
        if ($result->skipped) {
            Log::debug('Adquirente sem probe disponível; estado inalterado', [
                'acquirer' => $acquirerKey,
                'reason' => $result->error,
            ]);

            return $result;
        }

        $this->health->recordProbe($acquirerKey, $result->healthy, $result->latencyMs, $result->error);
        $this->applyCircuitConsequences($acquirerKey, $result);

        return $result;
    }

    /**
     * A healthy probe closes an open circuit; a failed one leaves the breaker
     * to its own threshold logic (a single failed probe should not take an
     * otherwise working acquirer out of rotation).
     */
    private function applyCircuitConsequences(string $acquirerKey, ProbeResult $result): void
    {
        $state = $this->circuitBreaker->state($acquirerKey);

        if ($result->healthy && $state !== CircuitState::CLOSED) {
            Log::info('Adquirente recuperada; recolocando no roteamento', [
                'acquirer' => $acquirerKey,
                'previous_circuit' => $state->value,
                'latency_ms' => $result->latencyMs,
            ]);

            $this->circuitBreaker->reset($acquirerKey, 'health check bem-sucedido');
        }
    }

    /**
     * Whether enough time has passed since the last check for this acquirer.
     */
    private function isDue(string $acquirerKey, ?AcquirerRoutingProfile $profile): bool
    {
        $state = $this->health->find($acquirerKey);

        if ($state?->last_check_at === null) {
            return true;
        }

        $interval = $profile->health_check_interval_seconds
            ?? (int) config('acquirer_routing.health.default_interval_seconds', 60);

        // An acquirer that is down gets probed more often so recovery is fast.
        if (!$state->healthState()->isRoutable()) {
            $interval = (int) max(15, $interval / 2);
        }

        return $state->last_check_at->addSeconds($interval)->isPast();
    }
}
