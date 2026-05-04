<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Charge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Detect abandoned checkouts — charges created but never processed
 * within 30 minutes. Dispatches checkout.abandoned messaging trigger
 * for recovery via WhatsApp.
 *
 * Runs every 5 minutes via scheduler.
 */
class DetectAbandonedCheckoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function handle(): void
    {
        $abandonedCharges = Charge::where('status', Charge::STATUS_PENDING)
            ->where('created_at', '<', now()->subMinutes(30))
            ->where('created_at', '>', now()->subHours(2))
            ->whereNull('abandoned_notified_at')
            ->limit(200)
            ->get();

        $count = 0;

        foreach ($abandonedCharges as $charge) {
            try {
                // Mark as notified to avoid duplicate sends
                DB::table('charges')
                    ->where('id', $charge->id)
                    ->whereNull('abandoned_notified_at')
                    ->update(['abandoned_notified_at' => now()]);

                DispatchPaymentNotificationsJob::dispatch($charge->id, 'checkout.abandoned')
                    ->onQueue('notifications');

                $count++;
            } catch (\Exception $e) {
                Log::error('DetectAbandonedCheckoutsJob: failed to process', [
                    'charge_id' => $charge->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($count > 0) {
            Log::info('DetectAbandonedCheckoutsJob completed', [
                'abandoned_count' => $count,
                'timestamp' => now()->toIso8601String(),
            ]);
        }
    }
}
