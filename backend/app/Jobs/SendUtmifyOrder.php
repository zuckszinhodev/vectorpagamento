<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\UtmifyIntegration;
use App\Services\UtmifyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendUtmifyOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of retry attempts.
     */
    public int $tries = 3;

    /**
     * Backoff between retries (seconds).
     */
    public array $backoff = [30, 60, 120];

    public int $maxExceptions = 3;

    public function __construct(
        public UtmifyIntegration $integration,
        public array $payload,
    ) {
        $this->onQueue('default');
    }

    public function handle(UtmifyService $service): void
    {
        if (!$this->integration->is_active) {
            Log::info('UTMify order skipped – integration inactive', [
                'integration_id' => $this->integration->id,
            ]);
            return;
        }

        // UTMify requires a two-step flow for terminal statuses:
        // first create the order (waiting_payment), then update to final status.
        // Always send waiting_payment pre-step for terminal statuses to guarantee
        // the order exists in UTMify, even if the initial notification was lost.
        $terminalStatuses = ['paid', 'refunded', 'chargedback'];
        $currentStatus = $this->payload['status'] ?? 'waiting_payment';

        if (in_array($currentStatus, $terminalStatuses)) {
            $waitingPayload = $this->payload;
            $waitingPayload['status'] = 'waiting_payment';
            $waitingPayload['approvedDate'] = null;
            $waitingPayload['refundedAt'] = null;

            $preResult = $service->send($this->integration, $waitingPayload);

            Log::info('UTMify pre-step (waiting_payment) sent via job', [
                'order_id'  => $this->payload['orderId'] ?? null,
                'success'   => $preResult['success'],
                'http_code' => $preResult['status'] ?? null,
            ]);

            usleep(500_000); // 500ms delay between steps
        }

        $result = $service->send($this->integration, $this->payload);

        if (!$result['success']) {
            Log::warning('UTMify order delivery failed', [
                'integration_id' => $this->integration->id,
                'order_id'       => $this->payload['orderId'] ?? null,
                'error'          => $result['error'] ?? 'Unknown',
            ]);

            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('UTMify order job failed permanently', [
            'integration_id' => $this->integration->id,
            'order_id'       => $this->payload['orderId'] ?? null,
            'error'          => $exception->getMessage(),
        ]);
    }
}
