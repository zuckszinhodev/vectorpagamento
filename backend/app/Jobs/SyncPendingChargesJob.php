<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Charge;
use App\Services\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Poll acquirers for pending charges when webhook delivery is delayed or missed.
 */
class SyncPendingChargesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;
    public int $uniqueFor = 120;

    public function __construct(private readonly ?string $acquirer = null)
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'sync-pending-charges:' . ($this->acquirer ?? 'all');
    }

    public function handle(PaymentService $paymentService): void
    {
        $query = Charge::query()
            ->whereIn('status', ['pending', 'processing', 'waiting_payment'])
            ->whereNotNull('acquirer_transaction_id')
            ->orderBy('updated_at')
            ->limit(100);

        if ($this->acquirer) {
            $query->where('acquirer', $this->acquirer);
        }

        $results = [
            'checked' => 0,
            'synced' => 0,
            'approved' => 0,
            'unchanged' => 0,
            'errors' => 0,
        ];

        foreach ($query->get() as $charge) {
            $results['checked']++;

            try {
                $previousStatus = (string) $charge->status;
                $sync = $paymentService->syncChargeStatus($charge);
                $charge->refresh();

                if ($sync['synced'] ?? false) {
                    $results['synced']++;
                }

                if ($charge->status === 'approved' && $previousStatus !== 'approved') {
                    $results['approved']++;
                } elseif ($charge->status === $previousStatus) {
                    $results['unchanged']++;
                }
            } catch (\Throwable $e) {
                $results['errors']++;

                Log::error('SyncPendingChargesJob: failed to sync charge', [
                    'charge_id' => $charge->id,
                    'acquirer' => $charge->acquirer,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('SyncPendingChargesJob: completed', $results + [
            'acquirer' => $this->acquirer,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncPendingChargesJob: job failed', [
            'acquirer' => $this->acquirer,
            'error' => $exception->getMessage(),
        ]);
    }
}
