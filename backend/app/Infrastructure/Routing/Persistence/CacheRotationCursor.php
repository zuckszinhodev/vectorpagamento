<?php

declare(strict_types=1);

namespace App\Infrastructure\Routing\Persistence;

use App\Domain\Routing\Contracts\RotationCursorInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

/**
 * Rotation cursor backed by the cache store.
 *
 * On Redis, increment() is a single atomic INCR. On the file/array stores used
 * in development it is not strictly atomic, which is acceptable there: the
 * worst case is two requests picking the same acquirer.
 */
final class CacheRotationCursor implements RotationCursorInterface
{
    /** Cursors are pure rotation state; a day of TTL is plenty. */
    private const TTL_SECONDS = 86400;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $prefix = 'acq_routing:cursor',
    ) {
    }

    public function next(string $key): int
    {
        $cacheKey = $this->cacheKey($key);

        try {
            $value = $this->cache->increment($cacheKey);

            // Some stores return false/null when the key does not exist yet.
            if (!is_int($value)) {
                $this->cache->put($cacheKey, 1, self::TTL_SECONDS);

                return 0;
            }

            // Keep the counter well inside integer range over long uptimes.
            if ($value >= PHP_INT_MAX - 1000) {
                $this->cache->put($cacheKey, 0, self::TTL_SECONDS);

                return 0;
            }

            return $value - 1;
        } catch (\Throwable $e) {
            Log::warning('Falha ao avançar cursor de rotação; usando posição 0', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    public function peek(string $key): int
    {
        try {
            return max(0, (int) $this->cache->get($this->cacheKey($key), 0));
        } catch (\Throwable) {
            return 0;
        }
    }

    public function reset(string $key): void
    {
        try {
            $this->cache->forget($this->cacheKey($key));
        } catch (\Throwable $e) {
            Log::warning('Falha ao resetar cursor de rotação', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    private function cacheKey(string $key): string
    {
        return "{$this->prefix}:{$key}";
    }
}
