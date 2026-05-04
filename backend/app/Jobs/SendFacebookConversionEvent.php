<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FacebookPixelIntegration;
use App\Services\FacebookConversionsApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sends a conversion event to Meta Conversions API asynchronously.
 */
class SendFacebookConversionEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];
    public int $maxExceptions = 3;

    public function __construct(
        public string $integrationId,
        public array $payload,
    ) {
        $this->onQueue('default');
    }

    public function handle(FacebookConversionsApiService $service): void
    {
        $integration = FacebookPixelIntegration::find($this->integrationId);

        if (!$integration) {
            Log::warning('Facebook CAPI job skipped – integration not found', [
                'integration_id' => $this->integrationId,
            ]);
            return;
        }

        if (!$integration->is_active) {
            Log::info('Facebook CAPI job skipped – integration inactive', [
                'integration_id' => $integration->id,
            ]);
            return;
        }

        $result = $service->send($integration, $this->payload);

        if (!$result['success']) {
            Log::warning('Facebook CAPI job failed', [
                'integration_id' => $integration->id,
                'pixel_id'       => $integration->pixel_id,
                'event'          => $this->payload['event_name'] ?? 'unknown',
                'status'         => $result['status'],
                'error'          => $result['error'] ?? null,
            ]);

            // Throw exception to trigger retry via the queue
            if ($this->attempts() < $this->tries) {
                throw new \RuntimeException('Facebook CAPI send failed: ' . ($result['error'] ?? 'Unknown'));
            }
        } else {
            Log::info('Facebook CAPI event sent successfully', [
                'integration_id' => $integration->id,
                'event'          => $this->payload['event_name'] ?? 'unknown',
            ]);
        }
    }
}
