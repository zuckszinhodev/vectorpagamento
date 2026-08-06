<?php

declare(strict_types=1);

namespace App\Services\Split;

/**
 * Cálculo puro da divisão de uma cobrança entre recebedores.
 *
 * Sem acesso a banco — recebe números, devolve números. É a única fonte de
 * verdade do rateio: criação de splits, simulação e preview usam este mesmo
 * cálculo, então nunca divergem.
 *
 * Invariante central: a soma dos `gross` de todas as parcelas (incluindo a do
 * produtor) é SEMPRE igual ao líquido da cobrança. Nunca se distribui mais do
 * que entrou, e nunca sobra valor sem dono.
 */
final class SplitCalculator
{
    /** Produtor absorve a taxa sozinho: recebedores calculam sobre o bruto. */
    public const FEE_PRODUCER = 'producer';

    /** Taxa rateada: recebedores calculam sobre o líquido. */
    public const FEE_PROPORTIONAL = 'proportional';

    /**
     * @param  array<int, array<string, mixed>>  $recipients  Itens {recipient_id, recipient_type, type, value, description}
     * @param  int  $grossAmount  Valor cheio da cobrança, em centavos.
     * @param  int  $feeAmount  Taxa do gateway, em centavos.
     * @param  string  $producerAccountId  Conta dona da cobrança — recebe o restante.
     * @return SplitResult
     */
    public function calculate(
        array $recipients,
        int $grossAmount,
        int $feeAmount,
        string $producerAccountId,
        string $feeMode = self::FEE_PRODUCER,
    ): SplitResult {
        $grossAmount = max(0, $grossAmount);
        $feeAmount = max(0, min($feeAmount, $grossAmount));

        // Só existe isto para dividir. Ninguém pode receber mais que isso somado.
        $distributable = $grossAmount - $feeAmount;

        // Base de cálculo dos percentuais dos recebedores.
        $base = $feeMode === self::FEE_PROPORTIONAL ? $distributable : $grossAmount;

        $allocations = [];
        $remaining = $distributable;

        foreach ($recipients as $recipient) {
            $recipientId = (string) ($recipient['recipient_id'] ?? '');
            if ($recipientId === '') {
                continue;
            }

            $type = (string) ($recipient['type'] ?? 'percentage');
            $value = (float) ($recipient['value'] ?? 0);

            $amount = $type === 'percentage'
                ? (int) round($base * ($value / 100))
                : (int) round($value);

            // Trava dura: jamais exceder o que ainda resta para distribuir.
            $amount = max(0, min($amount, $remaining));

            if ($amount === 0) {
                continue;
            }

            $remaining -= $amount;

            $allocations[] = new SplitAllocation(
                recipientId: $recipientId,
                recipientType: (string) ($recipient['recipient_type'] ?? 'account'),
                type: $type,
                value: $value,
                gross: $amount,
                fee: 0,
                net: $amount,
                isProducer: false,
                description: (string) ($recipient['description'] ?? ''),
            );
        }

        // O que sobrou é do produtor. Ele também é quem absorve a taxa do gateway
        // (o `distributable` já vem descontado dela).
        $producerAllocation = new SplitAllocation(
            recipientId: $producerAccountId,
            recipientType: 'account',
            type: 'remainder',
            value: null,
            gross: $remaining,
            fee: $feeAmount,
            net: $remaining,
            isProducer: true,
            description: 'Produtor',
        );

        return new SplitResult(
            allocations: $allocations,
            producer: $producerAllocation,
            grossAmount: $grossAmount,
            feeAmount: $feeAmount,
            distributable: $distributable,
        );
    }

    /**
     * Soma dos percentuais declarados — usado para validar antes de salvar a regra.
     */
    public static function totalPercentage(array $recipients): float
    {
        $total = 0.0;
        foreach ($recipients as $recipient) {
            if (($recipient['type'] ?? 'percentage') === 'percentage') {
                $total += (float) ($recipient['value'] ?? 0);
            }
        }

        return round($total, 4);
    }

    /**
     * Soma dos valores fixos declarados, em centavos.
     */
    public static function totalFixed(array $recipients): int
    {
        $total = 0;
        foreach ($recipients as $recipient) {
            if (($recipient['type'] ?? 'percentage') !== 'percentage') {
                $total += (int) round((float) ($recipient['value'] ?? 0));
            }
        }

        return $total;
    }
}
