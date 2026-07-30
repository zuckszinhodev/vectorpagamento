<?php

declare(strict_types=1);

use App\Application\Routing\PaymentRoutingService;
use App\Domain\Routing\Contracts\CircuitBreakerInterface;
use App\Domain\Routing\Contracts\HealthStateRepositoryInterface;
use App\Domain\Routing\Contracts\PaymentProviderInterface;
use App\Domain\Routing\Contracts\ProviderRegistryInterface;
use App\Domain\Routing\Enums\CircuitState;
use App\Domain\Routing\Enums\FailureReason;
use App\Domain\Routing\Enums\HealthState;
use App\Domain\Routing\Enums\PaymentMethod;
use App\Domain\Routing\ValueObjects\ProbeResult;
use App\Domain\Routing\ValueObjects\ProviderRequest;
use App\Domain\Routing\ValueObjects\ProviderResponse;
use App\Domain\Routing\ValueObjects\RoutingContext;
use App\Models\AcquirerConfig;
use App\Models\AcquirerMetricBucket;
use App\Models\AcquirerRoutingLog;
use App\Models\AcquirerRoutingPolicy;
use App\Models\AcquirerRoutingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Scriptable provider: each instance is told exactly how to behave, so the
 * fallback chain can be exercised deterministically without network calls.
 */
final class ScriptedProvider implements PaymentProviderInterface
{
    public int $chargeCalls = 0;

    public function __construct(
        private readonly string $key,
        private readonly string $behaviour,
        private readonly int $latencyMs = 5,
        private readonly int $succeedAfterCalls = 0,
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
        return [PaymentMethod::PIX, PaymentMethod::PIX_OUT, PaymentMethod::CREDIT_CARD];
    }

    public function supportsMethod(PaymentMethod $method): bool
    {
        return in_array($method, $this->supports(), true);
    }

    public function charge(ProviderRequest $request): ProviderResponse
    {
        $this->chargeCalls++;

        if ($this->latencyMs > 0) {
            usleep($this->latencyMs * 1000);
        }

        if ($this->succeedAfterCalls > 0 && $this->chargeCalls > $this->succeedAfterCalls) {
            return $this->success();
        }

        return match ($this->behaviour) {
            'success' => $this->success(),
            'timeout' => ProviderResponse::failure($this->key, 'Request timed out', FailureReason::TIMEOUT),
            'refused' => ProviderResponse::failure($this->key, 'connection refused', FailureReason::CONNECTION_REFUSED),
            'error' => ProviderResponse::failure($this->key, 'provider error 500', FailureReason::PROVIDER_ERROR),
            'declined' => ProviderResponse::failure($this->key, 'Cartão recusado', FailureReason::DECLINED),
            'throws' => throw new RuntimeException('cURL error 28: Operation timed out'),
            default => $this->success(),
        };
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
        return $this->success();
    }

    public function probe(): ProbeResult
    {
        return $this->behaviour === 'success'
            ? ProbeResult::healthy($this->latencyMs)
            : ProbeResult::unhealthy($this->latencyMs, "probe falhou para {$this->key}");
    }

    private function success(): ProviderResponse
    {
        return ProviderResponse::success(
            $this->key,
            ['pix_qr_code' => "qr-{$this->key}", 'pix_copy_paste' => "copia-e-cola-{$this->key}"],
            transactionId: "tx-{$this->key}-{$this->chargeCalls}",
            status: 'pending',
        );
    }
}

/** Register an acquirer in the database and bind a scripted provider to it. */
function registerScripted(
    string $key,
    string $behaviour,
    int $priority = 10,
    int $latencyMs = 5,
    array $profile = [],
    int $succeedAfterCalls = 0,
): ScriptedProvider {
    $config = AcquirerConfig::create([
        'id' => (string) Str::uuid(),
        'acquirer_key' => $key,
        'display_name' => ucfirst($key),
        'type' => 'pix',
        'enabled' => true,
        'supported_methods' => ['pix'],
        'priority' => $priority,
    ]);

    AcquirerRoutingProfile::create(array_merge([
        'id' => (string) Str::uuid(),
        'acquirer_config_id' => $config->id,
        'acquirer_key' => $key,
        'routing_enabled' => true,
        'priority' => $priority,
        'weight' => 1,
        'timeout_ms' => 5000,
        'connect_timeout_ms' => 1000,
        'max_retries' => 0,
        'retry_backoff_ms' => 1,
        'cb_failure_threshold' => 3,
        'cb_cooldown_seconds' => 60,
        'cb_half_open_max_calls' => 1,
        'cb_success_threshold' => 1,
    ], $profile));

    $provider = new ScriptedProvider($key, $behaviour, $latencyMs, $succeedAfterCalls);

    app(ProviderRegistryInterface::class)->register(
        $key,
        static fn (): PaymentProviderInterface => $provider,
        [PaymentMethod::PIX],
    );

    return $provider;
}

