<?php

declare(strict_types=1);

namespace Application\Payment\Services;

use Infrastructure\Queue\Jobs\SendWebhookJob;
use Illuminate\Support\Facades\DB;

class WebhookDispatcherService
{
    public function dispatch(string $accountId, string $eventType, array $payload): void
    {
        $webhooks = DB::table('webhooks')
            ->where('account_id', $accountId)
            ->whereRaw('"is_active" = true')
            ->whereNull('deleted_at')
            ->get();

        foreach ($webhooks as $webhook) {
            $events = json_decode($webhook->events, true) ?? [];

            // Check if webhook is subscribed to this event
            if (!$this->isSubscribedToEvent($events, $eventType)) {
                continue;
            }

            // Generate signature
            $timestamp = time();
            $signaturePayload = $timestamp . '.' . json_encode($payload);
            $signature = hash_hmac('sha256', $signaturePayload, $webhook->secret);

            // Dispatch job
            dispatch(new SendWebhookJob(
                webhookId: $webhook->id,
                url: $webhook->url,
                eventType: $eventType,
                payload: $payload,
                signature: "t={$timestamp},v1={$signature}",
                attempt: 1
            ));
        }
    }

    private function isSubscribedToEvent(array $subscribedEvents, string $eventType): bool
    {
        // Wildcard support
        if (in_array('*', $subscribedEvents)) {
            return true;
        }

        // Direct match
        if (in_array($eventType, $subscribedEvents)) {
            return true;
        }

        // Pattern matching (e.g., "payment.*" matches "payment.approved")
        foreach ($subscribedEvents as $pattern) {
            if (str_ends_with($pattern, '.*')) {
                $prefix = substr($pattern, 0, -2);
                if (str_starts_with($eventType, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
