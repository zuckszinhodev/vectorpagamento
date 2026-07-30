<?php

declare(strict_types=1);

namespace App\Domain\Routing\ValueObjects;

/**
 * Outcome of a connectivity probe against an acquirer — used both by the
 * scheduled health check and by the panel's "testar conexão" button.
 */
final class ProbeResult
{
    /**
     * @param array<string, mixed> $details
     */
    private function __construct(
        public readonly bool $healthy,
        public readonly int $latencyMs,
        public readonly ?string $error = null,
        public readonly array $details = [],
        public readonly bool $skipped = false,
    ) {
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function healthy(int $latencyMs, array $details = []): self
    {
        return new self(healthy: true, latencyMs: $latencyMs, details: $details);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function unhealthy(int $latencyMs, string $error, array $details = []): self
    {
        return new self(healthy: false, latencyMs: $latencyMs, error: $error, details: $details);
    }

    /**
     * The acquirer offers no probe we can run without side effects. Reported
     * separately so "not probed" is never rendered as "offline".
     */
    public static function skipped(string $reason): self
    {
        return new self(healthy: true, latencyMs: 0, error: $reason, skipped: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'healthy' => $this->healthy,
            'skipped' => $this->skipped,
            'latency_ms' => $this->latencyMs,
            'error' => $this->error,
            'details' => $this->details,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
