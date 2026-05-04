<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process a single withdrawal via acquirer PIX OUT.
 *
 * Dispatched when:
 * - User requests an instant withdrawal (is_instant=true)
 * - Auto-withdrawal processes a pending withdrawal
 * - Admin triggers manual processing
 */
class ProcessWithdrawalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];
    public int $maxExceptions = 3;

    public function __construct(
        public Withdrawal $withdrawal,
    ) {
        $this->onQueue('withdrawals');
    }

    public function handle(WithdrawalService $service): void
    {
        $withdrawal = $this->withdrawal->fresh();

        if (!$withdrawal) {
            Log::warning('ProcessWithdrawalJob: withdrawal no longer exists', [
                'withdrawal_id' => $this->withdrawal->id,
            ]);
            return;
        }

        $isRecoverableProcessing = $withdrawal->status === 'processing' && empty($withdrawal->acquirer_transfer_id);

        if (!in_array($withdrawal->status, ['pending'], true) && !$isRecoverableProcessing) {
            Log::info('ProcessWithdrawalJob: skipping, not pending', [
                'withdrawal_id' => $withdrawal->id,
                'status'        => $withdrawal->status,
            ]);
            return;
        }

        $result = $service->processWithdrawal($withdrawal);

        if (!$result['success']) {
            Log::warning('ProcessWithdrawalJob: withdrawal processing failed', [
                'withdrawal_id' => $withdrawal->id,
                'error'         => $result['error'] ?? 'Unknown',
                'attempt'       => $this->attempts(),
            ]);

            // Don't retry — the service already marked it as failed and returned funds
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessWithdrawalJob: job failed permanently', [
            'withdrawal_id' => $this->withdrawal->id,
            'error'         => $exception->getMessage(),
        ]);
    }
}