function pixPolicy(array $overrides = []): void
{
    AcquirerRoutingPolicy::updateOrCreate(
        ['scope' => 'global', 'account_id' => null, 'payment_method' => 'pix'],
        array_merge([
            'id' => (string) Str::uuid(),
            'strategy' => 'priority',
            'failover_enabled' => true,
            'max_attempts' => 3,
            'total_budget_ms' => 25000,
            'strict_health_check' => true,
        ], $overrides)
    );
}

beforeEach(function () {
    Cache::flush();

    // Earlier migrations seed real acquirers (payfort, stripe, ...). They would
    // join the candidate list and make these assertions meaningless, so the
    // catalog is emptied and each test declares exactly what it wants.
    AcquirerRoutingProfile::query()->delete();
    App\Models\AcquirerHealthState::query()->delete();
    AcquirerConfig::query()->forceDelete();

    app(App\Domain\Routing\Contracts\AcquirerCatalogInterface::class)->flush();
    app(ProviderRegistryInterface::class)->flush();

    pixPolicy();
    $this->routing = app(PaymentRoutingService::class);
});

/* ── Cadeia de fallback ─────────────────────────────────────── */

it('falls back through the chain until one acquirer succeeds', function () {
    // Cenário do enunciado: A erro → B timeout → C sucesso.
    $a = registerScripted('acq_a', 'error', priority: 1);
    $b = registerScripted('acq_b', 'timeout', priority: 2);
    $c = registerScripted('acq_c', 'success', priority: 3);

    $result = $this->routing->charge(null, RoutingContext::make('pix', 15000));

    expect($result->successful)->toBeTrue()
        ->and($result->selectedAcquirer())->toBe('acq_c')
        ->and($a->chargeCalls)->toBe(1)
        ->and($b->chargeCalls)->toBe(1)
        ->and($c->chargeCalls)->toBe(1);
});

it('returns only the final response, hiding intermediate attempts from the caller', function () {
    registerScripted('acq_a', 'error', priority: 1);
    registerScripted('acq_b', 'success', priority: 2);

    $payload = $this->routing->charge(null, RoutingContext::make('pix', 15000))->toLegacyArray();

    expect($payload['success'])->toBeTrue()
        ->and($payload['pix_qr_code'])->toBe('qr-acq_b')
        // The customer never learns that acq_a was tried and failed.
        ->and($payload)->not->toHaveKey('error')
        ->and(json_encode($payload))->not->toContain('acq_a');
});

it('stops at the first success without calling later acquirers', function () {
    $a = registerScripted('acq_a', 'success', priority: 1);
    $b = registerScripted('acq_b', 'success', priority: 2);

    $this->routing->charge(null, RoutingContext::make('pix', 15000));

    expect($a->chargeCalls)->toBe(1)
        ->and($b->chargeCalls)->toBe(0);
});

it('reports a clean failure when every acquirer fails', function () {
    registerScripted('acq_a', 'error', priority: 1);
    registerScripted('acq_b', 'timeout', priority: 2);

    $result = $this->routing->charge(null, RoutingContext::make('pix', 15000));

    expect($result->successful)->toBeFalse()
        ->and($result->attemptCount())->toBe(2)
        // The message must not leak which acquirers were tried.
        ->and($result->toLegacyArray()['error'])->not->toContain('acq_a');
});

it('does not fail over when the transaction was legitimately declined', function () {
    $a = registerScripted('acq_a', 'declined', priority: 1);
    $b = registerScripted('acq_b', 'success', priority: 2);

    $result = $this->routing->charge(null, RoutingContext::make('pix', 15000));

    // Retrying a declined transaction elsewhere risks a double charge.
    expect($result->successful)->toBeFalse()
        ->and($b->chargeCalls)->toBe(0);
});

