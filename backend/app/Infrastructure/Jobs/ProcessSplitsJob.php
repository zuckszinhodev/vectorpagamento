<?php

namespace App\Infrastructure\Jobs;

use App\Domain\Payment\Entities\Split;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessSplitsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public string $chargeId
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Log::info("Processing splits for charge", ['charge_id' => $this->chargeId]);

        try {
            DB::transaction(function () {
                $charge = \DB::table('charges')->where('id', $this->chargeId)->first();

                if (!$charge || $charge->status !== 'paid') {
                    throw new \Exception("Charge not found or not paid");
                }

                // Get all pending splits for this charge
                $splits = \DB::table('splits')
                    ->where('charge_id', $this->chargeId)
                    ->where('status', 'pending')
                    ->get();

                foreach ($splits as $split) {
                    // Update recipient wallet
                    \DB::table('account_wallets')
                        ->where('account_id', $split->recipient_account_id)
                        ->increment('available_balance', $split->amount);

                    // Mark split as processed
                    \DB::table('splits')
                        ->where('id', $split->id)
                        ->update([
                            'status' => 'processed',
                            'processed_at' => now(),
                        ]);

                    Log::info("Split processed", [
                        'split_id' => $split->id,
                        'recipient' => $split->recipient_account_id,
                        'amount' => $split->amount,
                    ]);
                }
            });

            Log::info("All splits processed successfully", ['charge_id' => $this->chargeId]);

        } catch (\Exception $e) {
            Log::error("Failed to process splits", [
                'charge_id' => $this->chargeId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Split processing permanently failed", [
            'charge_id' => $this->chargeId,
            'error' => $exception->getMessage(),
        ]);

        // Mark all splits as failed
        \DB::table('splits')
            ->where('charge_id', $this->chargeId)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'failed_at' => now(),
            ]);
    }
}
