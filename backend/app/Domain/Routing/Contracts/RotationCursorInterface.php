<?php

declare(strict_types=1);

namespace App\Domain\Routing\Contracts;

/**
 * Atomic counter backing the round-robin family of strategies.
 *
 * Must be atomic across workers: two concurrent transactions have to receive
 * two different cursor values, otherwise round-robin degenerates into
 * "everyone picks the same acquirer". The previous implementation stored this
 * in a `system_settings` row with a read-modify-write, which both raced and
 * put a database write on the payment hot path.
 */
interface RotationCursorInterface
{
    /**
     * Atomically return the current value and advance it by one.
     */
    public function next(string $key): int;

    /**
     * Read without advancing (used by the admin preview endpoint).
     */
    public function peek(string $key): int;

    public function reset(string $key): void;
}
