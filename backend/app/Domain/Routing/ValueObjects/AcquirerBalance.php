<?php

declare(strict_types=1);

namespace App\Domain\Routing\ValueObjects;

/**
 * Available funds held at one acquirer, always normalised to cents.
 *
 * Normalisation matters: the integrated PSPs disagree on units — Enki reports
 * cents as an int, BluPay and Witetec report reais as a float. Mixing them up
 * is a factor-of-100 payout error, so the conversion happens once, here.
 */
final class AcquirerBalance
{
    /**
     * @param array<string, mixed> $raw
     */
    private function __construct(
        public readonly string $acquirerKey,
        public readonly bool $available,
        public readonly int $availableCents = 0,
        public readonly int $blockedCents = 0,
        public readonly ?string $error = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function of(
        string $acquirerKey,
        int $availableCents,
        int $blockedCents = 0,
        array $raw = [],
    ): self {
        return new self(
            acquirerKey: $acquirerKey,
            available: true,
            availableCents: max(0, $availableCents),
            blockedCents: max(0, $blockedCents),
            raw: $raw,
        );
    }

    public static function unavailable(string $acquirerKey, string $error): self
    {
        return new self($acquirerKey, false, error: $error);
    }

    /**
     * The acquirer does not expose a balance endpoint. Distinct from an error:
     * it must not be rendered as "R$ 0,00 available".
     */
    public static function notSupported(string $acquirerKey): self
    {
        return new self($acquirerKey, false, error: 'Adquirente não expõe consulta de saldo.');
    }

    public function canCover(int $amountCents): bool
    {
        return $this->available && $this->availableCents >= $amountCents;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'acquirer_key' => $this->acquirerKey,
            'available' => $this->available,
            'available_cents' => $this->availableCents,
            'blocked_cents' => $this->blockedCents,
            'error' => $this->error,
        ];
    }
}
