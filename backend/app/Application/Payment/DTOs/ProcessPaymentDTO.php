<?php

declare(strict_types=1);

namespace Application\Payment\DTOs;

final class ProcessPaymentDTO
{
    public function __construct(
        public readonly string $chargeId,
        public readonly ?array $cardData = null,
        public readonly ?int $installments = 1
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            chargeId: $data['charge_id'],
            cardData: $data['card'] ?? null,
            installments: $data['installments'] ?? 1
        );
    }
}
