<?php

namespace App\Infrastructure\Jobs;

use App\Domain\Payment\Entities\Webhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 30;
    public array $backoff = [10, 60, 300, 900, 3600]; // 10s, 1m, 5m, 15m, 1h

    public function __construct(
        public string $webhookId,
        public string $url,
        public string $event,
        public array $payload,
        public ?string $secret = null
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        // SSRF protection: validate webhook URL
        if (!$this->isValidWebhookUrl($this->url)) {
            Log::error("Webhook URL rejected (SSRF protection)", [
                'webhook_id' => $this->webhookId,
                'url' => $this->url,
            ]);
            return;
        }

        $startTime = microtime(true);

        try {
            // Encode payload once for both signature and body
            $body = json_encode($this->payload, JSON_UNESCAPED_UNICODE);

            $headers = [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Vector Pagamentos-Webhook/1.0',
                'X-Webhook-Event' => $this->event,
                'X-Webhook-ID' => $this->webhookId,
                'X-Webhook-Timestamp' => now()->toIso8601String(),
            ];

            // Add signature if secret is provided
            if ($this->secret) {
                $signature = hash_hmac('sha256', $body, $this->secret);
                $headers['X-Webhook-Signature'] = $signature;
            }

            $response = Http::timeout($this->timeout)
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($this->url);

            $duration = (microtime(true) - $startTime) * 1000;

            // Log successful delivery
            $this->recordAttempt(
                success: $response->successful(),
                statusCode: $response->status(),
                response: $response->body(),
                duration: $duration
            );

            if (!$response->successful()) {
                throw new \Exception("Webhook failed with status {$response->status()}");
            }

            Log::info("Webhook delivered successfully", [
                'webhook_id' => $this->webhookId,
                'event' => $this->event,
                'url' => $this->url,
                'status' => $response->status(),
                'duration_ms' => round($duration, 2),
            ]);

        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordAttempt(
                success: false,
                statusCode: 0,
                response: $e->getMessage(),
                duration: $duration
            );

            Log::error("Webhook delivery failed", [
                'webhook_id' => $this->webhookId,
                'event' => $this->event,
                'url' => $this->url,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
                'duration_ms' => round($duration, 2),
            ]);

            throw $e;
        }
    }

    private function recordAttempt(
        bool $success,
        int $statusCode,
        string $response,
        float $duration
    ): void {
        // Create webhook attempt record
        \DB::table('webhook_attempts')->insert([
            'id' => \Str::uuid(),
            'webhook_id' => $this->webhookId,
            'success' => $success,
            'status_code' => $statusCode,
            'response' => substr($response, 0, 5000), // Limit response size
            'duration_ms' => round($duration, 2),
            'attempt_number' => $this->attempts(),
            'created_at' => now(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Webhook permanently failed after {$this->tries} attempts", [
            'webhook_id' => $this->webhookId,
            'event' => $this->event,
            'url' => $this->url,
            'error' => $exception->getMessage(),
        ]);

        // Mark webhook as failed
        \DB::table('webhooks')
            ->where('id', $this->webhookId)
            ->update([
                'status' => 'failed',
                'failed_at' => now(),
            ]);
    }

    private function isValidWebhookUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        // Require HTTPS in production
        if (config('app.env') === 'production' && $parsed['scheme'] !== 'https') {
            return false;
        }

        if (!in_array($parsed['scheme'], ['http', 'https'], true)) {
            return false;
        }

        $host = $parsed['host'];

        // Block loopback and private IPs
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
            return false;
        }

        // Resolve hostname and check for private IP ranges
        $ip = gethostbyname($host);
        if ($ip !== $host) {
            $privateRanges = [
                '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
                '127.0.0.0/8', '169.254.0.0/16', '0.0.0.0/8',
            ];
            $longIp = ip2long($ip);
            foreach ($privateRanges as $range) {
                [$subnet, $mask] = explode('/', $range);
                if (($longIp & ~((1 << (32 - (int)$mask)) - 1)) === ip2long($subnet)) {
                    return false;
                }
            }
        }

        return true;
    }
}
