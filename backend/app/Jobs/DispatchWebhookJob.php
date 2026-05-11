<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Webhook;
use App\Services\WebhookDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];
    public int $maxExceptions = 3;

    public function __construct(
        private readonly string $webhookId,
        private readonly string $event,
        private readonly array $payload,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(WebhookDispatchService $service): void
    {
        $webhook = Webhook::find($this->webhookId);

        if (!$webhook || !$webhook->is_active) {
            Log::debug('DispatchWebhookJob: webhook not found or inactive', [
                'webhook_id' => $this->webhookId,
            ]);
            return;
        }

        $service->sendSync($webhook, $this->event, $this->payload);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('DispatchWebhookJob: permanently failed', [
            'webhook_id' => $this->webhookId,
            'event' => $this->event,
            'error' => $exception->getMessage(),
        ]);
    }
}
