<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\N8nDeliveryLog;
use App\Models\N8nIntegration;
use App\Jobs\SendN8nWebhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Http\Client\Response;

/**
 * N8n Webhook Service
 * 
 * Handles sending webhook events to n8n workflows
 */
class N8nWebhookService
{
    /**
     * Dispatch webhook event to all active n8n integrations
     */
    public function dispatch(string $event, array $data, ?string $accountId = null): void
    {
        $query = N8nIntegration::active()->forEvent($event);
        
        if ($accountId) {
            $query->where(function ($q) use ($accountId) {
                $q->where('account_id', $accountId)
                  ->orWhereNull('account_id'); // Global integrations
            });
        }
        
        $integrations = $query->get();
        
        foreach ($integrations as $integration) {
            SendN8nWebhook::dispatch($integration, $event, $data);
        }
        
        Log::info('N8n webhooks dispatched', [
            'event' => $event,
            'integrations_count' => $integrations->count(),
            'account_id' => $accountId,
        ]);
    }

    /**
     * Send webhook synchronously (for testing)
     */
    public function send(N8nIntegration $integration, string $event, array $data): array
    {
        $payload = $this->buildPayload($integration, $event, $data);
        $headers = $this->buildHeaders($integration, $payload);
        
        $attempt = 0;
        $maxRetries = $integration->retry_count ?? 3;
        $lastError = null;

        // Create delivery log
        $deliveryLogData = [
            'id' => Str::uuid()->toString(),
            'integration_id' => $integration->id,
            'event' => $event,
            'status' => N8nDeliveryLog::STATUS_PENDING,
            'attempt' => 1,
            'max_attempts' => $maxRetries,
            'request_payload' => $payload,
            'request_headers' => array_diff_key($headers, array_flip(['X-Webhook-Signature'])),
        ];

        // Backward compatibility for legacy n8n_delivery_logs schema
        if (Schema::hasColumn('n8n_delivery_logs', 'payload')) {
            $deliveryLogData['payload'] = $payload;
        }

        $deliveryLog = N8nDeliveryLog::create($deliveryLogData);
        
        // Skip SSL verification in local development
        $httpClient = Http::timeout($integration->timeout ?? 30)
            ->withHeaders($headers);
        
        if (app()->environment('local')) {
            $httpClient = $httpClient->withoutVerifying();
        }
        
        while ($attempt < $maxRetries) {
            $attempt++;

            $deliveryLog->update(['attempt' => $attempt]);
            $startTime = microtime(true);
            
            try {
                $response = $httpClient->post($integration->webhook_url, $payload);
                $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
                
                if ($response->successful()) {
                    $integration->markSuccess((string) $response->status());
                    $deliveryLog->markSuccess($response->status(), $response->body(), $responseTimeMs);
                    
                    return [
                        'success' => true,
                        'status' => $response->status(),
                        'attempt' => $attempt,
                        'response' => $response->json() ?? $response->body(),
                        'delivery_log_id' => $deliveryLog->id,
                    ];
                }
                
                $lastError = "HTTP {$response->status()}: {$response->body()}";
                $deliveryLog->markFailed($lastError, $response->status(), $response->body());
                
                Log::warning('N8n webhook failed', [
                    'integration_id' => $integration->id,
                    'event' => $event,
                    'attempt' => $attempt,
                    'status' => $response->status(),
                    'delivery_log_id' => $deliveryLog->id,
                ]);
                
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                $deliveryLog->markFailed($lastError);
                
                Log::error('N8n webhook exception', [
                    'integration_id' => $integration->id,
                    'event' => $event,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'delivery_log_id' => $deliveryLog->id,
                ]);
            }
            
            // Wait before retry
            if ($attempt < $maxRetries) {
                sleep($integration->retry_delay ?? 60);
            }
        }
        
        $integration->markFailure($lastError);
        
        return [
            'success' => false,
            'error' => $lastError,
            'attempts' => $attempt,
            'delivery_log_id' => $deliveryLog->id,
        ];
    }

    /**
     * Build webhook payload
     */
    protected function buildPayload(N8nIntegration $integration, string $event, array $data): array
    {
        return [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'source' => 'vectorpagamentos-gateway',
            'version' => '1.0',
            'integration_id' => $integration->id,
            'account_id' => $integration->account_id,
            'data' => $data,
            'metadata' => array_merge(
                $integration->metadata ?? [],
                [
                    'event_category' => explode('.', $event)[0] ?? 'unknown',
                ]
            ),
        ];
    }

