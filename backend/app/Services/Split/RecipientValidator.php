<?php

declare(strict_types=1);

namespace App\Services\Split;

use App\Models\Account;
use App\Models\Affiliate;

/**
 * Valida que cada recebedor de um split existe de fato dentro do sistema.
 *
 * Sem isto, um `recipient_id` digitado errado faz o dinheiro ser creditado
 * numa carteira fantasma — criada na hora para um UUID que não pertence a
 * ninguém. Toda regra de split e todo split inline da API passa por aqui
 * antes de qualquer valor se mover.
 */
final class RecipientValidator
{
    /** Contas nestes status não podem receber split. */
    private const BLOCKED_ACCOUNT_STATUSES = ['blocked', 'suspended'];

    /**
     * Valida a lista de recebedores de uma regra.
     *
     * @param  array<int, array<string, mixed>>  $recipients
     * @return array<string, string>  Erros indexados por "recipients.{i}.recipient_id"
     */
    public function validate(array $recipients): array
    {
        $errors = [];
        $seen = [];

        foreach ($recipients as $index => $recipient) {
            $key = "recipients.{$index}.recipient_id";
            $recipientId = (string) ($recipient['recipient_id'] ?? '');
            $recipientType = (string) ($recipient['recipient_type'] ?? 'account');

            if ($recipientId === '') {
                $errors[$key] = 'Informe o destinatário.';
                continue;
            }

            // Duplicata na mesma regra: o mesmo destinatário receberia duas parcelas
            // e a soma dos percentuais deixaria de refletir o que o usuário vê.
            $dedupeKey = "{$recipientType}:{$recipientId}";
            if (isset($seen[$dedupeKey])) {
                $errors[$key] = 'Este destinatário já foi adicionado nesta regra.';
                continue;
            }
            $seen[$dedupeKey] = true;

            $error = match ($recipientType) {
                'account' => $this->validateAccount($recipientId),
                'affiliate' => $this->validateAffiliate($recipientId),
                default => "Tipo de destinatário inválido: {$recipientType}.",
            };

            if ($error !== null) {
                $errors[$key] = $error;
            }
        }

        return $errors;
    }

    /**
     * Resolve o destinatário para a conta que efetivamente recebe o dinheiro.
     *
     * Afiliados não têm carteira própria — o crédito vai para a conta vinculada
     * ao afiliado. Retorna null quando não há destino válido, e nesse caso o
     * split deve falhar em vez de sumir silenciosamente.
     */
    public function resolvePayoutAccountId(string $recipientId, string $recipientType): ?string
    {
        if ($recipientType === 'account') {
            return Account::whereKey($recipientId)->exists() ? $recipientId : null;
        }

        if ($recipientType === 'affiliate') {
            $payoutAccountId = Affiliate::find($recipientId)?->payoutAccountId();

            return $payoutAccountId && Account::whereKey($payoutAccountId)->exists()
                ? $payoutAccountId
                : null;
        }

        return null;
    }

    private function validateAccount(string $accountId): ?string
    {
        $account = Account::find($accountId);

        if (!$account) {
            return 'Conta não encontrada no sistema.';
        }

        if (in_array($account->status, self::BLOCKED_ACCOUNT_STATUSES, true)) {
            return "Conta {$account->status} não pode receber split.";
        }

        return null;
    }

    private function validateAffiliate(string $affiliateId): ?string
    {
        $affiliate = Affiliate::find($affiliateId);

        if (!$affiliate) {
            return 'Afiliado não encontrado no sistema.';
        }

        $payoutAccountId = $affiliate->payoutAccountId();

        if (!$payoutAccountId) {
            return 'Afiliado não possui conta vinculada para receber o valor.';
        }

        return $this->validateAccount($payoutAccountId);
    }
}
