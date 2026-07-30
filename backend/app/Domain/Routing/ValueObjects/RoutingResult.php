<?php

declare(strict_types=1);

namespace App\Domain\Routing\ValueObjects;

/**
 * Final outcome of a routed transaction.
 *
 * The customer-facing part is deliberately thin: {@see self::toLegacyArray()}
 * exposes only the winning acquirer's response, never the intermediate
 * attempts. The full chain lives in {@see self::$attempts} for operators.
 */
final class RoutingResult
{
    /**
     * @param array<int, RoutingAttempt> $attempts
     */
    private function __construct(
        public readonly bool $successful,
        public readonly string $correlationId,
        public readonly ?ProviderResponse $response,
        public readonly array $attempts,
        public readonly int $totalDurationMs,
        public readonly ?RoutingDecision $decision = null,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * @param array<int, RoutingAttempt> $attempts
     */
    public static function success(
        string $correlationId,
        ProviderResponse $response,
        array $attempts,
        int $totalDurationMs,
        RoutingDecision $decision,
    ): self {
        return new self(true, $correlationId, $response, $attempts, $totalDurationMs, $decision);
    }

    /**
     * @param array<int, RoutingAttempt> $attempts
     */
    public static function failure(
        string $correlationId,
        string $error,
        array $attempts,
        int $totalDurationMs,
        ?RoutingDecision $decision = null,
        ?ProviderResponse $lastResponse = null,
    ): self {
        return new self(false, $correlationId, $lastResponse, $attempts, $totalDurationMs, $decision, $error);
    }

    public function selectedAcquirer(): ?string
    {
        return $this->response?->acquirerKey;
    }

    /**
     * Whether the winning acquirer was a fallback rather than the primary.
     */
    public function usedFallback(): bool
    {
        return $this->successful && count($this->attempts) > 1;
    }

    public function attemptCount(): int
    {
        return count($this->attempts);
    }

    /**
     * Response for the API caller — the final answer only.
     *
     * @return array<string, mixed>
     */
    public function toLegacyArray(): array
    {
        if ($this->successful && $this->response !== null) {
            return $this->response->toLegacyArray();
        }

        return [
            'success' => false,
            'error' => $this->error ?? 'Não foi possível processar o pagamento.',
            'correlation_id' => $this->correlationId,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function attemptsAsArray(): array
    {
        return array_map(static fn (RoutingAttempt $a): array => $a->toArray(), $this->attempts);
    }
}
