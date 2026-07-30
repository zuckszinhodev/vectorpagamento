<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AcquirerConfig;
use Illuminate\Console\Command;

/**
 * Re-saves an acquirer's credentials through the model mutator so they are
 * (re-)encrypted with the current APP_KEY, and enables the acquirer.
 *
 * Usage: php artisan acquirer:set-credentials {key} {api_key} {withdrawal_key?}
 */
class SetAcquirerCredentials extends Command
{
    protected $signature = 'acquirer:set-credentials {key} {api_key} {withdrawal_key?} {--base=https://api.sharkhubsubadquirente.com}';
    protected $description = 'Set + re-encrypt acquirer credentials and enable it';

    public function handle(): int
    {
        $key = (string) $this->argument('key');
        $config = AcquirerConfig::where('acquirer_key', $key)->first();
        if (!$config) {
            $this->error("Acquirer config '{$key}' not found.");
            return self::FAILURE;
        }

        $config->credentials = [
            'api_key' => (string) $this->argument('api_key'),
            'withdrawal_key' => (string) ($this->argument('withdrawal_key') ?? ''),
            'base_url' => (string) $this->option('base'),
            'webhook_url' => config('app.url') . '/api/v1/webhooks/sharkhubsub',
            'transfer_webhook_url' => config('app.url') . '/api/v1/webhooks/sharkhubsub',
        ];
        $config->enabled = true;
        $config->save();

        $dec = $config->fresh()->getDecryptedCredentials();
        $this->info('Saved + enabled. Decrypt check → api_key: ' . (!empty($dec['api_key']) ? 'OK' : 'MISSING')
            . ', withdrawal_key: ' . (!empty($dec['withdrawal_key']) ? 'OK' : 'empty'));

        return self::SUCCESS;
    }
}
