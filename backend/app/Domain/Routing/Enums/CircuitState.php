<?php

declare(strict_types=1);

namespace App\Domain\Routing\Enums;

/**
 * Circuit breaker states.
 *
 *   CLOSED    → traffic flows, consecutive failures are counted
 *   OPEN      → acquirer removed from routing, fast-fail until cooldown elapses
 *   HALF_OPEN → limited probe traffic; success closes it, failure re-opens it
 */
enum CircuitState: string
{
    case CLOSED = 'closed';
    case OPEN = 'open';
    case HALF_OPEN = 'half_open';

    public static function tryNormalize(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::CLOSED;
    }

    public function allowsTraffic(): bool
    {
        return $this !== self::OPEN;
    }

    public function label(): string
    {
        return match ($this) {
            self::CLOSED => 'Fechado',
            self::OPEN => 'Aberto',
            self::HALF_OPEN => 'Meio Aberto',
        };
    }
}