it('honours max_attempts from the routing policy', function () {
    pixPolicy(['max_attempts' => 2]);

    registerScripted('acq_a', 'error', priority: 1);
    registerScripted('acq_b', 'error', priority: 2);
    $c = registerScripted('acq_c', 'success', priority: 3);

    $result = $this->routing->charge(null, RoutingContext::make('pix', 15000));

    expect($result->successful)->toBeFalse()
        ->and($c->chargeCalls)->toBe(0);
});

it('does not fail over at all when failover is disabled', function () {
    pixPolicy(['failover_enabled' => false]);

    registerScripted('acq_a', 'error', priority: 1);
    $b = registerScripted('acq_b', 'success', priority: 2);

    $result = $this->routing->charge(null, RoutingContext::make('pix', 15000));

    expect($result->successful)->toBeFalse()
        ->and($b->chargeCalls)->toBe(0);
});

it('converts a thrown exception into a normal failed attempt and keeps going', function () {
    registerScripted('acq_a', 'throws', priority: 1);
    $b = registerScripted('acq_b', 'success', priority: 2);

    $result = $this->routing->charge(null, RoutingContext::make('pix', 15000));

    expect($result->successful)->toBeTrue()
        ->and($b->chargeCalls)->toBe(1);
});

/* ── Retry com backoff ──────────────────────────────────────── */

it('retries the same acquirer on a transient failure before failing over', function () {
    $a = registerScripted('acq_a', 'timeout', priority: 1, profile: ['max_retries' => 2], succeedAfterCalls: 2);
    $b = registerScripted('acq_b', 'success', priority: 2);

    $result = $this->routing->charge(null, RoutingContext::make('pix', 15000));

    // Two failures then success, all on acq_a — no fallback needed.
    expect($result->successful)->toBeTrue()
        ->and($result->selectedAcquirer())->toBe('acq_a')
        ->and($a->chargeCalls)->toBe(3)
        ->and($b->chargeCalls)->toBe(0);
});

it('does not retry a non-transient provider error', function () {
    $a = registerScripted('acq_a', 'error', priority: 1, profile: ['max_retries' => 3]);
    registerScripted('acq_b', 'success', priority: 2);

    $this->routing->charge(null, RoutingContext::make('pix', 15000));

    // A 500 that is a real answer: retrying just adds latency before failover.
    expect($a->chargeCalls)->toBe(1);
});

/* ── Circuit breaker no roteamento ──────────────────────────── */

it('skips an acquirer whose circuit is open', function () {
    $a = registerScripted('acq_a', 'success', priority: 1);
    $b = registerScripted('acq_b', 'success', priority: 2);

    app(CircuitBreakerInterface::class)->trip('acq_a', 'teste');

    $result = $this->routing->charge(null, RoutingContext::make('pix', 15000));

    expect($result->selectedAcquirer())->toBe('acq_b')
        ->and($a->chargeCalls)->toBe(0);
});

it('opens the circuit after repeated failures during real traffic', function () {
    registerScripted('acq_a', 'timeout', priority: 1, profile: ['cb_failure_threshold' => 2]);
    registerScripted('acq_b', 'success', priority: 2);

    $this->routing->charge(null, RoutingContext::make('pix', 15000));
    $this->routing->charge(null, RoutingContext::make('pix', 15000));

    expect(app(CircuitBreakerInterface::class)->state('acq_a'))->toBe(CircuitState::OPEN);
});

/* ── Saúde: nunca rotear para indisponível ──────────────────── */

it('never routes to an acquirer marked offline', function () {
    $a = registerScripted('acq_a', 'success', priority: 1);
    registerScripted('acq_b', 'success', priority: 2);

    app(HealthStateRepositoryInterface::class)->setState('acq_a', HealthState::OFFLINE, 'teste');

    $result = $this->routing->charge(null, RoutingContext::make('pix', 15000));

    expect($result->selectedAcquirer())->toBe('acq_b')
        ->and($a->chargeCalls)->toBe(0);
});

it('fails rather than routing to an acquirer known to be unavailable', function () {
    $a = registerScripted('acq_a', 'success', priority: 1);
    app(HealthStateRepositoryInterface::class)->setState('acq_a', HealthState::OFFLINE, 'teste');

    $result = $this->routing->charge(null, RoutingContext::make('pix', 15000));

    // The old rotation service handed back every filtered-out candidate when
    // the eligible list came up empty. That is the bug this locks down.
    expect($result->successful)->toBeFalse()
        ->and($a->chargeCalls)->toBe(0);
});

