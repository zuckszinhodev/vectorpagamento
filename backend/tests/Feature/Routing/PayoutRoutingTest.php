<?php

declare(strict_types=1);

/**
 * PIX OUT (withdrawals) routed through the intelligent engine: credentials
 * from the encrypted vault, shared fallback chain, and balance reading
 * normalised across acquirers that disagree on units.
 */

use App\Application\Routing\AcquirerBalanceService;
use App\Application\Routing\PaymentRoutingService;
use App\Domain\Routing\Contracts\BalanceInquiryInterface;
use App\Domain\Routing\Contracts\PaymentProviderInterface;
use App\Domain\Routing\Contracts\ProviderRegistryInterface;
use App\Domain\Routing\Enums\FailureReason;
use App\Domain\Routing\Enums\PaymentMethod;
use App\Domain\Routing\ValueObjects\AcquirerBalance;
use App\Domain\Routing\ValueObjects\ProbeResult;
use App\Domain\Routing\ValueObjects\ProviderRequest;
use App\Domain\Routing\ValueObjects\ProviderResponse;
use App\Domain\Routing\ValueObjects\RoutingContext;
use App\Infrastructure\Routing\Providers\LegacyAcquirerProvider;
use App\Models\AcquirerConfig;
use App\Models\AcquirerRoutingPolicy;
use App\Models\AcquirerRoutingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Payout provider whose transfer outcome and balance are scripted. */
final class ScriptedPayoutProvider implements PaymentProviderInterface, BalanceInquiryInterface
{
    public int $payoutCalls = 0;

    public function __construct(
        private readonly string $key,
        private readonly string $behaviour = 'success',
        private readonly int $balanceCents = 0,
        private readonly bool $balanceReadable = true,
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
        return [PaymentMethod::PIX_OUT];
    }

    public function supportsMethod(PaymentMethod $method): bool
    {
        return $method === PaymentMethod::PIX_OUT;
    }

    public function charge(ProviderRequest $request): ProviderResponse
    {
        return $this->payout($request);
    }

    public function payout(ProviderRequest $request): ProviderResponse
    {
        $this->payoutCalls++;

        return match ($this->behaviour) {
            'success' => ProviderResponse::success(
                $this->key,
                ['transfer_id' => "tr-{$this->key}", 'status' => 'processing'],
                transactionId: "tr-{$this->key}",
                status: 'processing',
            ),
            'timeout' => ProviderResponse::failure($this->key, 'timed out', FailureReason::TIMEOUT),
            'insufficient' => ProviderResponse::failure($this->key, 'saldo insuficiente', FailureReason::PROVIDER_ERROR),
            default => ProviderResponse::failure($this->key, 'erro', FailureReason::PROVIDER_ERROR),
        };
    }

    public function refund(ProviderRequest $request, ?int $amountCents = null): ProviderResponse
    {
        return $this->payout($request);
    }

    public function fetchStatus(string $providerTransactionId): ProviderResponse
    {
        return ProviderResponse::success($this->key, []);
    }

    public function probe(): ProbeResult
    {
        return ProbeResult::healthy(10);
    }

    public function balance(): AcquirerBalance
    {
        return $this->balanceReadable
            ? AcquirerBalance::of($this->key, $this->balanceCents)
            : AcquirerBalance::unavailable($this->key, 'endpoint fora do ar');
    }
}

function registerPayout(
    string $key,
    string $behaviour = 'success',
    int $priority = 10,
    int $balanceCents = 0,
    bool $balanceReadable = true,
): ScriptedPayoutProvider {
    AcquirerConfig::withTrashed()->where('acquirer_key', $key)->forceDelete();

    $config = AcquirerConfig::create([
        'id' => (string) Str::uuid(),
        'acquirer_key' => $key,
        'display_name' => ucfirst($key),
        'type' => 'pix',
        'enabled' => true,
        'supported_methods' => ['pix_out'],
        'priority' => $priority,
    ]);

    AcquirerRoutingProfile::create([
        'id' => (string) Str::uuid(),
        'acquirer_config_id' => $config->id,
        'acquirer_key' => $key,
        'routing_enabled' => true,
        'priority' => $priority,
        'weight' => 1,
        'max_retries' => 0,
        'cb_failure_threshold' => 3,
    ]);

    $provider = new ScriptedPayoutProvider($key, $behaviour, $balanceCents, $balanceReadable);

    app(ProviderRegistryInterface::class)->register(
        $key,
        static fn (): PaymentProviderInterface => $provider,
        [PaymentMethod::PIX_OUT],
    );

    return $provider;
}

