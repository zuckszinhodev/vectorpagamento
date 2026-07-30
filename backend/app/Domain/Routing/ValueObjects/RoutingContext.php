<?php

declare(strict_types=1);

namespace App\Domain\Routing\ValueObjects;

use App\Domain\Routing\Enums\PaymentMethod;
use Illuminate\Support\Str;

/**
 * Everything the router needs to know about one transaction.
 *
 * Immutable and free of Eloquent models, so strategies stay unit-testable
 * without touching the database.
 */
final class RoutingContext
{
    /**
     * @param array<string, mixed> $metadata
     * @param array<int, string> $restrictToAcquirers acquirers this account is locked to
     */
    public function __construct(
        public readonly PaymentMethod $method,
        public readonly int $amountCents,
        public readonly ?string $accountId = null,
        public readonly ?string $chargeId = null,
        public readonly string $correlationId = '',
        public readonly array $restrictToAcquirers = [],
        public readonly ?string $forcedAcquirer = null,
        public readonly bool $exclusive = false,
        public readonly int $installments = 1,
        public readonly string $currency = 'BRL',
        public readonly array $metadata = [],
    ) {
    }

    public static function make(
        string|PaymentMethod $method,
        int $amountCents,
        ?string $accountId = null,
        ?string $chargeId = null,
    ): self {
        return new self(
            method: $method instanceof PaymentMethod ? $method : PaymentMethod::normalize($method),
            amountCents: $amountCents,
            accountId: $accountId,
            chargeId: $chargeId,
            correlationId: (string) Str::uuid(),
        );
    }

    public function withCorrelationId(string $correlationId): self
    {
        return $this->copyWith(['correlationId' => $correlationId]);
    }

    /**
     * @param array<int, string> $acquirers
     */
    public function restrictedTo(array $acquirers, bool $exclusive = false): self
    {
        return $this->copyWith([
            'restrictToAcquirers' => array_values(array_unique(array_map('strtolower', $acquirers))),
            'exclusive' => $exclusive,
        ]);
    }

    public function forcedTo(?string $acquirerKey): self
    {
        return $this->copyWith(['forcedAcquirer' => $acquirerKey ? strtolower($acquirerKey) : null]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return $this->copyWith(['metadata' => array_merge($this->metadata, $metadata)]);
    }

    public function withInstallments(int $installments): self
    {
        return $this->copyWith(['installments' => max(1, $installments)]);
    }

    public function isRestricted(): bool
    {
        return $this->restrictToAcquirers !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogArray(): array
    {
        return [
            'correlation_id' => $this->correlationId,
            'charge_id' => $this->chargeId,
            'account_id' => $this->accountId,
            'payment_method' => $this->method->value,
            'amount_cents' => $this->amountCents,
            'installments' => $this->installments,
            'restricted_to' => $this->restrictToAcquirers,
            'forced_acquirer' => $this->forcedAcquirer,
            'exclusive' => $this->exclusive,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function copyWith(array $overrides): self
    {
        return new self(
            method: $overrides['method'] ?? $this->method,
            amountCents: $overrides['amountCents'] ?? $this->amountCents,
            accountId: $overrides['accountId'] ?? $this->accountId,
            chargeId: $overrides['chargeId'] ?? $this->chargeId,
            correlationId: $overrides['correlationId'] ?? $this->correlationId,
            restrictToAcquirers: $overrides['restrictToAcquirers'] ?? $this->restrictToAcquirers,
            forcedAcquirer: array_key_exists('forcedAcquirer', $overrides) ? $overrides['forcedAcquirer'] : $this->forcedAcquirer,
            exclusive: $overrides['exclusive'] ?? $this->exclusive,
            installments: $overrides['installments'] ?? $this->installments,
            currency: $overrides['currency'] ?? $this->currency,
            metadata: $overrides['metadata'] ?? $this->metadata,
        );
    }
}
