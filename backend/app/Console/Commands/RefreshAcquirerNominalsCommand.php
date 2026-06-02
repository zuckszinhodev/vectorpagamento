<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PixNominalNameService;
use Illuminate\Console\Command;

class RefreshAcquirerNominalsCommand extends Command
{
    protected $signature = 'acquirers:refresh-nominals {account_id? : Conta preferencial para gerar o PIX de verificacao}';

    protected $description = 'Sincroniza imediatamente as nominais de todas as adquirentes ativas';

    public function handle(PixNominalNameService $service): int
    {
        $preferredAccountId = $this->argument('account_id');

        $this->info('Sincronizando nominais das adquirentes ativas...');

        $results = $service->refreshAllAcquirerNominals(
            is_string($preferredAccountId) && $preferredAccountId !== '' ? $preferredAccountId : null
        );

        foreach ($results as $result) {
            $acquirer = (string) ($result['acquirer'] ?? 'desconhecida');

            if ($result['success'] ?? false) {
                $nominal = (string) ($result['nominal_name'] ?? '-');
                $changed = !empty($result['changed']) ? 'sim' : 'nao';
                $this->line("[OK] {$acquirer} => {$nominal} (alterou: {$changed})");
                continue;
            }

            $message = (string) ($result['message'] ?? 'Falha ao sincronizar nominal');
            $this->error("[ERRO] {$acquirer} => {$message}");
        }

        $successCount = count(array_filter($results, static fn (array $result): bool => (bool) ($result['success'] ?? false)));
        $this->newLine();
        $this->info("Concluido: {$successCount}/" . count($results) . ' nominais sincronizadas.');

        return self::SUCCESS;
    }
}
