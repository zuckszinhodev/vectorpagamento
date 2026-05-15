<?php

namespace App\Infrastructure\Acquirers;

use App\Domain\Acquirer\Contracts\AcquirerInterface;
use App\Models\Charge;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BluPay IP Acquirer Implementation
 * 
 * API Documentation: https://docs.blupayip.io
 * Base URL: https://api.blupayip.io
 * 
 * Authentication: Basic Auth (secretKey:publicKey) on all endpoints.
 * PIX IN:  POST /api/v1/transactions
 * PIX OUT: POST /api/v1/transactions/pix-out (requires Idempotency-Key header)
 * GET PIX IN:  GET /api/v1/transactions/pix-in?id=xxx
 * GET PIX OUT: GET /api/v1/transactions/pix-out?id=xxx
 * Balance:     GET /api/v1/balance
 * Resend Webhook: POST /api/v1/transactions/{id}/resend-webhooks
 * 
 * Webhook events:
 *   PIX IN:  transaction.paid, transaction.refunded, transaction.infraction
 *   PIX OUT: transfer.paid, transfer.failed
 * 
 * Webhook signature: HMAC-SHA256 of body using webhookSecret → X-Webhook-Signature header
 * 
 * All monetary values are in CENTS (e.g. 10000 = R$ 100.00).
 */
class BluPayAcquirer implements AcquirerInterface
{
    private string $secretKey;
    private string $publicKey;
    private string $baseUrl;
    private string $webhookSecret;

    public function __construct(array $credentials)
    {
        $this->secretKey = $credentials['secret_key'] ?? '';
        $this->publicKey = $credentials['public_key'] ?? '';
        $this->baseUrl = rtrim($credentials['base_url'] ?? 'https://api.blupayip.io', '/');
        $this->webhookSecret = $credentials['webhook_secret'] ?? '';
    }

    /**
     * Get acquirer name
     */
    public function getName(): string
    {
        return 'BluPay';
    }

