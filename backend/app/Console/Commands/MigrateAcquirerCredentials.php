<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Routing\Contracts\AcquirerCredentialRepositoryInterface;
use App\Models\AcquirerConfig;
use App\Models\AcquirerCredential;
use Illuminate\Console\Command;

/**
 * Moves credentials from the legacy `acquirer_configs.credentials` blob into
 * the per-field encrypted vault, and re-wraps rows under a new key version.
 */
class MigrateAcquirerCredentials extends Command
{
    protected $signature = 'acquirer:migrate-credentials
                            {--rewrap : Recriptografa credenciais já migradas com a chave atual}
                            {--prune-legacy : Apaga o blob legado depois de migrar (irreversível)}
                            {--dry-run : Mostra o que seria feito sem gravar}';

    protected $description = 'Migra as credenciais das adquirentes para o cofre AES-256-GCM por campo';

    public function handle(AcquirerCredentialRepositoryInterface $credentials): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rewrap = (bool) $this->option('rewrap');
        $pruneLegacy = (bool) $this->option('prune-legacy');

        $configs = AcquirerConfig::withTrashed()->get();

        if ($configs->isEmpty()) {
            $this->info('Nenhuma adquirente cadastrada.');

            return self::SUCCESS;
        }

        $migrated = 0;
        $rewrapped = 0;
        $skipped = 0;

        foreach ($configs as $config) {
            $hasVaultRows = AcquirerCredential::query()
                ->where('acquirer_config_id', $config->id)
                ->exists();

            if ($hasVaultRows && !$rewrap) {
                $skipped++;
                continue;
            }

            // decrypted() transparently reads either the vault or the legacy
            // blob, which is exactly what both migration and re-wrap need.
            $plain = $credentials->decrypted($config);

            if ($plain === []) {
                $this->line("  <fg=gray>·</> {$config->acquirer_key}: sem credenciais");
                $skipped++;
                continue;
            }

            $action = $hasVaultRows ? 'recriptografar' : 'migrar';
            $fields = implode(', ', array_keys($plain));

            if ($dryRun) {
                $this->line("  <fg=yellow>→</> {$config->acquirer_key}: {$action} [{$fields}]");
                continue;
            }

            $written = $credentials->store($config, $plain);

            if ($hasVaultRows) {
                $rewrapped++;
                $this->line("  <fg=green>✓</> {$config->acquirer_key}: recriptografado [" . implode(', ', $written) . ']');
            } else {
                $migrated++;
                $this->line("  <fg=green>✓</> {$config->acquirer_key}: migrado [" . implode(', ', $written) . ']');
            }

            if ($pruneLegacy && !$hasVaultRows) {
                // Written straight to the attribute to bypass the encrypting
                // mutator — we want the column empty, not an encrypted null.
                $config->forceFill(['credentials' => null])->saveQuietly();
                $this->line("    <fg=gray>blob legado removido</>");
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s | migradas: %d | recriptografadas: %d | ignoradas: %d',
            $dryRun ? 'Simulação' : 'Concluído',
            $migrated,
            $rewrapped,
            $skipped
        ));

        if (!$dryRun && !$pruneLegacy && $migrated > 0) {
            $this->warn('O blob legado foi mantido. Depois de validar em produção, rode com --prune-legacy.');
        }

        return self::SUCCESS;
    }
}
