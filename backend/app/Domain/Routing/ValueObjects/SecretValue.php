<?php

declare(strict_types=1);

namespace App\Domain\Routing\ValueObjects;

/**
 * An encrypted credential together with the metadata needed to store it.
 *
 * Deliberately carries no plaintext: once built, the only way back is through
 * the vault. __toString / var_dump exposure is neutered on purpose.
 */
final class SecretValue implements \JsonSerializable
{
    public function __construct(
        public readonly string $cipherText,
        public readonly string $algorithm,
        public readonly int $keyVersion,
        public readonly string $maskHint,
        public readonly int $valueLength,
        public readonly string $fingerprint,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'cipher_text' => $this->cipherText,
            'cipher_alg' => $this->algorithm,
            'key_version' => $this->keyVersion,
            'mask_hint' => $this->maskHint,
            'value_length' => $this->valueLength,
            'fingerprint' => $this->fingerprint,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'masked' => $this->maskHint,
            'length' => $this->valueLength,
            'key_version' => $this->keyVersion,
        ];
    }

    public function __toString(): string
    {
        return '[encrypted]';
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['cipher_text' => '[redacted]', 'masked' => $this->maskHint];
    }
}
