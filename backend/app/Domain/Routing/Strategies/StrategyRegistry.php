<?php

declare(strict_types=1);

namespace App\Domain\Routing\Strategies;

use App\Domain\Routing\Contracts\RoutingStrategyInterface;
use App\Domain\Routing\Enums\RoutingStrategy;

/**
 * Resolves a {@see RoutingStrategy} to its implementation.
 *
 * Strategies are injected, so a new one is added by registering a class in the
 * service provider — the router itself never changes.
 */
final class StrategyRegistry
{
    /** @var array<string, RoutingStrategyInterface> */
    private array $strategies = [];

    /**
     * @param iterable<RoutingStrategyInterface> $strategies
     */
    public function __construct(iterable $strategies = [])
    {
        foreach ($strategies as $strategy) {
            $this->register($strategy);
        }
    }

    public function register(RoutingStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->identifier()->value] = $strategy;
    }

    public function has(RoutingStrategy $strategy): bool
    {
        return isset($this->strategies[$strategy->value]);
    }

    /**
     * Falls back to priority ordering when a strategy is configured but not
     * registered, so a bad config value can never leave a transaction unrouted.
     */
    public function get(RoutingStrategy $strategy): RoutingStrategyInterface
    {
        return $this->strategies[$strategy->value]
            ?? $this->strategies[RoutingStrategy::PRIORITY->value]
            ?? new PriorityStrategy();
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function available(): array
    {
        return array_values(array_map(
            static fn (RoutingStrategyInterface $s): array => [
                'value' => $s->identifier()->value,
                'label' => $s->identifier()->label(),
            ],
            $this->strategies
        ));
    }
}
