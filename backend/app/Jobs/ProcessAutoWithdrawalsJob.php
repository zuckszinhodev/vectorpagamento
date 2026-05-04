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
 * Scheduled job: process all auto-enabled pending withdrawals.
 *
 * Runs every minute via the scheduler when auto_withdrawal_enabled=true.
 * Picks up withdrawals with auto_process=true and sends them via PIX OUT.
 */
class ProcessAutoWithdrawalsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300; // 5 minutes max
    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue('withdrawals');
    }

    public function uniqueId(): string
    {
        return 'process-auto-withdrawals-batch';
    }

    public function handle(WithdrawalService $service): void
    {
        Log::info('ProcessAutoWithdrawalsJob: starting batch');

        $results = $service->processAutoWithdrawals();

        Log::info('ProcessAutoWithdrawalsJob: batch completed', $results);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessAutoWithdrawalsJob: job failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
