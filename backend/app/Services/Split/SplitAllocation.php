<?php

declare(strict_types=1);

namespace App\Services\Split;

/**
 * Uma parcela calculada do split, destinada a um recebedor.
 *
 * Valores sempre em centavos. `gross` é o que o recebedor ganha antes da taxa
 * que ele mesmo absorve; `net` é o que efetivamente cai na carteira dele.
 */
final class SplitAllocation
{
    public function __construct(
        public readonly string $recipientId,
        public readonly string $recipientType,
        public readonly string $type,
        public readonly ?float $value,
        public readonly int $gross,
        public readonly int $fee,
        public readonly int $net,
        public readonly bool $isProducer = false,
        public readonly string $description = '',
    ) {}

    public function toArray(): array
    {
        return [
            'recipient_id' => $this->recipientId,
            'recipient_type' => $this->recipientType,
            'type' => $this->type,
            'value' => $this->value,
            'gross_amount' => $this->gross,
            'fee_amount' => $this->fee,
            'net_amount' => $this->net,
            'is_producer' => $this->isProducer,
            'description' => $this->description,
        ];
    }
}
