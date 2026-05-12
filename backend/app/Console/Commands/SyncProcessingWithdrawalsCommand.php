<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WithdrawalService;
use Illuminate\Console\Command;

class SyncProcessingWithdrawalsCommand extends Command
{
    protected $signature = 'withdrawals:sync-processing {--limit=50 : Maximum withdrawals to sync}';

    protected $description = 'Sync processing withdrawals against acquirers when webhooks were missed';

    public function handle(WithdrawalService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $results = $service->syncProcessingWithdrawals($limit);

        $this->info('Processing withdrawals sync completed.');
        $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
