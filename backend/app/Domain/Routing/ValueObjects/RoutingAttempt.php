<?php

declare(strict_types=1);

namespace App\Domain\Routing\ValueObjects;

use App\Domain\Routing\Enums\FailureReason;

/**
 * Record of one attempt against one acquirer, including its retries.
 *
 * The customer never sees these — they exist so operators can reconstruct
 * exactly why a transaction landed where it did.
 */
final class RoutingAttempt
{
    public function __construct(
        public readonly string $acquirerKey,
        public readonly int $position,
        public readonly bool $succeeded,
        public readonly int $durationMs,
        public readonly int $retries = 0,
        public readonly ?FailureReason $failureReason = null,
        public readonly ?string $error = null,
        public readonly string $selectionReason = '',
        public readonly ?string $providerTransactionId = null,
    ) {
    }

    public static function success(
        string $acquirerKey,
        int $position,
        int $durationMs,
        int $retries = 0,
        string $selectionReason = '',
        ?string $providerTransactionId = null,
    ): self {
        return new self(
            acquirerKey: $acquirerKey,
            position: $position,
            succeeded: true,
            durationMs: $durationMs,
            retries: $retries,
            selectionReason: $selectionReason,
            providerTransactionId: $providerTransactionId,
        );
    }

    public static function failure(
        string $acquirerKey,
        int $position,
        int $durationMs,
        FailureReason $reason,
        ?string $error = null,
        int $retries = 0,
        string $selectionReason = '',
    ): self {
        return new self(
            acquirerKey: $acquirerKey,
            position: $position,
            succeeded: false,
            durationMs: $durationMs,
            retries: $retries,
            failureReason: $reason,
            error: $error,
            selectionReason: $selectionReason,
        );
    }

    public function isTimeout(): bool
    {
        return $this->failureReason?->isTimeout() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'acquirer' => $this->acquirerKey,
            'position' => $this->position,
            'succeeded' => $this->succeeded,
            'duration_ms' => $this->durationMs,
            'retries' => $this->retries,
            'failure_reason' => $this->failureReason?->value,
            'failure_label' => $this->failureReason?->label(),
            'error' => $this->error,
            'selection_reason' => $this->selectionReason,
            'provider_transaction_id' => $this->providerTransactionId,
            'at' => now()->toIso8601String(),
        ];
    }
}
