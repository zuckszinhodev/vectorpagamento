<?php

declare(strict_types=1);

use App\Domain\Routing\Contracts\CircuitBreakerInterface;
use App\Domain\Routing\Contracts\HealthStateRepositoryInterface;
use App\Domain\Routing\Enums\CircuitState;
use App\Domain\Routing\Enums\FailureReason;
use App\Models\AcquirerConfig;
use App\Models\AcquirerEvent;
use App\Models\AcquirerRoutingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeAcquirerWithProfile(string $key, array $profile = []): AcquirerConfig
{
    $config = AcquirerConfig::create([
        'id' => (string) Str::uuid(),
        'acquirer_key' => $key,
        'display_name' => ucfirst($key),
        'type' => 'pix',
        'enabled' => true,
        'supported_methods' => ['pix'],
        'priority' => 10,
    ]);

    AcquirerRoutingProfile::create(array_merge([
        'id' => (string) Str::uuid(),
        'acquirer_config_id' => $config->id,
        'acquirer_key' => $key,
        'routing_enabled' => true,
        'priority' => 10,
        'weight' => 1,
        'cb_failure_threshold' => 3,
        'cb_cooldown_seconds' => 60,
        'cb_half_open_max_calls' => 1,
        'cb_success_threshold' => 2,
    ], $profile));

    return $config;
}

beforeEach(function () {
    Cache::flush();
    $this->breaker = app(CircuitBreakerInterface::class);
    $this->health = app(HealthStateRepositoryInterface::class);
});

it('starts closed and allows traffic', function () {
    makeAcquirerWithProfile('acme');

    expect($this->breaker->allows('acme'))->toBeTrue()
        ->and($this->breaker->state('acme'))->toBe(CircuitState::CLOSED);
});

it('stays closed below the configured failure threshold', function () {
    makeAcquirerWithProfile('acme');

    $this->breaker->recordFailure('acme', FailureReason::TIMEOUT);
    $this->breaker->recordFailure('acme', FailureReason::TIMEOUT);

    expect($this->breaker->allows('acme'))->toBeTrue();
});

it('opens once the failure threshold is reached', function () {
    makeAcquirerWithProfile('acme');

    for ($i = 0; $i < 3; $i++) {
        $this->breaker->recordFailure('acme', FailureReason::TIMEOUT);
    }

    expect($this->breaker->state('acme'))->toBe(CircuitState::OPEN)
        ->and($this->breaker->allows('acme'))->toBeFalse();
});

it('uses each acquirer\'s own threshold from its routing profile', function () {
    makeAcquirerWithProfile('sensitive', ['cb_failure_threshold' => 1]);
    makeAcquirerWithProfile('tolerant', ['cb_failure_threshold' => 10]);

    $this->breaker->recordFailure('sensitive', FailureReason::PROVIDER_ERROR);
    $this->breaker->recordFailure('tolerant', FailureReason::PROVIDER_ERROR);

    expect($this->breaker->allows('sensitive'))->toBeFalse()
        ->and($this->breaker->allows('tolerant'))->toBeTrue();
});

it('does not trip on a declined transaction', function () {
    makeAcquirerWithProfile('acme');

    // A declined card is a healthy acquirer answering correctly.
    for ($i = 0; $i < 10; $i++) {
        $this->breaker->recordFailure('acme', FailureReason::DECLINED);
    }

    expect($this->breaker->state('acme'))->toBe(CircuitState::CLOSED);
});

it('does not trip on a validation error', function () {
    makeAcquirerWithProfile('acme');

    for ($i = 0; $i < 10; $i++) {
        $this->breaker->recordFailure('acme', FailureReason::VALIDATION_ERROR);
    }

    expect($this->breaker->state('acme'))->toBe(CircuitState::CLOSED);
});

it('resets the failure count after a success', function () {
    makeAcquirerWithProfile('acme');

    $this->breaker->recordFailure('acme', FailureReason::TIMEOUT);
    $this->breaker->recordFailure('acme', FailureReason::TIMEOUT);
    $this->breaker->recordSuccess('acme', 120);
    $this->breaker->recordFailure('acme', FailureReason::TIMEOUT);

    expect($this->breaker->allows('acme'))->toBeTrue();
});

