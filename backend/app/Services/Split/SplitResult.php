<?php

declare(strict_types=1);

namespace App\Services\Split;

/**
 * Resultado do rateio: as parcelas dos recebedores mais a parcela do produtor.
 */
final class SplitResult
{
    /**
     * @param  array<int, SplitAllocation>  $allocations  Parcelas dos recebedores configurados.
     * @param  SplitAllocation  $producer  Parcela do dono da cobrança (o que sobrou).
     */
    public function __construct(
        public readonly array $allocations,
        public readonly SplitAllocation $producer,
        public readonly int $grossAmount,
        public readonly int $feeAmount,
        public readonly int $distributable,
    ) {}

    /**
     * Todas as parcelas, incluindo a do produtor (mesmo que zerada).
     *
     * @return array<int, SplitAllocation>
     */
    public function all(): array
    {
        return [...$this->allocations, $this->producer];
    }

    /**
     * Parcelas que devem virar registros — descarta a do produtor se for zero.
     *
     * @return array<int, SplitAllocation>
     */
    public function persistable(): array
    {
        $all = $this->allocations;

        if ($this->producer->gross > 0 || empty($all)) {
            $all[] = $this->producer;
        }

        return $all;
    }

    /** Total distribuído aos recebedores, sem contar o produtor. */
    public function distributedToRecipients(): int
    {
        return array_sum(array_map(fn (SplitAllocation $a) => $a->gross, $this->allocations));
    }

    /** O que sobrou para o produtor. */
    public function producerAmount(): int
    {
        return $this->producer->gross;
    }

    /**
     * Confere a invariante: nada é criado nem perdido no rateio.
     */
    public function isBalanced(): bool
    {
        return $this->distributedToRecipients() + $this->producerAmount() === $this->distributable;
    }

    public function toArray(): array
    {
        return [
            'gross_amount' => $this->grossAmount,
            'fee_amount' => $this->feeAmount,
            'distributable_amount' => $this->distributable,
            'distributed_to_recipients' => $this->distributedToRecipients(),
            'producer_amount' => $this->producerAmount(),
            'balanced' => $this->isBalanced(),
            'recipients' => array_map(fn (SplitAllocation $a) => $a->toArray(), $this->allocations),
            'producer' => $this->producer->toArray(),
        ];
    }
}
