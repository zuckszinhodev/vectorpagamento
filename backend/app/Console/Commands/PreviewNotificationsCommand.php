<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Charge;
use App\Models\KycSubmission;
use App\Models\Notification;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\AccountNotificationService;
use Illuminate\Console\Command;

/**
 * Popula o sino de notificações de um usuário com um exemplo de cada tipo,
 * para poder visualizar como cada notificação aparece.
 *
 * Uso:  php artisan notifications:preview usuario@email.com
 *       php artisan notifications:preview usuario@email.com --clear   (limpa antes)
 */
class PreviewNotificationsCommand extends Command
{
    protected $signature = 'notifications:preview
                            {email : Email do usuário destino}
                            {--clear : Apaga notificações antigas antes de criar as de preview}';

    protected $description = 'Cria uma notificação de cada tipo (sino) para o usuário informado';

    public function handle(AccountNotificationService $svc): int
    {
        $email = (string) $this->argument('email');
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("Usuário não encontrado: {$email}");
            return self::FAILURE;
        }

        if (!$user->account_id) {
            $this->error("Usuário {$email} não tem account_id.");
            return self::FAILURE;
        }

        $this->info("Enviando preview para {$email} (account_id={$user->account_id})");

        if ($this->option('clear')) {
            $deleted = Notification::where('account_id', $user->account_id)->delete();
            $this->line("  Removidas {$deleted} notificações antigas");
        }

        // Cria stubs em memória (não persistidos) para alimentar os métodos.
        $charge = new Charge([
            'id'             => 'preview-charge',
            'account_id'     => $user->account_id,
            'amount'         => 9799,      // R$ 97,99 em centavos
            'currency'       => 'BRL',
            'payment_method' => 'pix',
            'status'         => 'approved',
            'customer'       => ['name' => 'Fabrício Silva', 'email' => 'fabricio@exemplo.com'],
        ]);
        $charge->id = 'preview-charge';

        $withdrawal = new Withdrawal([
            'id'          => 'preview-withdrawal',
            'account_id'  => $user->account_id,
            'user_id'     => $user->id,
            'amount'      => 500.00,   // R$ 500,00 (não centavos — modelo usa decimal)
            'net_amount'  => 498.00,
            'status'      => 'completed',
            'method'      => 'pix',
        ]);
        $withdrawal->id = 'preview-withdrawal';

        $submission = new KycSubmission(['id' => 'preview-kyc', 'user_id' => $user->id]);
        $submission->id = 'preview-kyc';

        $ticket = new SupportTicket([
            'id'         => 'preview-ticket',
            'account_id' => $user->account_id,
            'user_id'    => $user->id,
            'subject'    => 'Dúvida sobre cobrança pendente',
        ]);
        $ticket->id = 'preview-ticket';

        $svc->chargePaid($charge);            $this->line('  ✓ Nova venda aprovada');
        $svc->chargeRefunded($charge);        $this->line('  ✓ Cobrança estornada');
        $svc->chargebackReceived($charge);    $this->line('  ✓ Chargeback recebido');
        $svc->withdrawalRequested($withdrawal); $this->line('  ✓ Saque solicitado');
        $svc->withdrawalCompleted($withdrawal); $this->line('  ✓ Saque concluído');
        $svc->withdrawalFailed($withdrawal, 'Chave PIX inválida'); $this->line('  ✓ Saque falhou');
        $svc->kycApproved($user);             $this->line('  ✓ KYC aprovado');
        $svc->kycRejected($user, $submission, 'Documento ilegível'); $this->line('  ✓ KYC rejeitado');
        $svc->supportReplied($ticket);        $this->line('  ✓ Suporte respondeu');

        $count = Notification::where('account_id', $user->account_id)->count();
        $this->info("Pronto. Total de notificações na conta agora: {$count}");
        $this->info("Faça login como {$email} para ver no sino do painel.");

        return self::SUCCESS;
    }
}
