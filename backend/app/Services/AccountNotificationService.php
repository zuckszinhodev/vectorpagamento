<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Charge;
use App\Models\KycSubmission;
use App\Models\Notification;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Helper central para criar notificações internas (o sino do painel).
 *
 * Cada método traduz um evento de domínio em uma linha na tabela `notifications`,
 * consumida pelo sino do DashboardLayout via /api/v1/notifications.
 *
 * Tipos disponíveis (enum): sale | system | warning | info.
 *
 * Todos os métodos tratam erro em try/catch — falha ao criar a notificação
 * NUNCA deve derrubar o fluxo principal (pagamento, saque, KYC, etc.).
 */
class AccountNotificationService
{
    /** Cobrança aprovada / venda recebida. */
    public function chargePaid(Charge $charge): void
    {
        $amount = $this->formatMoney((int) ($charge->amount ?? 0), (string) ($charge->currency ?? 'BRL'));
        $customer = $charge->customer['name'] ?? $charge->customer['email'] ?? 'Cliente';

        $this->push($charge->account_id, null, [
            'type'    => 'sale',
            'title'   => 'Nova venda aprovada',
            'message' => "{$amount} de {$customer}",
            'data'    => [
                'event'     => 'charge.paid',
                'charge_id' => $charge->id,
            ],
        ]);
    }

    /** Cobrança estornada. */
    public function chargeRefunded(Charge $charge): void
    {
        $amount = $this->formatMoney((int) ($charge->amount ?? 0), (string) ($charge->currency ?? 'BRL'));

        $this->push($charge->account_id, null, [
            'type'    => 'warning',
            'title'   => 'Cobrança estornada',
            'message' => "{$amount} foi devolvida ao cliente.",
            'data'    => [
                'event'     => 'charge.refunded',
                'charge_id' => $charge->id,
            ],
        ]);
    }

    /** Chargeback recebido. */
    public function chargebackReceived(Charge $charge): void
    {
        $amount = $this->formatMoney((int) ($charge->amount ?? 0), (string) ($charge->currency ?? 'BRL'));

        $this->push($charge->account_id, null, [
            'type'    => 'warning',
            'title'   => 'Chargeback recebido',
            'message' => "Disputa aberta para uma cobrança de {$amount}. Responda o quanto antes.",
            'data'    => [
                'event'     => 'charge.chargeback',
                'charge_id' => $charge->id,
            ],
        ]);
    }

    /** Saque solicitado (informativo). */
    public function withdrawalRequested(Withdrawal $withdrawal): void
    {
        $amount = $this->formatMoneyMinor((int) ($withdrawal->amount * 100));

        $this->push($withdrawal->account_id, $withdrawal->user_id, [
            'type'    => 'info',
            'title'   => 'Saque solicitado',
            'message' => "{$amount} — estamos processando seu saque.",
            'data'    => [
                'event'         => 'withdrawal.requested',
                'withdrawal_id' => $withdrawal->id,
            ],
        ]);
    }

    /** Saque concluído. */
    public function withdrawalCompleted(Withdrawal $withdrawal): void
    {
        $amount = $this->formatMoneyMinor((int) ($withdrawal->net_amount * 100));

        $this->push($withdrawal->account_id, $withdrawal->user_id, [
            'type'    => 'sale',
            'title'   => 'Saque concluído',
            'message' => "{$amount} caiu no destino.",
            'data'    => [
                'event'         => 'withdrawal.completed',
                'withdrawal_id' => $withdrawal->id,
            ],
        ]);
    }

    /** Withdrawal failures are handled by administrators without a user notification. */
    public function withdrawalFailed(Withdrawal $withdrawal, string $reason): void
    {
        // Keep this entry point silent for all callers, including notification previews.
    }

    /** KYC pendente logo após o cadastro. */
    public function kycPending(User $user): void
    {
        $this->push($user->account_id, $user->id, [
            'type'    => 'warning',
            'title'   => 'Complete sua verificação',
            'message' => 'Envie seus documentos para liberar vendas e saques na sua conta.',
            'data'    => [
                'event' => 'kyc.pending',
                'url'   => '/kyc',
            ],
        ]);
    }

    /** KYC aprovado. */
    public function kycApproved(User $user): void
    {
        $this->push($user->account_id, $user->id, [
            'type'    => 'system',
            'title'   => 'Verificação aprovada',
            'message' => 'Sua conta está verificada. Saques liberados.',
            'data'    => ['event' => 'kyc.approved'],
        ]);
    }

    /** KYC rejeitado. */
    public function kycRejected(User $user, KycSubmission $submission, ?string $reason = null): void
    {
        $this->push($user->account_id, $user->id, [
            'type'    => 'warning',
            'title'   => 'Verificação não aprovada',
            'message' => $reason
                ? "Motivo: {$reason}. Corrija e envie novamente."
                : 'Corrija os pontos indicados e envie novamente.',
            'data'    => [
                'event'         => 'kyc.rejected',
                'submission_id' => $submission->id,
                'reason'        => $reason,
            ],
        ]);
    }

    /** Admin respondeu chamado de suporte. */
    public function supportReplied(SupportTicket $ticket): void
    {
        $this->push($ticket->account_id, $ticket->user_id, [
            'type'    => 'info',
            'title'   => 'Nova resposta do suporte',
            'message' => "Chamado \"{$ticket->subject}\" recebeu uma resposta.",
            'data'    => [
                'event'     => 'support.replied',
                'ticket_id' => $ticket->id,
            ],
        ]);
    }

    // ─── Internos ───────────────────────────────────────────

    /**
     * @param  array{type:string,title:string,message:string,data?:array}  $payload
     */
    private function push(?string $accountId, ?string $userId, array $payload): void
    {
        if (!$accountId) {
            return;
        }

        try {
            Notification::create([
                'id'         => (string) Str::uuid(),
                'account_id' => $accountId,
                'user_id'    => $userId,
                'type'       => $payload['type'],
                'title'      => $payload['title'],
                'message'    => $payload['message'],
                'data'       => $payload['data'] ?? [],
                'read'       => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AccountNotificationService: falha ao criar notificação', [
                'account_id' => $accountId,
                'title'      => $payload['title'] ?? null,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function formatMoney(int $cents, string $currency = 'BRL'): string
    {
        $symbol = $currency === 'BRL' ? 'R$ ' : ($currency . ' ');
        return $symbol . number_format($cents / 100, 2, ',', '.');
    }

    private function formatMoneyMinor(int $cents): string
    {
        return $this->formatMoney($cents, 'BRL');
    }
}
