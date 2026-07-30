<?php

declare(strict_types=1);

namespace App\Infrastructure\Routing\Resilience;

use App\Domain\Routing\Contracts\RateLimiterInterface;
use App\Models\AcquirerEvent;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * TPS and concurrency limiter backed by the cache store.
 *
 * TPS uses a sliding window over two one-second buckets rather than a hard
 * per-second reset: a fixed window lets 2× the limit through at a boundary
 * (all of second N's budget at 0.99s plus all of N+1's at 1.00s).
 */
final class CacheRateLimiter implements RateLimiterInterface
{
    private const PREFIX = 'acq_routing:rate';

    /** Concurrency slots self-expire so a crashed worker cannot leak one forever. */
    private const CONCURRENCY_TTL = 120;

    public function __construct(
        private readonly CacheRepository $cache,
    ) {
    }

    public function tryAcquire(string $acquirerKey, ?int $maxTps, ?int $maxConcurrent = null): bool
    {
        if ($maxConcurrent !== null && $maxConcurrent > 0) {
            $inFlight = $this->currentConcurrency($acquirerKey);

            if ($inFlight >= $maxConcurrent) {
                $this->recordThrottle($acquirerKey, 'max_concurrent', $inFlight, $maxConcurrent);

                return false;
            }
        }

        if ($maxTps !== null && $maxTps > 0) {
            if ($this->estimatedRate($acquirerKey) >= $maxTps) {
                $this->recordThrottle($acquirerKey, 'max_tps', (int) $this->estimatedRate($acquirerKey), $maxTps);

                return false;
            }

            $this->incrementBucket($acquirerKey);
        }

        if ($maxConcurrent !== null && $maxConcurrent > 0) {
            $this->incrementConcurrency($acquirerKey);
        }

        return true;
    }

    public function release(string $acquirerKey): void
    {
        $key = $this->concurrencyKey($acquirerKey);

        try {
            $value = $this->cache->decrement($key);

            if (is_int($value) && $value < 0) {
                $this->cache->put($key, 0, self::CONCURRENCY_TTL);
            }
        } catch (\Throwable $e) {
            Log::warning('Falha ao liberar slot de concorrência', [
                'acquirer' => $acquirerKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function currentTps(string $acquirerKey): float
    {
        return round($this->estimatedRate($acquirerKey), 4);
    }

    public function currentConcurrency(string $acquirerKey): int
    {
        try {
            return max(0, (int) $this->cache->get($this->concurrencyKey($acquirerKey), 0));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Sliding window: the current second's count plus the fraction of the
     * previous second still inside the trailing 1s window.
     */
    private function estimatedRate(string $acquirerKey): float
    {
        $now = microtime(true);
        $currentSecond = (int) $now;
        $elapsedFraction = $now - $currentSecond;

        try {
            $current = (int) $this->cache->get($this->bucketKey($acquirerKey, $currentSecond), 0);
            $previous = (int) $this->cache->get($this->bucketKey($acquirerKey, $currentSecond - 1), 0);
        } catch (\Throwable) {
            return 0.0;
        }

        return $current + ($previous * (1 - $elapsedFraction));
    }

    private function incrementBucket(string $acquirerKey): void
    {
        $key = $this->bucketKey($acquirerKey, (int) microtime(true));

        try {
            $value = $this->cache->increment($key);

            if (!is_int($value)) {
                // 3s TTL: long enough for the sliding window to read it back.
                $this->cache->put($key, 1, 3);
            }
        } catch (\Throwable $e) {
            Log::warning('Falha ao contabilizar TPS da adquirente', [
                'acquirer' => $acquirerKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function incrementConcurrency(string $acquirerKey): void
    {
        $key = $this->concurrencyKey($acquirerKey);

        try {
            $value = $this->cache->increment($key);

            if (!is_int($value)) {
                $this->cache->put($key, 1, self::CONCURRENCY_TTL);
            }
        } catch (\Throwable $e) {
            Log::warning('Falha ao reservar slot de concorrência', [
                'acquirer' => $acquirerKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function recordThrottle(string $acquirerKey, string $limitType, int $observed, int $limit): void
    {
        Log::info('Adquirente saturada, roteando para a próxima', [
            'acquirer' => $acquirerKey,
            'limit_type' => $limitType,
            'observed' => $observed,
            'limit' => $limit,
        ]);

        // Throttling is frequent under load — record at most one event per
        // acquirer per minute so the audit trail stays readable.
        $throttleKey = sprintf('%s:%s:event_seen:%s', self::PREFIX, $acquirerKey, $limitType);

        try {
            if ($this->cache->get($throttleKey) !== null) {
                return;
            }

            $this->cache->put($throttleKey, 1, 60);

            AcquirerEvent::query()->create([
                'id' => (string) Str::uuid(),
                'acquirer_key' => $acquirerKey,
                'type' => AcquirerEvent::TYPE_TPS_THROTTLED,
                'severity' => 'warning',
                'message' => sprintf('Limite de %s atingido (%d/%d)', $limitType, $observed, $limit),
                'context' => ['limit_type' => $limitType, 'observed' => $observed, 'limit' => $limit],
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Falha ao registrar evento de throttling', ['error' => $e->getMessage()]);
        }
    }

    private function bucketKey(string $acquirerKey, int $second): string
    {
        return sprintf('%s:tps:%s:%d', self::PREFIX, $acquirerKey, $second);
    }

    private function concurrencyKey(string $acquirerKey): string
    {
        return sprintf('%s:conc:%s', self::PREFIX, $acquirerKey);
    }
}
