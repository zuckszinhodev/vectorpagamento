<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\XtrackyIntegration;
use App\Services\XtrackyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendXtrackyEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];
    public int $maxExceptions = 3;

    public function __construct(
        public XtrackyIntegration $integration,
        public array $payload,
    ) {
        $this->onQueue('default');
    }

    public function handle(XtrackyService $service): void
    {
        if (!$this->integration->is_active) {
            Log::info('XTracky event skipped – integration inactive', [
                'integration_id' => $this->integration->id,
            ]);
            return;
        }

        // Two-step flow for terminal statuses
        $terminalStatuses = ['paid', 'refunded', 'chargedback'];
        $currentStatus = $this->payload['status'] ?? 'waiting_payment';

        if (in_array($currentStatus, $terminalStatuses)) {
            $waitingPayload = $this->payload;
            $waitingPayload['status'] = 'waiting_payment';

            $preResult = $service->send($this->integration, $waitingPayload);

            Log::info('XTracky pre-step (waiting_payment) sent via job', [
                'order_id'  => $this->payload['orderId'] ?? null,
                'success'   => $preResult['success'],
                'http_code' => $preResult['status'] ?? null,
            ]);

            usleep(500_000); // 500ms delay
        }

        $result = $service->send($this->integration, $this->payload);

        if (!$result['success']) {
            Log::warning('XTracky event delivery failed', [
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
        Log::error('XTracky event job failed permanently', [
            'integration_id' => $this->integration->id,
            'order_id'       => $this->payload['orderId'] ?? null,
            'error'          => $exception->getMessage(),
        ]);
    }
}
