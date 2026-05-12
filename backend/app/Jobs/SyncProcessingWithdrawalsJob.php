<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\WithdrawalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Poll acquirers for withdrawals that stayed processing after webhook delivery failed.
 */
class SyncProcessingWithdrawalsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;
    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue('withdrawals');
    }

    public function uniqueId(): string
    {
        return 'sync-processing-withdrawals';
    }

    public function handle(WithdrawalService $service): void
    {
        Log::info('SyncProcessingWithdrawalsJob: starting');

        $results = $service->syncProcessingWithdrawals();

        Log::info('SyncProcessingWithdrawalsJob: completed', $results);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncProcessingWithdrawalsJob: failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
