<?php

namespace App\Console\Commands;

use App\Models\AcquirerConfig;
use App\Services\AcquirerFactory;
use Illuminate\Console\Command;

class AcquirerDebugCommand extends Command
{
    protected $signature = 'acquirer:debug';
    protected $description = 'Debug acquirer configuration and selection priority';

    public function handle(): int
    {
        $this->info('=== Acquirer Debug Report ===');
        $this->newLine();

        // ENV Configuration
        $this->info('--- ENV Configuration ---');
        $this->table(['Variable', 'Value'], [
            ['DEFAULT_ACQUIRER', config('acquirers.default', '(not set)')],
            ['PAYFORT_ENABLED', config('acquirers.payfort.enabled') ? 'true' : 'false'],
            ['PAYFORT_API_KEY', !empty(config('acquirers.payfort.api_key')) ? '***set***' : '(empty)'],
            ['PAYFORT_API_SECRET', !empty(config('acquirers.payfort.api_secret')) ? '***set***' : '(empty)'],
            ['BLUPAY_ENABLED', config('acquirers.blupay.enabled') ? 'true' : 'false'],
            ['BABYLON_ENABLED', config('acquirers.babylon.enabled') ? 'true' : 'false'],
        ]);

        // Factory checks
        $this->newLine();
        $this->info('--- Factory: isConfigured Checks ---');
        $this->table(['Acquirer', 'isConfigured'], [
            ['payfort', AcquirerFactory::isPayFortConfigured() ? '<fg=green>YES</>' : '<fg=red>NO</>'],
            ['blupay', AcquirerFactory::isBluPayConfigured() ? '<fg=green>YES</>' : '<fg=red>NO</>'],
            ['babylon', AcquirerFactory::isBabylonConfigured() ? '<fg=green>YES</>' : '<fg=red>NO</>'],
        ]);

        // DB Configuration
        $this->newLine();
        $this->info('--- Database: acquirer_configs ---');

        try {
            $configs = AcquirerConfig::orderBy('priority', 'asc')->get();

            if ($configs->isEmpty()) {
                $this->warn('No acquirer_configs records found!');
            } else {
                $rows = [];
                foreach ($configs as $config) {
                    $dbCreds = $config->getDecryptedCredentials();
                    $hasValidCreds = $config->hasValidCredentials();

                    $credsStatus = 'none';
                    if ($dbCreds) {
                        $fields = array_keys(array_filter($dbCreds, fn($v) => !empty($v)));
                        $credsStatus = implode(', ', $fields) ?: 'empty values';
                    }

                    $rows[] = [
                        $config->acquirer_key,
                        $config->priority,
                        $config->enabled ? '<fg=green>yes</>' : '<fg=red>no</>',
                        $hasValidCreds ? '<fg=green>yes</>' : '<fg=red>no</>',
                        $credsStatus,
                    ];
                }

                $this->table(
                    ['Acquirer Key', 'Priority', 'Enabled', 'Valid Creds', 'DB Credential Fields'],
                    $rows
                );
            }
        } catch (\Exception $e) {
            $this->error('DB query failed: ' . $e->getMessage());
        }

        // Active acquirer selection
        $this->newLine();
        $this->info('--- Active Acquirer Selection ---');

        AcquirerFactory::reset();
        $factory = AcquirerFactory::instance();
        $active = $factory->getActiveAcquirerName();
        $this->line("Selected acquirer: <fg=yellow;options=bold>{$active}</>");

        // PIX acquirer order
        $pixOrder = $factory->getPixAcquirerOrder($active);
        $this->line('PIX fallback order: <fg=yellow>' . implode(' → ', $pixOrder) . '</>');

        // Instance check
        $this->newLine();
        $this->info('--- Acquirer Instances ---');
        $this->table(['Acquirer', 'Can Initialize'], [
            ['payfort', $factory->payfort() ? '<fg=green>YES</>' : '<fg=red>NO</>'],
            ['blupay', $factory->blupay() ? '<fg=green>YES</>' : '<fg=red>NO</>'],
        ]);

        $this->newLine();
        if ($active === 'mock') {
            $this->error('⚠ No acquirer configured! Payments will use mock data.');
            $this->line('Fix: Add credentials via Admin Panel or set PAYFORT_API_KEY/PAYFORT_API_SECRET in .env');
        } else {
            $this->info("✓ Payments will be processed via: {$active}");
        }

        return 0;
    }
}
