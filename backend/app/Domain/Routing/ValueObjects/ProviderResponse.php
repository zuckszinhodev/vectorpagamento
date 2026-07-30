<?php

declare(strict_types=1);

namespace App\Domain\Routing\ValueObjects;

use App\Domain\Routing\Enums\FailureReason;

/**
 * Normalised result of one acquirer call.
 *
 * `data` keeps the shape the rest of the application already expects
 * (pix_qr_code, pix_copy_paste, transaction_id, ...), so the new engine is a
 * drop-in for the legacy return arrays.
 */
final class ProviderResponse
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|string|null $raw
     */
    private function __construct(
        public readonly bool $successful,
        public readonly string $acquirerKey,
        public readonly array $data = [],
        public readonly ?string $transactionId = null,
        public readonly ?string $status = null,
        public readonly ?string $errorMessage = null,
        public readonly ?FailureReason $failureReason = null,
        public readonly mixed $raw = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function success(
        string $acquirerKey,
        array $data = [],
        ?string $transactionId = null,
        ?string $status = null,
        mixed $raw = null,
    ): self {
        return new self(
            successful: true,
            acquirerKey: $acquirerKey,
            data: $data,
            transactionId: $transactionId,
            status: $status,
            raw: $raw,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function failure(
        string $acquirerKey,
        string $errorMessage,
        FailureReason $reason = FailureReason::PROVIDER_ERROR,
        array $data = [],
        mixed $raw = null,
    ): self {
        return new self(
            successful: false,
            acquirerKey: $acquirerKey,
            data: $data,
            errorMessage: $errorMessage,
            failureReason: $reason,
            raw: $raw,
        );
    }

    public function allowsFailover(): bool
    {
        return !$this->successful && ($this->failureReason?->allowsFailover() ?? true);
    }

    public function isRetryable(): bool
    {
        return !$this->successful && ($this->failureReason?->isRetryable() ?? false);
    }

    /**
     * Legacy-compatible array, so call sites that still expect the old
     * ['success' => bool, ...] shape keep working unchanged.
     *
     * @return array<string, mixed>
     */
    public function toLegacyArray(): array
    {
        if ($this->successful) {
            return array_merge($this->data, [
                'success' => true,
                'acquirer' => $this->acquirerKey,
                'transaction_id' => $this->transactionId ?? ($this->data['transaction_id'] ?? null),
                'status' => $this->status ?? ($this->data['status'] ?? null),
            ]);
        }

        return array_merge($this->data, [
            'success' => false,
            'acquirer' => $this->acquirerKey,
            'error' => $this->errorMessage,
            'error_code' => $this->failureReason?->value,
            'raw_response' => $this->raw,
        ]);
    }
}
