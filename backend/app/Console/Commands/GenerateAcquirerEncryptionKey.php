<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Generates the AES-256 key used to encrypt acquirer credentials.
 */
class GenerateAcquirerEncryptionKey extends Command
{
    protected $signature = 'acquirer:generate-key
                            {--show : Apenas exibe a chave, sem gravar no .env}';

    protected $description = 'Gera a chave AES-256 (ACQUIRER_ENCRYPTION_KEY) para o cofre de credenciais das adquirentes';

    public function handle(): int
    {
        $key = base64_encode(random_bytes(32));

        if ($this->option('show')) {
            $this->line($key);

            return self::SUCCESS;
        }

        $envPath = base_path('.env');

        if (!file_exists($envPath)) {
            $this->error('.env não encontrado. Use --show e configure a chave manualmente.');

            return self::FAILURE;
        }

        $contents = (string) file_get_contents($envPath);

        if (preg_match('/^ACQUIRER_ENCRYPTION_KEY=(.*)$/m', $contents, $matches)) {
            if (trim($matches[1]) !== '') {
                $this->warn('ACQUIRER_ENCRYPTION_KEY já está definida.');
                $this->line('');
                $this->line('Trocar a chave sem rotação torna as credenciais existentes ilegíveis.');
                $this->line('Para rotacionar com segurança:');
                $this->line('  1. Mova a chave atual para ACQUIRER_ENCRYPTION_KEYS_PREVIOUS={"1":"<chave atual>"}');
                $this->line('  2. Defina a nova chave em ACQUIRER_ENCRYPTION_KEY e incremente ACQUIRER_ENCRYPTION_KEY_VERSION');
                $this->line('  3. Rode: php artisan acquirer:migrate-credentials --rewrap');
                $this->line('');
                $this->line('Nova chave sugerida: ' . $key);

                return self::FAILURE;
            }

            $contents = preg_replace('/^ACQUIRER_ENCRYPTION_KEY=.*$/m', "ACQUIRER_ENCRYPTION_KEY={$key}", $contents) ?? $contents;
        } else {
            $contents = rtrim($contents, "\n") . "\n\n# Cofre de credenciais das adquirentes (AES-256-GCM)\nACQUIRER_ENCRYPTION_KEY={$key}\nACQUIRER_ENCRYPTION_KEY_VERSION=1\n";
        }

        file_put_contents($envPath, $contents);

        $this->info('ACQUIRER_ENCRYPTION_KEY gravada no .env.');
        $this->warn('Faça backup desta chave: sem ela as credenciais gravadas não podem ser recuperadas.');

        return self::SUCCESS;
    }
}