it('moves to half-open after the cooldown elapses', function () {
    makeAcquirerWithProfile('acme', ['cb_cooldown_seconds' => 30]);

    for ($i = 0; $i < 3; $i++) {
        $this->breaker->recordFailure('acme', FailureReason::TIMEOUT);
    }
    expect($this->breaker->allows('acme'))->toBeFalse();

    // Simulate the cooldown having elapsed.
    Cache::put('acq_routing:cb:acme:opened_at', time() - 31, 3600);

    expect($this->breaker->allows('acme'))->toBeTrue()
        ->and($this->breaker->state('acme'))->toBe(CircuitState::HALF_OPEN);
});

it('admits only the configured number of probes while half-open', function () {
    makeAcquirerWithProfile('acme', ['cb_cooldown_seconds' => 30, 'cb_half_open_max_calls' => 1]);

    for ($i = 0; $i < 3; $i++) {
        $this->breaker->recordFailure('acme', FailureReason::TIMEOUT);
    }
    Cache::put('acq_routing:cb:acme:opened_at', time() - 31, 3600);

    // First call becomes the probe; concurrent callers must be turned away.
    expect($this->breaker->allows('acme'))->toBeTrue()
        ->and($this->breaker->allows('acme'))->toBeFalse();
});

it('re-opens immediately when a half-open probe fails', function () {
    makeAcquirerWithProfile('acme', ['cb_cooldown_seconds' => 30]);

    for ($i = 0; $i < 3; $i++) {
        $this->breaker->recordFailure('acme', FailureReason::TIMEOUT);
    }
    Cache::put('acq_routing:cb:acme:opened_at', time() - 31, 3600);
    $this->breaker->allows('acme');

    $this->breaker->recordFailure('acme', FailureReason::TIMEOUT);

    expect($this->breaker->state('acme'))->toBe(CircuitState::OPEN);
});

it('closes after enough consecutive successes in half-open', function () {
    makeAcquirerWithProfile('acme', ['cb_cooldown_seconds' => 30, 'cb_success_threshold' => 2]);

    for ($i = 0; $i < 3; $i++) {
        $this->breaker->recordFailure('acme', FailureReason::TIMEOUT);
    }
    Cache::put('acq_routing:cb:acme:opened_at', time() - 31, 3600);
    $this->breaker->allows('acme');

    $this->breaker->recordSuccess('acme', 100);
    expect($this->breaker->state('acme'))->toBe(CircuitState::HALF_OPEN);

    $this->breaker->recordSuccess('acme', 100);
    expect($this->breaker->state('acme'))->toBe(CircuitState::CLOSED)
        ->and($this->breaker->allows('acme'))->toBeTrue();
});

it('persists the open state so it survives a cache flush', function () {
    makeAcquirerWithProfile('acme');

    for ($i = 0; $i < 3; $i++) {
        $this->breaker->recordFailure('acme', FailureReason::TIMEOUT);
    }

    // Simulate a Redis restart: the durable store must still know.
    Cache::flush();

    expect($this->breaker->state('acme'))->toBe(CircuitState::OPEN);
});

it('records an audit event when the circuit opens', function () {
    makeAcquirerWithProfile('acme');

    for ($i = 0; $i < 3; $i++) {
        $this->breaker->recordFailure('acme', FailureReason::CONNECTION_REFUSED, 'connection refused');
    }

    expect(AcquirerEvent::where('acquirer_key', 'acme')->where('type', AcquirerEvent::TYPE_CIRCUIT_OPENED)->exists())
        ->toBeTrue();
});

it('can be reset manually from the admin panel', function () {
    makeAcquirerWithProfile('acme');

    for ($i = 0; $i < 3; $i++) {
        $this->breaker->recordFailure('acme', FailureReason::TIMEOUT);
    }
    expect($this->breaker->allows('acme'))->toBeFalse();

    $this->breaker->reset('acme', 'reset manual');

    expect($this->breaker->allows('acme'))->toBeTrue()
        ->and($this->breaker->state('acme'))->toBe(CircuitState::CLOSED);
});

it('gives an acquirer registered at runtime a working breaker', function () {
    // The previous implementation built breakers from a hard-coded list in the
    // constructor, so an acquirer added later had no protection at all.
    makeAcquirerWithProfile('brand_new_psp', ['cb_failure_threshold' => 2]);

    $this->breaker->recordFailure('brand_new_psp', FailureReason::TIMEOUT);
    $this->breaker->recordFailure('brand_new_psp', FailureReason::TIMEOUT);

    expect($this->breaker->allows('brand_new_psp'))->toBeFalse();
});