beforeEach(function () {
    Cache::flush();

    AcquirerRoutingProfile::query()->delete();
    App\Models\AcquirerHealthState::query()->delete();
    AcquirerConfig::query()->forceDelete();

    app(App\Domain\Routing\Contracts\AcquirerCatalogInterface::class)->flush();
    app(ProviderRegistryInterface::class)->flush();

    AcquirerRoutingPolicy::updateOrCreate(
        ['scope' => 'global', 'account_id' => null, 'payment_method' => 'pix_out'],
        [
            'id' => (string) Str::uuid(),
            'strategy' => 'priority',
            'failover_enabled' => true,
            'max_attempts' => 3,
            'total_budget_ms' => 25000,
            'strict_health_check' => true,
        ]
    );

    $this->routing = app(PaymentRoutingService::class);
    $this->balances = app(AcquirerBalanceService::class);
});

/* ── Roteamento de saque ────────────────────────────────────── */

it('routes a payout without any .env credential', function () {
    config(['acquirers' => []]);

    $provider = registerPayout('psp_saque', 'success');

    $result = $this->routing->payout(
        RoutingContext::make(PaymentMethod::PIX_OUT, 25000),
        ['amount_cents' => 25000, 'pix_key' => 'a@b.com'],
    );

    expect($result->successful)->toBeTrue()
        ->and($result->selectedAcquirer())->toBe('psp_saque')
        ->and($provider->payoutCalls)->toBe(1);
});

it('falls back to the next acquirer when a payout fails', function () {
    $a = registerPayout('psp_a', 'timeout', priority: 1);
    $b = registerPayout('psp_b', 'success', priority: 2);

    $result = $this->routing->payout(
        RoutingContext::make(PaymentMethod::PIX_OUT, 25000),
        ['amount_cents' => 25000],
    );

    expect($result->successful)->toBeTrue()
        ->and($result->selectedAcquirer())->toBe('psp_b')
        ->and($a->payoutCalls)->toBe(1);
});

it('never routes a payout to an acquirer with an open circuit', function () {
    $a = registerPayout('psp_a', 'success', priority: 1);
    registerPayout('psp_b', 'success', priority: 2);

    app(App\Domain\Routing\Contracts\CircuitBreakerInterface::class)->trip('psp_a', 'teste');

    $result = $this->routing->payout(
        RoutingContext::make(PaymentMethod::PIX_OUT, 25000),
        ['amount_cents' => 25000],
    );

    expect($result->selectedAcquirer())->toBe('psp_b')
        ->and($a->payoutCalls)->toBe(0);
});

it('records a routing log for a payout', function () {
    registerPayout('psp_a', 'timeout', priority: 1);
    registerPayout('psp_b', 'success', priority: 2);

    $context = RoutingContext::make(PaymentMethod::PIX_OUT, 25000);
    $this->routing->payout($context, ['amount_cents' => 25000]);

    $log = App\Models\AcquirerRoutingLog::where('correlation_id', $context->correlationId)->first();

    expect($log)->not->toBeNull()
        ->and($log->payment_method)->toBe('pix_out')
        ->and($log->selected_acquirer)->toBe('psp_b')
        ->and($log->attempt_count)->toBe(2);
});

/* ── Saldos ─────────────────────────────────────────────────── */

it('reads balances for every payout acquirer', function () {
    registerPayout('psp_a', 'success', priority: 1, balanceCents: 500_00);
    registerPayout('psp_b', 'success', priority: 2, balanceCents: 300_00);

    $balances = $this->balances->forPayoutAcquirers();

    expect($balances)->toHaveCount(2)
        ->and($balances['psp_a']->availableCents)->toBe(500_00)
        ->and($this->balances->totalAvailableCents())->toBe(800_00);
});

