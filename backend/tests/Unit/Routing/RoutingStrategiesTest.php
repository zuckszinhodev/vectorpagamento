<?php

declare(strict_types=1);

use App\Domain\Routing\Contracts\RotationCursorInterface;
use App\Domain\Routing\Enums\CircuitState;
use App\Domain\Routing\Enums\HealthState;
use App\Domain\Routing\Strategies\BestApprovalRateStrategy;
use App\Domain\Routing\Strategies\LeastLatencyStrategy;
use App\Domain\Routing\Strategies\LeastUtilizationStrategy;
use App\Domain\Routing\Strategies\PriorityStrategy;
use App\Domain\Routing\Strategies\RoundRobinStrategy;
use App\Domain\Routing\Strategies\WeightedRoundRobinStrategy;
use App\Domain\Routing\ValueObjects\AcquirerCandidate;
use App\Domain\Routing\ValueObjects\AcquirerMetrics;
use App\Domain\Routing\ValueObjects\RoutingContext;

/** In-memory cursor so rotation tests do not depend on the cache driver. */
final class ArrayRotationCursor implements RotationCursorInterface
{
    /** @var array<string, int> */
    private array $values = [];

    public function next(string $key): int
    {
        $current = $this->values[$key] ?? 0;
        $this->values[$key] = $current + 1;

        return $current;
    }

    public function peek(string $key): int
    {
        return $this->values[$key] ?? 0;
    }

    public function reset(string $key): void
    {
        unset($this->values[$key]);
    }
}

function candidate(
    string $key,
    int $priority = 100,
    int $weight = 1,
    int $attempts = 0,
    int $successes = 0,
    int $latencySumMs = 0,
    ?int $maxTps = null,
    float $currentTps = 0.0,
): AcquirerCandidate {
    return new AcquirerCandidate(
        key: $key,
        displayName: ucfirst($key),
        configId: "cfg-{$key}",
        priority: $priority,
        weight: $weight,
        health: HealthState::ONLINE,
        circuit: CircuitState::CLOSED,
        metrics: new AcquirerMetrics(
            acquirerKey: $key,
            attempts: $attempts,
            successes: $successes,
            failures: $attempts - $successes,
            latencySumMs: $latencySumMs,
        ),
        maxTps: $maxTps,
        currentTps: $currentTps,
    );
}

function keysOf(array $ordered): array
{
    return array_map(static fn (AcquirerCandidate $c): string => $c->key, $ordered);
}

$context = fn () => RoutingContext::make('pix', 10000);

/* ── Prioridade ─────────────────────────────────────────────── */

it('orders by priority, lowest value first', function () use ($context) {
    $ordered = (new PriorityStrategy())->order([
        candidate('c', priority: 30),
        candidate('a', priority: 10),
        candidate('b', priority: 20),
    ], $context());

    expect(keysOf($ordered))->toBe(['a', 'b', 'c']);
});

it('breaks priority ties by weight then alphabetically', function () use ($context) {
    $ordered = (new PriorityStrategy())->order([
        candidate('zeta', priority: 10, weight: 1),
        candidate('alpha', priority: 10, weight: 1),
        candidate('heavy', priority: 10, weight: 5),
    ], $context());

    expect(keysOf($ordered))->toBe(['heavy', 'alpha', 'zeta']);
});

it('assigns descending scores so higher always means better', function () use ($context) {
    $ordered = (new PriorityStrategy())->order([
        candidate('a', priority: 10),
        candidate('b', priority: 20),
    ], $context());

    expect($ordered[0]->score)->toBeGreaterThan($ordered[1]->score);
});

/* ── Round robin ────────────────────────────────────────────── */

it('rotates the primary acquirer on each call', function () use ($context) {
    $strategy = new RoundRobinStrategy(new ArrayRotationCursor());
    $pool = fn () => [candidate('a', priority: 1), candidate('b', priority: 2), candidate('c', priority: 3)];

    $primaries = [];
    for ($i = 0; $i < 6; $i++) {
        $primaries[] = keysOf($strategy->order($pool(), $context()))[0];
    }

    expect($primaries)->toBe(['a', 'b', 'c', 'a', 'b', 'c']);
});

it('keeps every acquirer in the fallback chain while rotating', function () use ($context) {
    $strategy = new RoundRobinStrategy(new ArrayRotationCursor());
    $strategy->order([candidate('a'), candidate('b'), candidate('c')], $context());

    $ordered = $strategy->order([candidate('a'), candidate('b'), candidate('c')], $context());

    expect(keysOf($ordered))->toHaveCount(3)
        ->and(keysOf($ordered))->toContain('a', 'b', 'c');
});

it('keeps separate rotation cursors per establishment', function () {
    $strategy = new RoundRobinStrategy(new ArrayRotationCursor());
    $pool = fn () => [candidate('a', priority: 1), candidate('b', priority: 2)];

    $accountA = RoutingContext::make('pix', 1000, accountId: 'acc-1');
    $accountB = RoutingContext::make('pix', 1000, accountId: 'acc-2');

    // Advancing one seller's cursor must not shift the other's.
    $strategy->order($pool(), $accountA);

    expect(keysOf($strategy->order($pool(), $accountB))[0])->toBe('a');
});