    /**
     * Build request headers with signature
     */
    protected function buildHeaders(N8nIntegration $integration, array $payload): array
    {
        $payloadJson = json_encode($payload);
        $timestamp = time();
        
        // Generate HMAC signature
        $signature = hash_hmac(
            'sha256',
            $timestamp . '.' . $payloadJson,
            $integration->secret_key ?? ''
        );
        
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'Vector Pagamentos-Gateway/1.0',
            'X-Webhook-Source' => 'vectorpagamentos',
            'X-Webhook-Event' => $payload['event'],
            'X-Webhook-Timestamp' => (string) $timestamp,
            'X-Webhook-Signature' => "sha256={$signature}",
            'X-Webhook-Id' => $payload['id'],
        ];
        
        // Add custom headers from integration config
        if (!empty($integration->headers)) {
            $headers = array_merge($headers, $integration->headers);
        }
        
        return $headers;
    }

    /**
     * Test webhook connection
     */
    public function test(N8nIntegration $integration): array
    {
        $testPayload = [
            'test' => true,
            'message' => 'Este é um teste de conexão do Vector Pagamentos',
            'integration_id' => $integration->id,
            'integration_name' => $integration->name,
            'timestamp' => now()->toIso8601String(),
        ];
        
        return $this->send($integration, 'test.connection', $testPayload);
    }

    /**
     * Get delivery stats for an integration
     */
    public function getStats(N8nIntegration $integration): array
    {
        $cacheKey = "n8n_stats_{$integration->id}";
        
        return Cache::remember($cacheKey, 300, function () use ($integration) {
            $total = $integration->success_count + $integration->failure_count;
            
            return [
                'total_deliveries' => $total,
                'successful' => $integration->success_count,
                'failed' => $integration->failure_count,
                'success_rate' => $total > 0 ? round(($integration->success_count / $total) * 100, 2) : 0,
                'last_triggered_at' => $integration->last_triggered_at?->toIso8601String(),
                'last_status' => $integration->last_status,
                'health' => $integration->getHealthStatus(),
            ];
        });
    }

    /**
     * Verify webhook signature (for incoming webhooks from n8n)
     */
    public function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $parts = explode(',', $signature);
        $timestamp = null;
        $sig = null;
        
        foreach ($parts as $part) {
            [$key, $value] = explode('=', $part, 2);
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'sha256') {
                $sig = $value;
            }
        }
        
        if (!$timestamp || !$sig) {
            return false;
        }
        
        // Check timestamp is within 5 minutes
        if (abs(time() - (int)$timestamp) > 300) {
            return false;
        }
        
        $expectedSignature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        
        return hash_equals($expectedSignature, $sig);
    }

    /**
     * Dispatch payment event
     */
    public function dispatchPaymentEvent(string $status, array $paymentData, ?string $accountId = null): void
    {
        $eventMap = [
            'pending' => 'payment.created',
            'processing' => 'payment.processing',
            'paid' => 'payment.approved',
            'failed' => 'payment.rejected',
            'refunded' => 'payment.refunded',
            'cancelled' => 'payment.cancelled',
            'expired' => 'payment.expired',
        ];
        
        $event = $eventMap[$status] ?? 'payment.updated';
        
        $this->dispatch($event, $paymentData, $accountId);
    }

    /**
     * Dispatch charge event
     */
    public function dispatchChargeEvent(string $status, array $chargeData, ?string $accountId = null): void
    {
        $eventMap = [
            'pending' => 'charge.created',
            'paid' => 'charge.paid',
            'expired' => 'charge.expired',
            'cancelled' => 'charge.cancelled',
        ];
        
        $event = $eventMap[$status] ?? 'charge.updated';
        
        $this->dispatch($event, $chargeData, $accountId);
    }

    /**
     * Dispatch PIX event
     */
    public function dispatchPixEvent(string $action, array $pixData, ?string $accountId = null): void
    {
        $eventMap = [
            'generated' => 'pix.generated',
            'paid' => 'pix.paid',
            'expired' => 'pix.expired',
        ];
        
        $event = $eventMap[$action] ?? 'pix.updated';
        
        $this->dispatch($event, $pixData, $accountId);
    }
}
