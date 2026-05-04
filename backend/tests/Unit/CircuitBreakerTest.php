<?php

declare(strict_types=1);

use App\Services\CircuitBreaker;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('starts in closed state and allows requests', function () {
    $cb = new CircuitBreaker('test:service', 3, 60);

    expect($cb->isAvailable())->toBeTrue();
});

it('stays closed below failure threshold', function () {
    $cb = new CircuitBreaker('test:service', 3, 60);

    $cb->recordFailure();
    $cb->recordFailure();

    expect($cb->isAvailable())->toBeTrue();
});

it('opens after reaching failure threshold', function () {
    $cb = new CircuitBreaker('test:service', 3, 60);

    $cb->recordFailure();
    $cb->recordFailure();
    $cb->recordFailure();

    expect($cb->isAvailable())->toBeFalse();
});

it('resets failures on success', function () {
    $cb = new CircuitBreaker('test:service', 3, 60);

    $cb->recordFailure();
    $cb->recordFailure();
    $cb->recordSuccess();

    // Should be back to 0 failures
    $cb->recordFailure();
    $cb->recordFailure();

    expect($cb->isAvailable())->toBeTrue();
});

it('transitions to half-open after cooldown', function () {
    $cb = new CircuitBreaker('test:service', 3, 1); // 1 second cooldown

    $cb->recordFailure();
    $cb->recordFailure();
    $cb->recordFailure();

    expect($cb->isAvailable())->toBeFalse();

    // Simulate cooldown elapsed
    Cache::put('circuit_breaker:test:service:opened_at', time() - 2, now()->addHours(1));

    expect($cb->isAvailable())->toBeTrue(); // half-open probe allowed
});

it('re-opens on failure in half-open state', function () {
    $cb = new CircuitBreaker('test:service', 3, 1);

    // Open the circuit
    $cb->recordFailure();
    $cb->recordFailure();
    $cb->recordFailure();

    // Simulate cooldown elapsed so it goes half-open
    Cache::put('circuit_breaker:test:service:opened_at', time() - 2, now()->addHours(1));

    $cb->isAvailable(); // triggers transition to half-open

    // Probe fails
    $cb->recordFailure();

    expect($cb->isAvailable())->toBeFalse();
});

it('closes on success in half-open state', function () {
    $cb = new CircuitBreaker('test:service', 3, 1);

    // Open circuit
    $cb->recordFailure();
    $cb->recordFailure();
    $cb->recordFailure();

    // Simulate cooldown
    Cache::put('circuit_breaker:test:service:opened_at', time() - 2, now()->addHours(1));

    $cb->isAvailable(); // half-open

    $cb->recordSuccess();

    expect($cb->isAvailable())->toBeTrue();
});

it('call() executes action and records success', function () {
    $cb = new CircuitBreaker('test:service', 3, 60);

    $result = $cb->call(fn () => 'ok');

    expect($result)->toBe('ok');
});

it('call() records failure and rethrows exception', function () {
    $cb = new CircuitBreaker('test:service', 3, 60);

    expect(fn () => $cb->call(fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');
});

it('call() throws RuntimeException when circuit is open and no fallback', function () {
    $cb = new CircuitBreaker('test:service', 3, 60);

    $cb->recordFailure();
    $cb->recordFailure();
    $cb->recordFailure();

    expect(fn () => $cb->call(fn () => 'never'))
        ->toThrow(RuntimeException::class);
});

it('call() uses fallback when circuit is open', function () {
    $cb = new CircuitBreaker('test:service', 3, 60);

    $cb->recordFailure();
    $cb->recordFailure();
    $cb->recordFailure();

    $result = $cb->call(fn () => 'never', fn () => 'fallback');

    expect($result)->toBe('fallback');
});

it('uses separate state per service name', function () {
    $cb1 = new CircuitBreaker('service:a', 2, 60);
    $cb2 = new CircuitBreaker('service:b', 2, 60);

    $cb1->recordFailure();
    $cb1->recordFailure();

    expect($cb1->isAvailable())->toBeFalse();
    expect($cb2->isAvailable())->toBeTrue();
});