/* ── Weighted round robin ───────────────────────────────────── */

it('distributes traffic proportionally to weight', function () use ($context) {
    $strategy = new WeightedRoundRobinStrategy(new ArrayRotationCursor());
    $pool = fn () => [candidate('heavy', weight: 3), candidate('light', weight: 1)];

    $counts = ['heavy' => 0, 'light' => 0];
    for ($i = 0; $i < 40; $i++) {
        $counts[keysOf($strategy->order($pool(), $context()))[0]]++;
    }

    // 3:1 over 40 requests → 30 vs 10.
    expect($counts['heavy'])->toBe(30)
        ->and($counts['light'])->toBe(10);
});

it('interleaves weighted slots instead of clustering them', function () use ($context) {
    $strategy = new WeightedRoundRobinStrategy(new ArrayRotationCursor());
    $pool = fn () => [candidate('a', weight: 3), candidate('b', weight: 1)];

    $sequence = [];
    for ($i = 0; $i < 4; $i++) {
        $sequence[] = keysOf($strategy->order($pool(), $context()))[0];
    }

    // Smooth expansion: b appears in the middle, not after three straight a's.
    expect($sequence)->toBe(['a', 'b', 'a', 'a']);
});

it('never starves an acquirer with weight 1 among heavy ones', function () use ($context) {
    $strategy = new WeightedRoundRobinStrategy(new ArrayRotationCursor());
    $pool = fn () => [candidate('big', weight: 20), candidate('small', weight: 1)];

    $sawSmall = false;
    for ($i = 0; $i < 21; $i++) {
        if (keysOf($strategy->order($pool(), $context()))[0] === 'small') {
            $sawSmall = true;
        }
    }

    expect($sawSmall)->toBeTrue();
});

/* ── Menor latência ─────────────────────────────────────────── */

it('prefers the acquirer with the lowest average latency', function () use ($context) {
    $ordered = (new LeastLatencyStrategy(minSamples: 10))->order([
        candidate('slow', attempts: 100, successes: 100, latencySumMs: 500_000),  // 5000 ms
        candidate('fast', attempts: 100, successes: 100, latencySumMs: 80_000),   // 800 ms
    ], $context());

    expect(keysOf($ordered)[0])->toBe('fast');
});

it('ranks acquirers without enough samples below measured ones', function () use ($context) {
    $ordered = (new LeastLatencyStrategy(minSamples: 50))->order([
        candidate('unproven', priority: 1, attempts: 2, successes: 2, latencySumMs: 100),
        candidate('measured', priority: 90, attempts: 200, successes: 200, latencySumMs: 600_000),
    ], $context());

    // Two samples at 50 ms is not evidence; the measured acquirer wins.
    expect(keysOf($ordered)[0])->toBe('measured');
});

/* ── Melhor taxa de aprovação ───────────────────────────────── */

it('prefers the acquirer approving the most transactions', function () use ($context) {
    $ordered = (new BestApprovalRateStrategy(minSamples: 10))->order([
        candidate('poor', attempts: 1000, successes: 700),
        candidate('good', attempts: 1000, successes: 970),
    ], $context());

    expect(keysOf($ordered)[0])->toBe('good');
});

it('does not let a tiny perfect sample beat a large strong one', function () use ($context) {
    $ordered = (new BestApprovalRateStrategy(minSamples: 20))->order([
        candidate('lucky', attempts: 3, successes: 3),        // 100% raw
        candidate('proven', attempts: 2000, successes: 1940), // 97% over real volume
    ], $context());

    expect(keysOf($ordered)[0])->toBe('proven');
});

it('treats an acquirer with no history neutrally', function () use ($context) {
    $ordered = (new BestApprovalRateStrategy())->order([
        candidate('fresh'),
        candidate('failing', attempts: 500, successes: 50),
    ], $context());

    expect(keysOf($ordered)[0])->toBe('fresh');
});

/* ── Menor utilização ───────────────────────────────────────── */

it('prefers the acquirer furthest from its TPS ceiling', function () use ($context) {
    $ordered = (new LeastUtilizationStrategy())->order([
        candidate('busy', maxTps: 100, currentTps: 90.0),
        candidate('idle', maxTps: 100, currentTps: 10.0),
    ], $context());

    expect(keysOf($ordered)[0])->toBe('idle');
});

it('treats an acquirer without a TPS ceiling as idle', function () use ($context) {
    $ordered = (new LeastUtilizationStrategy())->order([
        candidate('capped', maxTps: 100, currentTps: 50.0),
        candidate('uncapped', maxTps: null, currentTps: 500.0),
    ], $context());

    expect(keysOf($ordered)[0])->toBe('uncapped');
});

it('records a human readable reason for the choice', function () use ($context) {
    $ordered = (new LeastUtilizationStrategy())->order([
        candidate('a', maxTps: 200, currentTps: 50.0),
    ], $context());

    expect($ordered[0]->scoreReason)->toContain('25.0%')
        ->and($ordered[0]->scoreReason)->toContain('200');
});
