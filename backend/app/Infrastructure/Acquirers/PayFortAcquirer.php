<?php

namespace App\Infrastructure\Acquirers;

use App\Domain\Acquirer\Contracts\AcquirerInterface;
use App\Models\Charge;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayFort Bank Acquirer Implementation
 * 
 * API Documentation: https://docs.payfortbank.com
 * 
 * Authentication: Basic Auth (apiKey:apiSecret) → JWT Bearer Token (30min TTL)
 * PIX IN: POST /api/v1/pix/in/qrcode
 * PIX OUT: POST /api/v1/pix/out/pixkey
 * 
 * This class implements the simplified AcquirerInterface.
 * For full gateway functionality, use PayFortGateway instead.
 */
class PayFortAcquirer implements AcquirerInterface
{
    use Concerns\ConfigurableTimeouts;

    private string $apiKey;
    private string $apiSecret;
    private string $baseUrl;
    private string $webhookSecret;

    public function __construct(array $credentials)
    {
        $this->apiKey = $credentials['api_key'] ?? '';
        $this->apiSecret = $credentials['api_secret'] ?? '';
        $this->baseUrl = $credentials['base_url'] ?? 'https://api.payfortbank.com';
        $this->webhookSecret = $credentials['webhook_secret'] ?? '';
    }

    /**
     * Get acquirer name
     */
    public function getName(): string
    {
        return 'PayFort Bank';
    }

