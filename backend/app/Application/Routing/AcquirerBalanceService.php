<?php

declare(strict_types=1);

namespace App\Application\Routing;

use App\Domain\Routing\Contracts\AcquirerCatalogInterface;
use App\Domain\Routing\Contracts\BalanceInquiryInterface;
use App\Domain\Routing\Contracts\ProviderRegistryInterface;
use App\Domain\Routing\Enums\PaymentMethod;
use App\Domain\Routing\ValueObjects\AcquirerBalance;
use App\Domain\Routing\ValueObjects\RoutingContext;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

/**
 * Reads how much money each acquirer currently holds.
 *
 * Used by the payout path to split a withdrawal across nominal balances, and
 * by the admin panel to show operators where their funds actually are.
 *
 * Balance endpoints are slow and rate-limited, so results are cached briefly.
 * The payout path can bypass the cache, because paying out against a stale
 * balance is how you get a rejected transfer mid-split.
 */
final class AcquirerBalanceService
{
    private const CACHE_PREFIX = 'acq_routing:balance';
    private const CACHE_TTL = 30;

    public function __construct(
        private readonly ProviderRegistryInterface $providers,
        private readonly AcquirerCatalogInterface $catalog,
        private readonly CacheRepository $cache,
    ) {
    }

    /**
     * Balance for one acquirer.
     */
    public function forAcquirer(string $acquirerKey, ?string $accountId = null, bool $fresh = false): AcquirerBalance
    {
        $cacheKey = sprintf('%s:%s:%s', self::CACHE_PREFIX, $acquirerKey, $accountId ?? 'global');

        if (!$fresh) {
            $cached = $this->cache->get($cacheKey);

            if ($cached instanceof AcquirerBalance) {
                return $cached;
            }
        }

        $balance = $this->read($acquirerKey, $accountId);

        // Only successful reads are cached: caching a transient failure would
        // keep an acquirer out of payout splits for the whole TTL.
        if ($balance->available) {
            $this->cache->put($cacheKey, $balance, self::CACHE_TTL);
        }

        return $balance;
    }

    /**
     * Balances for every acquirer that can send money out.
     *
     * @return array<string, AcquirerBalance>
     */
    public function forPayoutAcquirers(?string $accountId = null, bool $fresh = false): array
    {
        $context = RoutingContext::make(PaymentMethod::PIX_OUT, 0, $accountId);
        $balances = [];

        foreach ($this->catalog->candidatesFor($context) as $candidate) {
            $balances[$candidate->key] = $this->forAcquirer($candidate->key, $accountId, $fresh);
        }

        return $balances;
    }

    /**
     * Acquirers holding funds, richest first — the order a split should consume
     * them in, so the fewest possible parts are created.
     *
     * @return array<int, AcquirerBalance>
     */
    public function splittableBalances(?string $accountId = null): array
    {
        $balances = array_values(array_filter(
            $this->forPayoutAcquirers($accountId, fresh: true),
            static fn (AcquirerBalance $b): bool => $b->available && $b->availableCents > 0
        ));

        usort($balances, static fn (AcquirerBalance $a, AcquirerBalance $b): int => $b->availableCents <=> $a->availableCents);

        return $balances;
    }

    public function totalAvailableCents(?string $accountId = null): int
    {
        return array_sum(array_map(
            static fn (AcquirerBalance $b): int => $b->available ? $b->availableCents : 0,
            $this->forPayoutAcquirers($accountId)
        ));
    }

    public function flush(?string $acquirerKey = null): void
    {
        try {
            if ($acquirerKey === null) {
                // No tag support on every store; the short TTL bounds staleness.
                return;
            }

            $this->cache->forget(sprintf('%s:%s:global', self::CACHE_PREFIX, $acquirerKey));
        } catch (\Throwable) {
            // Stale for at most CACHE_TTL seconds.
        }
    }

    private function read(string $acquirerKey, ?string $accountId): AcquirerBalance
    {
        $provider = $this->providers->resolve($acquirerKey, $accountId);

        if ($provider === null) {
            return AcquirerBalance::unavailable($acquirerKey, 'Módulo indisponível ou credenciais inválidas.');
        }

        if (!$provider instanceof BalanceInquiryInterface) {
            return AcquirerBalance::notSupported($acquirerKey);
        }

        try {
            return $provider->balance();
        } catch (\Throwable $e) {
            Log::warning('Falha ao consultar saldo da adquirente', [
                'acquirer' => $acquirerKey,
                'error' => $e->getMessage(),
            ]);

            return AcquirerBalance::unavailable($acquirerKey, $e->getMessage());
        }
    }
}