it('still routes to a brand new acquirer with unknown health', function () {
    $a = registerScripted('acq_new', 'success', priority: 1);

    $result = $this->routing->charge(null, RoutingContext::make('pix', 15000));

    // UNKNOWN must be routable, otherwise a new acquirer can never prove itself.
    expect($result->successful)->toBeTrue()
        ->and($a->chargeCalls)->toBe(1);
});

/* ── Limites de valor e TPS ─────────────────────────────────── */

it('skips an acquirer that does not accept the transaction amount', function () {
    $a = registerScripted('acq_a', 'success', priority: 1, profile: ['max_amount_cents' => 10000]);
    $b = registerScripted('acq_b', 'success', priority: 2);

    $result = $this->routing->charge(null, RoutingContext::make('pix', 50000));

    expect($result->selectedAcquirer())->toBe('acq_b')
        ->and($a->chargeCalls)->toBe(0);
});

it('routes around an acquirer that hit its TPS ceiling', function () {
    $a = registerScripted('acq_a', 'success', priority: 1, profile: ['max_tps' => 1]);
    $b = registerScripted('acq_b', 'success', priority: 2);

    // First transaction consumes acq_a's only slot for this second.
    $this->routing->charge(null, RoutingContext::make('pix', 1000));
    $second = $this->routing->charge(null, RoutingContext::make('pix', 1000));

    expect($a->chargeCalls)->toBe(1)
        ->and($second->selectedAcquirer())->toBe('acq_b')
        ->and($b->chargeCalls)->toBe(1);
});

/* ── Orçamento de tempo ─────────────────────────────────────── */

it('stops walking the chain when the total time budget is exhausted', function () {
    pixPolicy(['total_budget_ms' => 1200]);
    config(['acquirer_routing.defaults.total_budget_ms' => 1200]);

    registerScripted('acq_a', 'error', priority: 1, latencyMs: 700);
    registerScripted('acq_b', 'error', priority: 2, latencyMs: 700);
    $c = registerScripted('acq_c', 'success', priority: 3, latencyMs: 10);

    $result = $this->routing->charge(null, RoutingContext::make('pix', 15000));

    // Two slow failures already blew the budget: stop instead of piling on.
    expect($result->successful)->toBeFalse()
        ->and($c->chargeCalls)->toBe(0);
});

/* ── Observabilidade ────────────────────────────────────────── */

it('persists one routing log per transaction with the full attempt chain', function () {
    registerScripted('acq_a', 'error', priority: 1);
    registerScripted('acq_b', 'success', priority: 2);

    $context = RoutingContext::make('pix', 15000);
    $this->routing->charge(null, $context);

    $log = AcquirerRoutingLog::where('correlation_id', $context->correlationId)->first();

    expect($log)->not->toBeNull()
        ->and($log->outcome)->toBe('success')
        ->and($log->selected_acquirer)->toBe('acq_b')
        ->and($log->attempt_count)->toBe(2)
        ->and($log->attempts)->toHaveCount(2)
        ->and($log->attempts[0]['acquirer'])->toBe('acq_a')
        ->and($log->attempts[0]['succeeded'])->toBeFalse()
        ->and($log->attempts[1]['succeeded'])->toBeTrue()
        ->and($log->selection_reason)->not->toBeEmpty();
});

it('records metrics for both the failed and the successful acquirer', function () {
    registerScripted('acq_a', 'error', priority: 1);
    registerScripted('acq_b', 'success', priority: 2);

    $this->routing->charge(null, RoutingContext::make('pix', 15000));

    $failed = AcquirerMetricBucket::where('acquirer_key', 'acq_a')->first();
    $succeeded = AcquirerMetricBucket::where('acquirer_key', 'acq_b')->first();

    expect($failed->attempts)->toBe(1)
        ->and($failed->failures)->toBe(1)
        ->and($succeeded->successes)->toBe(1)
        ->and($succeeded->volume_cents)->toBe(15000)
        // acq_b took over from acq_a, so it counts as a fallback.
        ->and($succeeded->fallback_ins)->toBe(1);
});

it('marks the log as no_acquirer when nothing is eligible', function () {
    $context = RoutingContext::make('pix', 15000);
    $result = $this->routing->charge(null, $context);

    expect($result->successful)->toBeFalse()
        ->and(AcquirerRoutingLog::where('correlation_id', $context->correlationId)->value('outcome'))
        ->toBe('no_acquirer');
});
