<?php

declare(strict_types=1);

use App\Application\Routing\AcquirerHealthMonitor;
use App\Domain\Routing\Contracts\CircuitBreakerInterface;
use App\Domain\Routing\Contracts\HealthStateRepositoryInterface;
use App\Domain\Routing\Contracts\PaymentProviderInterface;
use App\Domain\Routing\Contracts\ProviderRegistryInterface;
use App\Domain\Routing\Enums\CircuitState;
use App\Domain\Routing\Enums\HealthState;
use App\Domain\Routing\Enums\PaymentMethod;
use App\Domain\Routing\ValueObjects\ProbeResult;
use App\Domain\Routing\ValueObjects\ProviderRequest;
use App\Domain\Routing\ValueObjects\ProviderResponse;
use App\Models\AcquirerConfig;
use App\Models\AcquirerEvent;
use App\Models\AcquirerRoutingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Provider whose probe outcome is controlled by the test. */
final class ProbeableProvider implements PaymentProviderInterface
{
    public int $probeCalls = 0;

    public function __construct(
        private readonly string $key,
        public string $mode = 'healthy',
        public int $latencyMs = 50,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function displayName(): string
    {
        return ucfirst($this->key);
    }

    public function supports(): array
    {
        return [PaymentMethod::PIX];
    }

    public function supportsMethod(PaymentMethod $method): bool
    {
        return $method === PaymentMethod::PIX;
    }

    public function charge(ProviderRequest $request): ProviderResponse
    {
        return ProviderResponse::success($this->key, ['pix_qr_code' => 'qr']);
    }

    public function payout(ProviderRequest $request): ProviderResponse
    {
        return $this->charge($request);
    }

    public function refund(ProviderRequest $request, ?int $amountCents = null): ProviderResponse
    {
        return $this->charge($request);
    }

    public function fetchStatus(string $providerTransactionId): ProviderResponse
    {
        return $this->charge(new ProviderRequest(
            PaymentMethod::PIX,
            App\Domain\Routing\ValueObjects\RoutingContext::make('pix', 0)
        ));
    }

    public function probe(): ProbeResult
    {
        $this->probeCalls++;

        return match ($this->mode) {
            'healthy' => ProbeResult::healthy($this->latencyMs),
            'slow' => ProbeResult::healthy($this->latencyMs),
            'unhealthy' => ProbeResult::unhealthy($this->latencyMs, 'connection refused'),
            'skipped' => ProbeResult::skipped('sem endpoint de verificação'),
            'throws' => throw new RuntimeException('probe explodiu'),
            default => ProbeResult::healthy($this->latencyMs),
        };
    }
}

function registerProbeable(string $key, string $mode = 'healthy', int $latencyMs = 50): ProbeableProvider
{
    AcquirerConfig::withTrashed()->where('acquirer_key', $key)->forceDelete();

    $config = AcquirerConfig::create([
        'id' => (string) Str::uuid(),
        'acquirer_key' => $key,
        'display_name' => ucfirst($key),
        'type' => 'pix',
        'enabled' => true,
        'supported_methods' => ['pix'],
        'priority' => 10,
    ]);

    AcquirerRoutingProfile::create([
        'id' => (string) Str::uuid(),
        'acquirer_config_id' => $config->id,
        'acquirer_key' => $key,
        'routing_enabled' => true,
        'priority' => 10,
        'weight' => 1,
        'health_check_enabled' => true,
        'health_check_interval_seconds' => 60,
        'cb_failure_threshold' => 3,
        'cb_cooldown_seconds' => 60,
        'cb_success_threshold' => 1,
    ]);

    $provider = new ProbeableProvider($key, $mode, $latencyMs);

    app(ProviderRegistryInterface::class)->register(
        $key,
        static fn (): PaymentProviderInterface => $provider,
        [PaymentMethod::PIX],
    );

    return $provider;
}

beforeEach(function () {
    Cache::flush();
    app(ProviderRegistryInterface::class)->flush();

    $this->monitor = app(AcquirerHealthMonitor::class);
    $this->health = app(HealthStateRepositoryInterface::class);
    $this->breaker = app(CircuitBreakerInterface::class);
});

it('marks an acquirer online after a successful probe', function () {
    registerProbeable('psp_ok', 'healthy', latencyMs: 40);

    $this->monitor->check('psp_ok');

    $state = $this->health->find('psp_ok');

    expect($state->healthState())->toBe(HealthState::ONLINE)
        ->and($state->last_latency_ms)->toBe(40)
        ->and($state->last_check_at)->not->toBeNull();
});

it('marks an acquirer degraded when the probe is slow', function () {
    config(['acquirer_routing.health.degraded_latency_ms' => 100]);
    registerProbeable('psp_slow', 'slow', latencyMs: 5000);

    $this->monitor->check('psp_slow');

    expect($this->health->find('psp_slow')->healthState())->toBe(HealthState::DEGRADED);
});

it('marks an acquirer degraded on the first failed probe, offline on the second', function () {
    config(['acquirer_routing.health.offline_after_failures' => 2]);
    registerProbeable('psp_bad', 'unhealthy');

    $this->monitor->check('psp_bad');
    expect($this->health->find('psp_bad')->healthState())->toBe(HealthState::DEGRADED);

    $this->monitor->check('psp_bad');
    expect($this->health->find('psp_bad')->healthState())->toBe(HealthState::OFFLINE);
});

it('records the error message from a failed probe', function () {
    registerProbeable('psp_bad', 'unhealthy');

    $this->monitor->check('psp_bad');

    expect($this->health->find('psp_bad')->last_error)->toContain('connection refused');
});

it('does not change state when the acquirer offers no safe probe', function () {
    registerProbeable('psp_noprobe', 'skipped');

    $result = $this->monitor->check('psp_noprobe');

    // The old job treated "no probe endpoint" as unhealthy, which is how it
    // ended up reporting every acquirer as down.
    expect($result->skipped)->toBeTrue()
        ->and($this->health->find('psp_noprobe'))->toBeNull();
});

it('treats a throwing probe as a failure instead of crashing the run', function () {
    registerProbeable('psp_throws', 'throws');

    $result = $this->monitor->check('psp_throws');

    expect($result->healthy)->toBeFalse()
        ->and($result->error)->toContain('probe explodiu');
});

it('marks an acquirer offline when its module cannot be resolved', function () {
    AcquirerConfig::withTrashed()->where('acquirer_key', 'psp_missing')->forceDelete();
    AcquirerConfig::create([
        'id' => (string) Str::uuid(),
        'acquirer_key' => 'psp_missing',
        'display_name' => 'Missing',
        'type' => 'pix',
        'enabled' => true,
        'supported_methods' => ['pix'],
        'priority' => 10,
    ]);

    $result = $this->monitor->check('psp_missing');

    expect($result->healthy)->toBeFalse()
        ->and($this->health->find('psp_missing')->healthState())->not->toBe(HealthState::ONLINE);
});

it('tracks availability across probes', function () {
    $provider = registerProbeable('psp_flaky', 'healthy');

    $this->monitor->check('psp_flaky');
    $this->monitor->check('psp_flaky');
    $provider->mode = 'unhealthy';
    $this->monitor->check('psp_flaky');
    $this->monitor->check('psp_flaky');

    $state = $this->health->find('psp_flaky');

    expect($state->checks_total)->toBe(4)
        ->and($state->checks_ok)->toBe(2)
        ->and($state->availabilityPercent())->toBe(50.0);
});

it('records a status-change event when availability flips', function () {
    $provider = registerProbeable('psp_flip', 'healthy');
    $this->monitor->check('psp_flip');

    $provider->mode = 'unhealthy';
    $this->monitor->check('psp_flip');

    expect(AcquirerEvent::where('acquirer_key', 'psp_flip')
        ->where('type', AcquirerEvent::TYPE_STATUS_CHANGE)
        ->exists())->toBeTrue();
});

/* ── Recuperação automática ─────────────────────────────────── */

it('closes an open circuit when the acquirer recovers', function () {
    $provider = registerProbeable('psp_recover', 'unhealthy');

    // Drive the circuit open through failures.
    for ($i = 0; $i < 3; $i++) {
        $this->breaker->recordFailure('psp_recover', App\Domain\Routing\Enums\FailureReason::TIMEOUT);
    }
    expect($this->breaker->state('psp_recover'))->toBe(CircuitState::OPEN);

    // The acquirer comes back and the scheduled probe notices.
    $provider->mode = 'healthy';
    $this->monitor->check('psp_recover');

    expect($this->breaker->state('psp_recover'))->toBe(CircuitState::CLOSED)
        ->and($this->health->find('psp_recover')->healthState())->toBe(HealthState::ONLINE);
});

it('probes an acquirer even while its circuit is open', function () {
    $provider = registerProbeable('psp_open', 'healthy');
    $this->breaker->trip('psp_open', 'teste');

    $this->monitor->check('psp_open');

    // Recovery depends on probing acquirers that are currently excluded.
    expect($provider->probeCalls)->toBe(1);
});

it('brings an offline acquirer back when real traffic succeeds', function () {
    registerProbeable('psp_traffic', 'healthy');
    $this->health->setState('psp_traffic', HealthState::OFFLINE, 'teste');

    $this->health->recordTraffic('psp_traffic', true, 120);

    // A completed payment is stronger evidence than any probe.
    expect($this->health->find('psp_traffic')->healthState())->toBe(HealthState::ONLINE);
});

/* ── Agendamento ────────────────────────────────────────────── */

it('skips acquirers that are not due for a check yet', function () {
    $provider = registerProbeable('psp_due', 'healthy');

    $this->monitor->checkAll();
    expect($provider->probeCalls)->toBe(1);

    // Interval is 60s and no time has passed.
    $this->monitor->checkAll();
    expect($provider->probeCalls)->toBe(1);
});

it('forces a check regardless of the interval when asked', function () {
    $provider = registerProbeable('psp_forced', 'healthy');

    $this->monitor->checkAll();
    $this->monitor->checkAll(force: true);

    expect($provider->probeCalls)->toBe(2);
});

it('probes a down acquirer more often than a healthy one', function () {
    $provider = registerProbeable('psp_down', 'unhealthy');
    config(['acquirer_routing.health.offline_after_failures' => 1]);

    $this->monitor->check('psp_down');
    expect($this->health->find('psp_down')->healthState())->toBe(HealthState::OFFLINE);

    // Down acquirers halve their interval (60s → 30s) to recover faster.
    $state = $this->health->find('psp_down');
    $state->last_check_at = now()->subSeconds(35);
    $state->save();

    $this->monitor->checkAll();

    expect($provider->probeCalls)->toBe(2);
});
