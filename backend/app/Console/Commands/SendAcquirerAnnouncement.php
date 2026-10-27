<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\AcquirerAnnouncementMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Comunicado da nova adquirente Stone | Pagar.me.
 *
 * Sem opções só lista os destinatários. --test envia para um endereço;
 * --send dispara para todos os usuários ativos.
 */
class SendAcquirerAnnouncement extends Command
{
    protected $signature = 'mail:announce-acquirer
        {--test= : Envia só para este e-mail}
        {--send : Confirma o envio para todos os usuários ativos}';

    protected $description = 'Envia o comunicado da nova adquirente Stone | Pagar.me';

    // A Resend limita a taxa de envio; um intervalo curto entre e-mails evita 429.
    private const DELAY_MICROSECONDS = 600000;

    public function handle(): int
    {
        $testEmail = $this->option('test');

        if ($testEmail) {
            $user = User::where('email', $testEmail)->first() ?? new User(['email' => $testEmail, 'name' => 'Teste']);

            return $this->deliver([$user]) === 0 ? self::SUCCESS : self::FAILURE;
        }

        $recipients = User::query()
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->orderBy('created_at')
            ->get();

        $this->info("Destinatários: {$recipients->count()}");
        foreach ($recipients as $user) {
            $this->line("  - {$user->email}");
        }

        if (!$this->option('send')) {
            $this->warn('Nada enviado. Use --test=<email> para um teste ou --send para disparar para todos.');

            return self::SUCCESS;
        }

        return $this->deliver($recipients->all()) === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array<int, User> $users
     * @return int quantidade de falhas
     */
    private function deliver(array $users): int
    {
        $sent = 0;
        $failed = 0;

        foreach ($users as $index => $user) {
            if ($index > 0) {
                usleep(self::DELAY_MICROSECONDS);
            }

            try {
                Mail::to($user->email)->send(new AcquirerAnnouncementMail($user));
                $sent++;
                $this->info("  enviado: {$user->email}");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  falhou:  {$user->email} ({$e->getMessage()})");
                Log::warning('Acquirer announcement email failed', ['email' => $user->email, 'error' => $e->getMessage()]);
            }
        }

        $this->newLine();
        $this->info("Resumo: {$sent} enviado(s), {$failed} falha(s).");

        return $failed;
    }
}
