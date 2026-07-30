<?php

declare(strict_types=1);

namespace App\Infrastructure\Acquirers\Concerns;

/**
 * Lets the routing engine override an acquirer's HTTP timeouts at call time.
 *
 * Timeouts used to be constants inside each acquirer class, so an operator
 * could not tighten a slow acquirer without a deploy. The routing profile now
 * supplies them per acquirer; the values baked into the class remain the
 * default whenever no override is set.
 */
trait ConfigurableTimeouts
{
    private ?int $overrideTimeoutMs = null;
    private ?int $overrideConnectTimeoutMs = null;

    /**
     * @param int|null $timeoutMs        total request budget; null keeps the default
     * @param int|null $connectTimeoutMs connection budget; null keeps the default
     */
    public function setTimeouts(?int $timeoutMs, ?int $connectTimeoutMs = null): static
    {
        $this->overrideTimeoutMs = $timeoutMs !== null && $timeoutMs > 0 ? $timeoutMs : null;
        $this->overrideConnectTimeoutMs = $connectTimeoutMs !== null && $connectTimeoutMs > 0 ? $connectTimeoutMs : null;

        return $this;
    }

    /**
     * Total timeout in whole seconds for an HTTP call.
     *
     * Laravel's PendingRequest::timeout() is typed `int`, so a float would
     * raise a TypeError at call time. Sub-second budgets round UP: truncating
     * 500 ms to 0 would mean "no timeout at all" in Guzzle — the opposite of
     * what a tight budget is asking for.
     */
    protected function httpTimeout(int $default): int
    {
        if ($this->overrideTimeoutMs === null) {
            return max(1, $default);
        }

        return max(1, (int) ceil($this->overrideTimeoutMs / 1000));
    }

    /**
     * Connection timeout in whole seconds.
     *
     * Never allowed to exceed the total timeout — a connect budget larger than
     * the request budget would make the total meaningless.
     */
    protected function httpConnectTimeout(int $default): int
    {
        if ($this->overrideConnectTimeoutMs === null) {
            return max(1, $default);
        }

        $connect = max(1, (int) ceil($this->overrideConnectTimeoutMs / 1000));

        return $this->overrideTimeoutMs !== null
            ? min($connect, $this->httpTimeout($default))
            : $connect;
    }
}
