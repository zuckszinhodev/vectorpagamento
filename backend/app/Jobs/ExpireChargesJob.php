<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Charge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Automatically expire charges that have passed their expiration time.
 * 
 * Runs every 2 minutes to mark pending/processing charges as expired
 * when their expires_at timestamp has passed.
 */
class ExpireChargesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function handle(): void
    {
        $expiredCount = Charge::whereIn('status', [
                Charge::STATUS_PENDING,
                Charge::STATUS_PROCESSING,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->chunkById(100, function ($charges) {
                $count = 0;
                foreach ($charges as $charge) {
                    try {
                        $charge->update([
                            'status' => Charge::STATUS_EXPIRED,
                            'failure_reason' => 'Tempo de pagamento expirado',
                        ]);
                        $count++;

                        Log::debug('Charge expired', [
                            'charge_id' => $charge->id,
                            'payment_method' => $charge->payment_method,
                            'expired_at' => $charge->expires_at,
                        ]);

                        // Dispatch recovery messaging trigger based on payment method
                        $recoveryEvent = match ($charge->payment_method) {
                            'pix' => 'pix.expired',
                            'boleto' => 'boleto.expired',
                            default => 'payment.expired',
                        };

                        DispatchPaymentNotificationsJob::dispatch($charge->id, $recoveryEvent)
                            ->onQueue('notifications');
                    } catch (\Exception $e) {
                        Log::error('Failed to expire charge', [
                            'charge_id' => $charge->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                return $count;
            });

        Log::info('ExpireChargesJob completed', [
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