it('orders splittable balances richest first', function () {
    registerPayout('psp_small', 'success', priority: 1, balanceCents: 100_00);
    registerPayout('psp_big', 'success', priority: 2, balanceCents: 900_00);

    $keys = array_map(
        static fn (AcquirerBalance $b): string => $b->acquirerKey,
        $this->balances->splittableBalances()
    );

    // Consuming the largest balance first keeps the number of parts minimal.
    expect($keys)->toBe(['psp_big', 'psp_small']);
});

it('excludes an acquirer whose balance cannot be read from splits', function () {
    registerPayout('psp_ok', 'success', priority: 1, balanceCents: 100_00);
    registerPayout('psp_broken', 'success', priority: 2, balanceReadable: false);

    $keys = array_map(
        static fn (AcquirerBalance $b): string => $b->acquirerKey,
        $this->balances->splittableBalances()
    );

    // Unreadable must not be treated as zero — it is simply not eligible.
    expect($keys)->toBe(['psp_ok'])
        ->and($this->balances->forAcquirer('psp_broken')->available)->toBeFalse();
});

/* ── Normalização de unidade (o erro de fator 100) ──────────── */

it('normalises a balance reported in cents', function () {
    // Enki reports cents as an int.
    $provider = new LegacyAcquirerProvider('enki', 'Enki', new class {
        public function getBalance(): array
        {
            return ['success' => true, 'available' => 250_00, 'reserved' => 10_00];
        }
    }, [PaymentMethod::PIX_OUT]);

    $balance = $provider->balance();

    expect($balance->availableCents)->toBe(250_00)
        ->and($balance->blockedCents)->toBe(10_00);
});

it('normalises a balance reported in reais', function () {
    // BluPay reports reais as a float — the same number means 100x less money.
    $provider = new LegacyAcquirerProvider('blupay', 'BluPay', new class {
        public function getBalance(): array
        {
            return ['success' => true, 'pix_balance' => 250.00, 'pix_blocked' => 10.5];
        }
    }, [PaymentMethod::PIX_OUT]);

    $balance = $provider->balance();

    expect($balance->availableCents)->toBe(250_00)
        ->and($balance->blockedCents)->toBe(1050);
});

it('reports a balance as unavailable rather than zero when unreadable', function () {
    $provider = new LegacyAcquirerProvider('psp_estranho', 'Estranho', new class {
        public function getBalance(): array
        {
            return ['success' => true, 'saldo_em_moeda_local' => 999];
        }
    }, [PaymentMethod::PIX_OUT]);

    $balance = $provider->balance();

    // The old `match` had `default => 0`, which read as "no funds".
    expect($balance->available)->toBeFalse()
        ->and($balance->availableCents)->toBe(0)
        ->and($balance->error)->toContain('não reconhecido');
});

it('falls back to common field names for an unmapped acquirer', function () {
    $provider = new LegacyAcquirerProvider('psp_generico', 'Generico', new class {
        public function getBalance(): array
        {
            return ['success' => true, 'available_balance' => 42.75];
        }
    }, [PaymentMethod::PIX_OUT]);

    expect($provider->balance()->availableCents)->toBe(4275);
});

it('marks an acquirer without a balance endpoint as not supported', function () {
    $provider = new LegacyAcquirerProvider('psp_sem_saldo', 'Sem Saldo', new class {
        public function sendPixTransfer(array $data): array
        {
            return ['success' => true];
        }
    }, [PaymentMethod::PIX_OUT]);

    $balance = $provider->balance();

    expect($balance->available)->toBeFalse()
        ->and($balance->error)->toContain('não expõe');
});

it('surfaces a failed balance call as unavailable', function () {
    $provider = new LegacyAcquirerProvider('psp_falha', 'Falha', new class {
        public function getBalance(): array
        {
            return ['success' => false, 'error' => 'API fora do ar'];
        }
    }, [PaymentMethod::PIX_OUT]);

    expect($provider->balance()->error)->toContain('API fora do ar');
});
