<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Webhook;
use App\Models\Charge;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches webhook events to per-account webhook URLs
 * 
 * Fires notifications to URLs configured by users in the IntegraÃ§Ãµes â†’ Webhook section
 */
class WebhookDispatchService
{
    /**
     * Dispatch a payment event to all active webhooks for the account
     */
    public function dispatchPaymentEvent(string $event, Charge $charge): void
    {
        $webhooks = Webhook::where('account_id', $charge->account_id)
            ->whereRaw('"is_active" = true')
            ->get();

        if ($webhooks->isEmpty()) {
            return;
        }

        $payload = $this->buildPaymentPayload($event, $charge);

        foreach ($webhooks as $webhook) {
            if ($webhook->isSubscribedTo($event) || $webhook->isSubscribedTo('*')) {
                $this->send($webhook, $event, $payload);
            }
        }
    }

    /**
     * Build the payment webhook payload
     */
    private function buildPaymentPayload(string $event, Charge $charge): array
    {
        $customer = $charge->customer ?? [];

        return [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'charge_id' => $charge->id,
                'status' => $charge->status,
                'amount' => $charge->amount,
                'net_amount' => $charge->net_amount,
                'currency' => $charge->currency ?? 'BRL',
                'payment_method' => $charge->payment_method,
                'acquirer' => $charge->acquirer ?? 'babylon',
                'acquirer_transaction_id' => $charge->acquirer_transaction_id,
                'pix_end2end_id' => $charge->pix_end2end_id,
                'installments' => $charge->installments,
                'customer' => [
                    'name' => $customer['name'] ?? null,
                    'email' => $customer['email'] ?? null,
                    'document' => $customer['document'] ?? null,
                    'phone' => $customer['phone'] ?? null,
                ],
                'product_id' => $charge->product_id,
                'checkout_id' => $charge->checkout_id,
                'paid_at' => $charge->paid_at?->toIso8601String(),
                'created_at' => $charge->created_at?->toIso8601String(),
                'metadata' => $charge->metadata,
            ],
        ];
    }

    /**
     * Send webhook request to a URL
     */
    public function send(Webhook $webhook, string $event, array $payload): void
    {
        try {
            $reason = null;
            if (!$this->isValidWebhookUrl($webhook->url, $reason)) {
                Log::warning('Webhook URL rejected (SSRF protection)', [
                    'webhook_id' => $webhook->id,
                    'url' => $webhook->url,
                    'reason' => $reason,
                ]);

                $this->recordAttempt($webhook, $event, $payload, 0, false, $reason);
                return;
            }

            $body = json_encode($payload);

            $headers = [
                'Content-Type' => 'application/json',
                'X-Webhook-Event' => $event,
                'User-Agent' => 'Vector Pagamentos-Webhook/1.0',
            ];

            // Only sign if the webhook has a secret configured
            if (!empty($webhook->secret)) {
                $signature = hash_hmac('sha256', $body, $webhook->secret);
                $headers['X-Webhook-Signature'] = $signature;
                $headers['X-Vector-Signature'] = $signature;
            }

            $response = Http::timeout(10)
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($webhook->url);

            Log::info('Webhook dispatched', [
                'webhook_id' => $webhook->id,
                'url' => $webhook->url,
                'event' => $event,
                'status_code' => $response->status(),
                'success' => $response->successful(),
            ]);

            // Record attempt in webhook_attempts table if it exists
            $this->recordAttempt($webhook, $event, $payload, $response->status(), $response->successful());

        } catch (\Exception $e) {
            Log::error('Webhook dispatch failed', [
                'webhook_id' => $webhook->id,
                'url' => $webhook->url,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            $this->recordAttempt($webhook, $event, $payload, 0, false, $e->getMessage());
        }
    }

    private function isValidWebhookUrl(string $url, ?string &$reason = null): bool
    {
        $parsed = parse_url($url);

        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            $reason = 'invalid_url';
            return false;
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            $reason = 'invalid_scheme';
            return false;
        }

        if (config('app.env') === 'production' && $scheme !== 'https') {
            $reason = 'https_required';
            return false;
        }

        $host = strtolower($parsed['host']);
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
            $reason = 'loopback_host';
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($this->isPrivateIp($host)) {
                $reason = 'private_ip';
                return false;
            }

            return true;
        }

        $ip = gethostbyname($host);
        if ($ip !== $host && $this->isPrivateIp($ip)) {
            $reason = 'private_ip';
            return false;
        }

        return true;
    }

    private function isPrivateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $privateRanges = [
                '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
                '127.0.0.0/8', '169.254.0.0/16', '0.0.0.0/8',
            ];

            $longIp = ip2long($ip);
            foreach ($privateRanges as $range) {
                [$subnet, $mask] = explode('/', $range);
                if (($longIp & ~((1 << (32 - (int) $mask)) - 1)) === ip2long($subnet)) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lowerIp = strtolower($ip);
            if ($lowerIp === '::1') {
                return true;
            }

            return str_starts_with($lowerIp, 'fc')
                || str_starts_with($lowerIp, 'fd')
                || str_starts_with($lowerIp, 'fe80');
        }

        return true;
    }

    /**
     * Record webhook delivery attempt
     */
    private function recordAttempt(
        Webhook $webhook,
        string $event,
        array $payload,
        int $statusCode,
        bool $success,
        ?string $error = null
    ): void {
        try {
            \Illuminate\Support\Facades\DB::table('webhook_attempts')->insert([
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'webhook_id' => $webhook->id,
                'event_type' => $event,
                'payload' => json_encode($payload),
                'response_code' => $statusCode,
                'response_body' => $error,
                'success' => $success,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Table may not exist yet, just log
            Log::debug('Could not record webhook attempt', ['error' => $e->getMessage()]);
        }
    }
}