    /**
     * Get HTTP client with Basic Auth (secretKey:publicKey)
     */
    private function getHttpClient()
    {
        $basicAuth = base64_encode($this->secretKey . ':' . $this->publicKey);

        $client = Http::withHeaders([
            'Authorization' => 'Basic ' . $basicAuth,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->connectTimeout(3)->timeout(10);

        if (config('app.env') === 'local') {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * Process card payment — not supported, BluPay is PIX-only
     */
    public function processPayment(Charge $charge, array $paymentData): array
    {
        return [
            'success' => false,
            'error' => 'BluPay does not support credit card payments. Use PIX instead.',
        ];
    }

    /**
     * Process refund — BluPay handles refunds internally
     */
    public function refund(Charge $charge, float $amount): array
    {
        return [
            'success' => false,
            'error' => 'Refunds should be processed through BluPay dashboard.',
        ];
    }

    /**
     * Generate PIX QR Code via BluPay (PIX IN)
     * 
     * POST /api/v1/transactions
     * 
     * Request body:
     *   amount (int, cents), paymentMethod ("pix"), customer {name, email, phone, document {type, number}},
     *   items [{title, unitPrice, quantity, tangible}], postbackUrl, webhookSecret, externalRef, metadata
     * 
     * Response (201):
     *   id, amount, status, pix {qrcode, expiresAt}, secureUrl, fee {fixedAmount, spreadPercentage, estimatedFee, netAmount}
     */
    public function generatePixQrCode(Charge $charge): array
    {
        try {
            $customer = $charge->customer ?? [];
            $documentNumber = preg_replace('/\D/', '', $customer['document'] ?? '');
            $documentType = strlen($documentNumber) <= 11 ? 'cpf' : 'cnpj';

            $customerData = [
                'name' => $customer['name'] ?? 'Cliente',
                'email' => $customer['email'] ?? 'cliente@email.com',
                'document' => [
                    'type' => $documentType,
                    'number' => $documentNumber,
                ],
            ];

            if (!empty($customer['phone'])) {
                $customerData['phone'] = preg_replace('/\D/', '', $customer['phone']);
            }

            $description = $charge->description ?? 'Pagamento via PIX';

            $payload = [
                'amount' => (int) $charge->amount,
                'paymentMethod' => 'pix',
                'externalRef' => $charge->id,
                'customer' => $customerData,
                'items' => [
                    [
                        'title' => $description,
                        'unitPrice' => (int) $charge->amount,
                        'quantity' => 1,
                        'tangible' => false,
                    ],
                ],
                'postbackUrl' => config('app.url') . '/api/v1/webhooks/blupay',
                'metadata' => [
                    'charge_id' => $charge->id ?? null,
                    'account_id' => $charge->account_id ?? null,
                    'checkout_id' => $charge->checkout_id ?? null,
                    'product_id' => $charge->product_id ?? null,
                ],
            ];

            // Include webhook secret for HMAC signature verification
            if (!empty($this->webhookSecret)) {
                $payload['webhookSecret'] = $this->webhookSecret;
            }

            Log::info('BluPay PIX request', [
                'charge_id' => $charge->id ?? null,
                'amount_cents' => $payload['amount'],
            ]);

            $response = $this->getHttpClient()->post("{$this->baseUrl}/api/v1/transactions", $payload);

            if (!$response->successful()) {
                $error = $response->json();
                Log::error('BluPay PIX error', [
                    'charge_id' => $charge->id ?? null,
                    'status' => $response->status(),
                    'error' => $error,
                    'response_body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'error' => $error['message'] ?? $response->body() ?? 'PIX generation failed',
                    'raw_response' => $error,
                ];
            }

            $data = $response->json();
            $pixData = $data['pix'] ?? [];
            $feeData = $data['fee'] ?? [];

            Log::info('BluPay PIX response', [
                'charge_id' => $charge->id ?? null,
                'transaction_id' => $data['id'] ?? null,
                'status' => $data['status'] ?? null,
                'has_qrcode' => !empty($pixData['qrcode']),
            ]);

            return [
                'success' => true,
                'external_id' => $data['id'] ?? null,
                'pix_qr_code' => $pixData['qrcode'] ?? null,
                'pix_qr_code_url' => $data['secureUrl'] ?? null,
                'pix_expires_at' => $pixData['expiresAt'] ?? null,
                'end2end_id' => null,
                'status' => $this->mapStatus($data['status'] ?? 'pending'),
                'fees' => $feeData,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('BluPay PIX exception', [
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
     * Generate Boleto — not supported by BluPay
     */
    public function generateBoleto(Charge $charge): array
    {
        return [
            'success' => false,
            'error' => 'BluPay does not support Boleto generation. Use PIX instead.',
        ];
    }

    /**
     * Send PIX transfer (PIX OUT)
     * 
     * POST /api/v1/transactions/pix-out
     * Requires Idempotency-Key header (UUID)
     * 
     * Body: amount (cents), pixKey, pixKeyType (CPF|CNPJ|EMAIL|PHONE|RANDOM),
     *       destinationName, destinationDocument, externalRef, postbackUrl, webhookSecret, metadata
     * 
     * Response (201): {success: true, data: {id, status, amount, fee, netAmount, pixKey, ...}}
     */
    public function sendPixTransfer(array $transferData): array
    {
        try {
            $idempotencyKey = $transferData['idempotency_key'] ?? \Illuminate\Support\Str::uuid()->toString();

            $payload = [
                'amount' => (int) ($transferData['amount_cents'] ?? ($transferData['amount'] * 100)),
                'pixKey' => $transferData['pix_key'],
                'pixKeyType' => strtoupper($transferData['pix_key_type'] ?? 'CPF'),
                'destinationName' => $transferData['destination_name'] ?? 'Destinatário',
                'destinationDocument' => preg_replace('/\D/', '', $transferData['destination_document'] ?? ''),
                'externalRef' => $transferData['external_ref'] ?? $idempotencyKey,
                'postbackUrl' => config('app.url') . '/api/v1/webhooks/blupay',
            ];

            if (!empty($this->webhookSecret)) {
                $payload['webhookSecret'] = $this->webhookSecret;
            }

            if (!empty($transferData['metadata'])) {
                $payload['metadata'] = $transferData['metadata'];
            }

            $response = $this->getHttpClient()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post("{$this->baseUrl}/api/v1/transactions/pix-out", $payload);

            if (!$response->successful()) {
                $error = $response->json();
                Log::error('BluPay PIX OUT error', [
                    'error' => $error,
                    'status' => $response->status(),
                ]);

                return [
                    'success' => false,
                    'error' => $error['message'] ?? 'PIX transfer failed',
                    'code' => $error['code'] ?? null,
                ];
            }

            $data = $response->json();
            $txData = $data['data'] ?? $data;

            return [
                'success' => true,
                'transfer_id' => $txData['id'] ?? null,
                'amount' => ($txData['amount'] ?? 0) / 100,
                'net_amount' => ($txData['netAmount'] ?? 0) / 100,
                'fee' => ($txData['fee'] ?? 0) / 100,
                'status' => strtolower($txData['status'] ?? 'processing'),
                'pix_key' => $txData['pixKey'] ?? $transferData['pix_key'],
                'data' => $txData,
            ];
        } catch (\Exception $e) {
            Log::error('BluPay PIX OUT exception', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get PIX IN transaction status
     * 
     * GET /api/v1/transactions/pix-in?id=xxx or ?externalRef=xxx
     */
    public function getPixInTransaction(string $transactionId, ?string $externalRef = null): array
    {
        try {
            $query = [];
            if ($transactionId) {
                $query['id'] = $transactionId;
            }
            if ($externalRef) {
                $query['externalRef'] = $externalRef;
            }

            $response = $this->getHttpClient()->get("{$this->baseUrl}/api/v1/transactions/pix-in", $query);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Transaction not found',
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'data' => $data,
                'status' => $this->mapStatus($data['status'] ?? 'pending'),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get PIX OUT transaction status
     * 
     * GET /api/v1/transactions/pix-out?id=xxx
     */
    public function getPixOutTransaction(string $transactionId): array
    {
        try {
            $response = $this->getHttpClient()->get("{$this->baseUrl}/api/v1/transactions/pix-out", [
                'id' => $transactionId,
            ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Transfer not found',
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'data' => $data,
                'status' => $this->mapTransferStatus($data['status'] ?? 'processing'),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get wallet balances
     * 
     * GET /api/v1/balance
     * Response: {success: true, data: {pix: {balance: "36.36"}, pixBlocked: {balance: "0.00"}, reserve: {balance: "0.00"}}}
     */
    public function getBalance(): array
    {
        try {
            $response = $this->getHttpClient()->get("{$this->baseUrl}/api/v1/balance");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Failed to get balance',
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'pix_balance' => (float) ($data['data']['pix']['balance'] ?? 0),
                'pix_blocked' => (float) ($data['data']['pixBlocked']['balance'] ?? 0),
                'reserve' => (float) ($data['data']['reserve']['balance'] ?? 0),
                'data' => $data['data'] ?? [],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Resend webhooks for a transaction
     * 
     * POST /api/v1/transactions/{id}/resend-webhooks
     */
    public function resendWebhooks(string $transactionId): array
    {
        try {
            $response = $this->getHttpClient()->post(
                "{$this->baseUrl}/api/v1/transactions/{$transactionId}/resend-webhooks"
            );

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Failed to resend webhooks',
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'webhooks_queued' => $data['webhooksQueued'] ?? 0,
                'event_type' => $data['eventType'] ?? null,
                'destinations' => $data['destinations'] ?? [],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify BluPay webhook signature (HMAC-SHA256)
     * 
     * If webhookSecret was provided when creating the transaction,
     * webhooks will include X-Webhook-Signature header with HMAC-SHA256 of the body.
     */
    public static function verifyWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        if (empty($signature) || empty($secret)) {
            return false;
        }

        $computedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($signature, $computedSignature);
    }

    /**
     * Map BluPay transaction status to internal status
     */
    private function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'paid', 'approved', 'confirmed' => 'paid',
            'pending', 'waiting_payment' => 'pending',
            'canceled', 'cancelled' => 'canceled',
            'refunded' => 'refunded',
            'in_protest', 'infraction' => 'chargeback',
            'expired' => 'expired',
            'failed', 'refused', 'declined' => 'failed',
            'processing' => 'processing',
            default => 'pending',
        };
    }

    /**
     * Map BluPay transfer status to internal status
     */
    private function mapTransferStatus(string $status): string
    {
        return match (strtolower($status)) {
            'paid', 'completed' => 'paid',
            'processing', 'pending' => 'pending',
            'failed' => 'failed',
            'canceled', 'cancelled' => 'canceled',
            default => 'pending',
        };
    }
}
