<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\N8nIntegration;
use App\Services\N8nWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendN8nWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff = [60, 120, 300];

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public N8nIntegration $integration,
        public string $event,
        public array $data
    ) {
        $this->onQueue('n8n-webhooks');
    }

    /**
     * Execute the job.
     */
    public function handle(N8nWebhookService $service): void
    {
        // Check if integration is still active
        if (!$this->integration->is_active) {
            Log::info('N8n webhook skipped - integration inactive', [
                'integration_id' => $this->integration->id,
                'event' => $this->event,
            ]);
            return;
        }

        $result = $service->send($this->integration, $this->event, $this->data);

        if (!$result['success']) {
            Log::warning('N8n webhook delivery failed', [
                'integration_id' => $this->integration->id,
                'event' => $this->event,
                'error' => $result['error'] ?? 'Unknown error',
            ]);

            // Let Laravel handle retry through job failure
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('N8n webhook job permanently failed', [
            'integration_id' => $this->integration->id,
            'event' => $this->event,
            'error' => $exception->getMessage(),
        ]);

        $this->integration->markFailure($exception->getMessage());
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'n8n',
            'webhook',
            "event:{$this->event}",
            "integration:{$this->integration->id}",
        ];
    }
}