    /**
     * Authenticate with PayFort Bank API and get Bearer token
     * Token is cached for 25 minutes (TTL is 30 min)
     */
    private function getAuthToken(): string
    {
        $cacheKey = 'payfort_auth_token_' . md5($this->apiKey);

        return Cache::remember($cacheKey, 1500, function () {
            $basicAuth = base64_encode($this->apiKey . ':' . $this->apiSecret);

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $basicAuth,
                'Content-Type' => 'application/json',
            ])->connectTimeout($this->httpConnectTimeout(3))->timeout($this->httpTimeout(5))->post("{$this->baseUrl}/api/auth");

            if (!$response->successful()) {
                Log::error('PayFort auth failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException('PayFort authentication failed: ' . ($response->json()['message'] ?? 'Unknown error'));
            }

            $data = $response->json();

            if (!($data['success'] ?? false) || empty($data['token'])) {
                throw new \RuntimeException('PayFort auth response missing token');
            }

            return $data['token'];
        });
    }

    /**
     * Clear cached auth token (e.g., on 401 response)
     */
    private function clearAuthToken(): void
    {
        $cacheKey = 'payfort_auth_token_' . md5($this->apiKey);
        Cache::forget($cacheKey);
    }

    /**
     * Pre-warm the auth token so it's cached before we need it.
     * Call this early in the request lifecycle (e.g., service provider or middleware).
     */
    public function warmAuthToken(): void
    {
        try {
            $this->getAuthToken();
        } catch (\Exception $e) {
            Log::warning('PayFort auth pre-warm failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check if the auth token is already cached
     */
    public function hasWarmToken(): bool
    {
        $cacheKey = 'payfort_auth_token_' . md5($this->apiKey);
        return Cache::has($cacheKey);
    }

    /**
     * Get HTTP client with Bearer token authentication
     */
    private function getHttpClient()
    {
        $token = $this->getAuthToken();

        $client = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->connectTimeout($this->httpConnectTimeout(3))->timeout($this->httpTimeout(8));

        if (config('app.env') === 'local') {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * Make authenticated request with automatic token refresh on 401
     */
    private function makeRequest(string $method, string $url, array $payload = [], array $headers = []): \Illuminate\Http\Client\Response
    {
        $client = $this->getHttpClient();

        if (!empty($headers)) {
            $client = $client->withHeaders($headers);
        }

        $response = match ($method) {
            'GET' => $client->get($url),
            'POST' => $client->post($url, $payload),
            'PUT' => $client->put($url, $payload),
            'DELETE' => $client->delete($url),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };

        // If 401, clear token and retry once
        if ($response->status() === 401) {
            $this->clearAuthToken();
            $client = $this->getHttpClient();

            if (!empty($headers)) {
                $client = $client->withHeaders($headers);
            }

            $response = match ($method) {
                'GET' => $client->get($url),
                'POST' => $client->post($url, $payload),
                'PUT' => $client->put($url, $payload),
                'DELETE' => $client->delete($url),
            };
        }

        return $response;
    }

    /**
     * Process card payment
     * Note: PayFort Bank currently supports PIX only.
     * This is a placeholder for future card support.
     */
    public function processPayment(Charge $charge, array $paymentData): array
    {
        return [
            'success' => false,
            'error' => 'PayFort Bank does not support credit card payments. Use PIX instead.',
        ];
    }

    /**
     * Process refund
     * Note: PayFort Bank handles refunds via transaction status changes
     */
    public function refund(Charge $charge, float $amount): array
    {
        return [
            'success' => false,
            'error' => 'Refunds should be processed through PayFort Bank dashboard or API directly.',
        ];
    }

    /**
     * Generate PIX QR Code via PayFort Bank
     * 
     * POST /api/v1/pix/in/qrcode
     */
    public function generatePixQrCode(Charge $charge): array
    {
        try {
            $customer = $charge->customer ?? [];

            $customerData = [
                    'name' => $customer['name'] ?? 'Cliente',
                    'email' => $customer['email'] ?? 'cliente@email.com',
                    'document' => $customer['document'] ?? '',
                    'documentType' => strlen(preg_replace('/\D/', '', $customer['document'] ?? '')) <= 11 ? 'cpf' : 'cnpj',
                ];

            if (!empty($customer['phone'])) {
                $customerData['phone'] = $customer['phone'];
            }

            $payload = [
                'amountInCents' => (int)$charge->amount,
                'description' => $charge->description ?? 'Pagamento via PIX',
                'postbackUrl' => (config('acquirers.payfort.webhook_url') ?: config('app.url')) . '/api/v1/webhooks/payfort',
                'customer' => $customerData,
                'metadata' => [
                    'charge_id' => $charge->id ?? null,
                    'account_id' => $charge->account_id ?? null,
                    'checkout_id' => $charge->checkout_id ?? null,
                    'product_id' => $charge->product_id ?? null,
                ],
            ];

            Log::info('PayFort PIX request', [
                'charge_id' => $charge->id ?? null,
                'amount_cents' => $payload['amountInCents'],
            ]);

            $response = $this->makeRequest('POST', "{$this->baseUrl}/api/v1/pix/in/qrcode", $payload);

            if (!$response->successful()) {
                $error = $response->json();
                Log::error('PayFort PIX error', [
                    'charge_id' => $charge->id ?? null,
                    'status' => $response->status(),
                    'error' => $error,
                    'response_body' => $response->body(),
                    'payload_sent' => $payload,
                ]);

                return [
                    'success' => false,
                    'error' => $error['message'] ?? $response->body() ?? 'PIX generation failed',
                    'raw_response' => $error,
                ];
            }

            $data = $response->json();
            $txData = $data['data'] ?? $data;
            $pixData = $txData['pix'] ?? [];

            Log::info('PayFort PIX response', [
                'charge_id' => $charge->id ?? null,
                'transaction_id' => $txData['id'] ?? null,
                'status' => $txData['status'] ?? null,
            ]);

            return [
                'success' => true,
                'external_id' => $txData['id'] ?? null,
                'pix_qr_code' => $pixData['emv'] ?? null,
                'pix_qr_code_url' => $pixData['qrCode'] ?? null,
                'pix_expires_at' => $txData['expiresAt'] ?? null,
                'end2end_id' => null,
                'status' => $this->mapStatus($txData['status'] ?? 'pending'),
                'fees' => $txData['fees'] ?? null,
                'data' => $txData,
            ];
        } catch (\Exception $e) {
            Log::error('PayFort PIX exception', [
                'error' => $e->getMessage(),
                'charge_id' => $charge->id ?? null,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate Boleto
     * Note: PayFort Bank currently supports PIX only
     */
    public function generateBoleto(Charge $charge): array
    {
        return [
            'success' => false,
            'error' => 'PayFort Bank does not support Boleto generation. Use PIX instead.',
        ];
    }

    /**
     * Send PIX transfer (PIX OUT)
     * 
     * POST /api/v1/pix/out/pixkey
     * Requires x-idempotency-key header
     */
    public function sendPixTransfer(array $transferData): array
    {
        try {
            $payload = [
                'pixKey' => $transferData['pix_key'],
                'amount' => (int)($transferData['amount'] * 100),
                'currency' => 'BRL',
                'description' => $transferData['description'] ?? 'Transferência PIX',
                'postbackUrl' => config('app.url') . '/api/v1/webhooks/payfort',
            ];

            $idempotencyKey = $transferData['idempotency_key'] ?? \Illuminate\Support\Str::uuid()->toString();

            $response = $this->makeRequest('POST', "{$this->baseUrl}/api/v1/pix/out/pixkey", $payload, [
                'x-idempotency-key' => $idempotencyKey,
            ]);

            if (!$response->successful()) {
                $error = $response->json();
                Log::error('PayFort PIX OUT error', [
                    'error' => $error,
                    'status' => $response->status(),
                ]);

                return [
                    'success' => false,
                    'error' => $error['message'] ?? 'PIX transfer failed',
                ];
            }

            $data = $response->json();
            $txData = $data['data'] ?? $data;

            return [
                'success' => true,
                'transfer_id' => $txData['id'] ?? null,
                'amount' => ($txData['amount'] ?? 0) / 100,
                'net_amount' => ($txData['netAmount'] ?? 0) / 100,
                'fees' => $txData['fees'] ?? null,
                'status' => $txData['status'] ?? 'queued',
                'pix_key' => $txData['pixKey'] ?? $transferData['pix_key'],
                'data' => $txData,
            ];
        } catch (\Exception $e) {
            Log::error('PayFort PIX OUT exception', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get transaction status from PayFort Bank
     * 
     * GET /api/v1/transactions/:id
     */
    public function getTransaction(string $transactionId): array
    {
        try {
            $response = $this->makeRequest('GET', "{$this->baseUrl}/api/v1/transactions/{$transactionId}");

            if ($response->successful()) {
                $data = $response->json();
                $txData = $data['data'] ?? $data;
                $transaction = is_array($txData['transaction'] ?? null)
                    ? $txData['transaction']
                    : $txData;

                $status = strtolower((string) (
                    $transaction['status']
                    ?? $txData['status']
                    ?? data_get($data, 'data.transaction.status')
                    ?? 'unknown'
                ));

                $amount = $transaction['amount']
                    ?? $transaction['amountInCents']
                    ?? $txData['amount']
                    ?? $txData['amountInCents']
                    ?? data_get($data, 'data.transaction.amount')
                    ?? data_get($data, 'data.transaction.amountInCents');

                $paidAt = $transaction['paidAt']
                    ?? $transaction['paid_at']
                    ?? $txData['paidAt']
                    ?? $txData['paid_at']
                    ?? data_get($data, 'data.transaction.paidAt')
                    ?? data_get($data, 'data.transaction.paid_at');

                return [
                    'success' => true,
                    'status' => $status,
                    'amount' => $amount,
                    'paid_at' => $paidAt,
                    'data' => $transaction,
                ];
            }

            Log::warning('PayFort getTransaction failed', [
                'transaction_id' => $transactionId,
                'status_code' => $response->status(),
                'body' => substr($response->body(), 0, 300),
            ]);

            return [
                'success' => false,
                'error' => 'PayFort transaction query returned ' . $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('PayFort getTransaction exception', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Register webhook at PayFort Bank
     * 
     * POST /api/v1/webhook
     */
    public function registerWebhook(string $url, array $events = [], string $name = 'Vector Pagamentos Webhook'): array
    {
        try {
            if (empty($events)) {
                $events = [
                    'transaction_created',
                    'transaction_paid',
                    'transaction_refunded',
                    'transaction_updated',
                    'transfer_created',
                    'transfer_completed',
                    'transfer_canceled',
                    'transfer_updated',
                    'infraction',
                ];
            }

            $payload = [
                'url' => $url,
                'name' => $name,
                'isActive' => true,
                'events' => $events,
            ];

            $response = $this->makeRequest('POST', "{$this->baseUrl}/api/v1/webhook", $payload);

            if (!$response->successful()) {
                $errorBody = $response->json();
                Log::error('PayFort webhook registration failed', [
                    'status' => $response->status(),
                    'response' => $errorBody,
                    'payload_sent' => $payload,
                ]);

                return [
                    'success' => false,
                    'error' => $errorBody['message'] ?? $errorBody['error'] ?? 'Webhook registration failed',
                    'details' => $errorBody,
                    'status_code' => $response->status(),
                ];
            }

            $data = $response->json();
            $webhookData = $data['data'] ?? $data;

            return [
                'success' => true,
                'webhook_id' => $webhookData['id'] ?? null,
                'signature_secret' => $webhookData['signatureSecret'] ?? null,
                'data' => $webhookData,
            ];
        } catch (\Exception $e) {
            Log::error('PayFort webhook registration error', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * List registered webhooks
     * 
     * GET /api/v1/webhook
     */
    public function listWebhooks(): array
    {
        try {
            $response = $this->makeRequest('GET', "{$this->baseUrl}/api/v1/webhook");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Failed to list webhooks',
                ];
            }

            $data = $response->json();

            // PayFort returns: { success: true, data: { webhooks: [...], pagination: {...} } }
            $webhooks = $data['data']['webhooks'] 
                ?? $data['data'] 
                ?? (is_array($data) && !isset($data['success']) ? $data : []);

            // If $webhooks is not a list (e.g. it's an object with 'webhooks' key), extract
            if (isset($webhooks['webhooks'])) {
                $webhooks = $webhooks['webhooks'];
            }

            return [
                'success' => true,
                'webhooks' => is_array($webhooks) ? $webhooks : [],
                'raw' => $data,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Delete a webhook
     * 
     * DELETE /api/v1/webhook/:id
     */
    public function deleteWebhook($webhookId): array
    {
        try {
            $response = $this->makeRequest('DELETE', "{$this->baseUrl}/api/v1/webhook/{$webhookId}");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Failed to delete webhook',
                    'status_code' => $response->status(),
                ];
            }

            return [
                'success' => true,
                'message' => 'Webhook deleted successfully',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update/activate a webhook
     * 
     * PUT /api/v1/webhook/:id
     */
    public function updateWebhook($webhookId, array $data): array
    {
        try {
            $response = $this->makeRequest('PUT', "{$this->baseUrl}/api/v1/webhook/{$webhookId}", $data);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Failed to update webhook',
                    'status_code' => $response->status(),
                    'response' => $response->json(),
                ];
            }

            return [
                'success' => true,
                'data' => $response->json()['data'] ?? $response->json(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify PayFort webhook signature (HMAC-SHA256)
     * 
     * Header format: payfort-Signature: t=timestamp,v1=hmac_hex
     * Signed payload: "{timestamp}.{raw_json_body}"
     */
    public static function verifyWebhookSignature(string $payload, string $signatureHeader, string $secret): bool
    {
        if (empty($signatureHeader) || empty($secret)) {
            return false;
        }

        $elements = explode(',', $signatureHeader);
        $timestamp = null;
        $signature = null;

        foreach ($elements as $element) {
            $parts = explode('=', $element, 2);
            if (count($parts) !== 2) continue;

            [$prefix, $value] = $parts;
            if ($prefix === 't') {
                $timestamp = $value;
            } elseif ($prefix === 'v1') {
                $signature = $value;
            }
        }

        if (!$timestamp || !$signature) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $computedSignature = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($signature, $computedSignature);
    }

    /**
     * Map PayFort status to internal status
     */
    private function mapStatus(string $status): string
    {
        return match ($status) {
            'paid' => 'paid',
            'pending' => 'pending',
            'canceled', 'cancelled' => 'canceled',
            'refunded' => 'refunded',
            'queued' => 'pending',
            'processing' => 'processing',
            'completed' => 'paid',
            'infraction' => 'failed',
            default => 'pending',
        };
    }
}
