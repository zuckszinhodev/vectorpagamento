<?php

declare(strict_types=1);

namespace App\Domain\Routing\ValueObjects;

use App\Domain\Routing\Enums\RoutingStrategy;

/**
 * The ordered acquirer chain the router produced for one transaction.
 *
 * The first element is the primary; the rest are the fallback chain, already
 * filtered so that no unavailable acquirer can appear.
 */
final class RoutingDecision
{
    /**
     * @param array<int, AcquirerCandidate> $chain ordered, primary first
     * @param array<int, array<string, mixed>> $excluded acquirers ruled out, with the reason
     */
    public function __construct(
        public readonly array $chain,
        public readonly RoutingStrategy $strategy,
        public readonly string $reason,
        public readonly array $excluded = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->chain === [];
    }

    public function primary(): ?AcquirerCandidate
    {
        return $this->chain[0] ?? null;
    }

    /**
     * @return array<int, AcquirerCandidate>
     */
    public function fallbacks(): array
    {
        return array_slice($this->chain, 1);
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_map(static fn (AcquirerCandidate $c): string => $c->key, $this->chain);
    }

    /**
     * Limit the chain to at most N attempts, as configured in the policy.
     */
    public function limitedTo(int $maxAttempts): self
    {
        if ($maxAttempts <= 0 || count($this->chain) <= $maxAttempts) {
            return $this;
        }

        $dropped = array_slice($this->chain, $maxAttempts);
        $excluded = $this->excluded;

        foreach ($dropped as $candidate) {
            $excluded[] = [
                'acquirer' => $candidate->key,
                'reason' => 'max_attempts_reached',
                'detail' => "Limite de {$maxAttempts} tentativas por transação",
            ];
        }

        return new self(
            chain: array_slice($this->chain, 0, $maxAttempts),
            strategy: $this->strategy,
            reason: $this->reason,
            excluded: $excluded,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogArray(): array
    {
        return [
            'strategy' => $this->strategy->value,
            'reason' => $this->reason,
            'chain' => array_map(static fn (AcquirerCandidate $c): array => $c->toLogArray(), $this->chain),
            'excluded' => $this->excluded,
        ];
    }
}
