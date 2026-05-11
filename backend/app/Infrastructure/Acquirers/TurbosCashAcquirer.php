<?php

namespace App\Infrastructure\Acquirers;

use App\Domain\Acquirer\Contracts\AcquirerInterface;
use App\Models\Charge;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turbos Cash Acquirer Implementation
 * 
 * API Documentation: https://docs.turbos.cash
 * 
 * Authentication: Basic Access Auth — base64(PUBLIC_KEY:SECRET_KEY)
 * Base URL: https://api.turbos.cash/v1
 * 
 * Supported payment methods: PIX, Credit Card, Boleto
 * Supported transfer method: PIX OUT
 * 
 * Endpoints:
 *   POST   /v1/transactions           — Create sale (PIX/Card/Boleto)
 *   GET    /v1/transactions/{id}      — Find sale
 *   POST   /v1/transactions/{id}/refund — Refund sale
 *   POST   /v1/transfers              — Create PIX OUT (requires x-withdraw-key)
 *   GET    /v1/transfers/{id}         — Find transfer (requires x-withdraw-key)
 *   GET    /v1/balance/available      — Get available balance
 * 
 * Transaction statuses: waiting_payment, pending, approved, refused, in_protest, refunded, paid, cancelled, chargeback
 * Transfer statuses: COMPLETED, PROCESSING, CANCELLED, REFUSED, PENDING_ANALYSIS, PENDING_QUEUE
 */
class TurbosCashAcquirer implements AcquirerInterface
{
    private const DEFAULT_PIX_EXPIRATION_MINUTES = 1440;

    private string $publicKey;
    private string $secretKey;
    private string $withdrawKey;
    private string $baseUrl;
    private string $webhookSecret;
    private string $webhookUrl;

    public function __construct(array $credentials)
    {
        $this->publicKey = $credentials['public_key'] ?? '';
        $this->secretKey = $credentials['secret_key'] ?? '';
        $this->withdrawKey = $credentials['withdraw_key'] ?? '';
        $this->baseUrl = rtrim($credentials['base_url'] ?? 'https://api.turbos.cash', '/');
        $this->webhookSecret = $credentials['webhook_secret'] ?? '';
        $this->webhookUrl = rtrim($credentials['webhook_url'] ?? config('app.url'), '/');
    }

    /**
     * Get acquirer name
     */
    public function getName(): string
    {
        return 'Turbos Cash';
    }

    /**
     * Get HTTP client with Basic Auth
     */
    private function getHttpClient(array $extraHeaders = [])
    {
        $basicAuth = base64_encode($this->publicKey . ':' . $this->secretKey);

        $headers = array_merge([
            'Authorization' => 'Basic ' . $basicAuth,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $extraHeaders);

        $client = Http::withHeaders($headers)
            ->connectTimeout(10)
            ->timeout(30);

        if (config('app.env') === 'local') {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * Process card payment via Turbos Cash
     * 
     * POST /v1/transactions
     * paymentMethod: "credit_card"
     */
    public function processPayment(Charge $charge, array $cardData): array
    {
        try {
            $customer = $charge->customer ?? [];
            $document = preg_replace('/\D/', '', $customer['document'] ?? '');

            $payload = [
                'amount' => (int) $charge->amount, // Amount already in centavos
                'paymentMethod' => 'credit_card',
                'installments' => $cardData['installments'] ?? 1,
                'card' => [
                    'number' => $cardData['number'] ?? $cardData['card_number'] ?? null,
                    'holderName' => $cardData['holder_name'] ?? $cardData['holderName'] ?? $customer['name'] ?? 'Titular',
                    'expirationDate' => $cardData['expiration_date'] ?? $cardData['expirationDate'] ?? null,
                    'cvv' => $cardData['cvv'] ?? null,
                ],
                'customer' => $this->buildCustomerPayload($customer),
                'items' => [[
                    'tangible' => false,
                    'title' => $charge->description ?? 'Pagamento',
                    'unitPrice' => (int) $charge->amount,
                    'quantity' => 1,
                ]],
                'postbackUrl' => $this->webhookUrl . '/api/v1/webhooks/turbos',
                'metadata' => json_encode([
                    'charge_id' => $charge->id ?? null,
                    'account_id' => $charge->account_id ?? null,
                ]),
            ];

            // If card token is provided, use it instead of raw card data
            if (!empty($cardData['card_hash']) || !empty($cardData['token'])) {
                $payload['card'] = [
                    'hash' => $cardData['card_hash'] ?? $cardData['token'] ?? null,
                ];
            }

            if (!empty($charge->external_id)) {
                $payload['externalRef'] = $charge->external_id;
            }

            Log::info('TurbosCash card payment request', [
                'charge_id' => $charge->id ?? null,
                'amount_cents' => $payload['amount'],
                'installments' => $payload['installments'],
            ]);

            $response = $this->getHttpClient()->post("{$this->baseUrl}/v1/transactions", $payload);

            if (!$response->successful()) {
                $error = $response->json();
                Log::error('TurbosCash card payment error', [
                    'charge_id' => $charge->id ?? null,
                    'status' => $response->status(),
                    'error' => $error,
                ]);

                return [
                    'success' => false,
                    'error' => $error['message'] ?? $error['error'] ?? 'Card payment failed',
                    'raw_response' => $error,
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'external_id' => $data['id'] ?? null,
                'status' => $this->mapTransactionStatus($data['status'] ?? 'pending'),
                'acquirer_nsu' => $data['nsu'] ?? null,
                'authorization_code' => $data['authorizationCode'] ?? null,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('TurbosCash card payment exception', [
                'charge_id' => $charge->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process refund via Turbos Cash
     * 
     * POST /v1/transactions/{id}/refund
     */
    public function refund(Charge $charge, float $amount): array
    {
        try {
            $externalId = $charge->acquirer_transaction_id ?? $charge->external_id;

            if (!$externalId) {
                return [
                    'success' => false,
                    'error' => 'No external transaction ID found for refund',
                ];
            }

            Log::info('TurbosCash refund request', [
                'charge_id' => $charge->id,
                'external_id' => $externalId,
                'amount' => $amount,
            ]);

            $response = $this->getHttpClient()->post("{$this->baseUrl}/v1/transactions/{$externalId}/refund");

            if (!$response->successful()) {
                $error = $response->json();
                Log::error('TurbosCash refund error', [
                    'charge_id' => $charge->id,
                    'status' => $response->status(),
                    'error' => $error,
                ]);

                return [
                    'success' => false,
                    'error' => $error['message'] ?? 'Refund failed',
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'refund_id' => $data['id'] ?? null,
                'status' => $this->mapTransactionStatus($data['status'] ?? 'refunded'),
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('TurbosCash refund exception', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate PIX QR Code via Turbos Cash
     * 
     * POST /v1/transactions
     * paymentMethod: "pix"
     */
    public function generatePixQrCode(Charge $charge): array
    {
        try {
            $customer = $charge->customer ?? [];

            $payload = [
                'amount' => (int) $charge->amount, // centavos
                'paymentMethod' => 'pix',
                'pix' => [
                    'expiresInMinutes' => self::DEFAULT_PIX_EXPIRATION_MINUTES,
                ],
                'customer' => $this->buildCustomerPayload($customer),
                'items' => [[
                    'tangible' => false,
                    'title' => $charge->description ?? 'Pagamento via PIX',
                    'unitPrice' => (int) $charge->amount,
                    'quantity' => 1,
                ]],
                'postbackUrl' => $this->webhookUrl . '/api/v1/webhooks/turbos',
                'metadata' => json_encode([
                    'charge_id' => $charge->id ?? null,
                    'account_id' => $charge->account_id ?? null,
                    'checkout_id' => $charge->checkout_id ?? null,
                    'product_id' => $charge->product_id ?? null,
                ]),
            ];

            if (!empty($charge->external_id)) {
                $payload['externalRef'] = $charge->external_id;
            }

            Log::info('TurbosCash PIX request', [
                'charge_id' => $charge->id ?? null,
                'amount_cents' => $payload['amount'],
            ]);

            $response = $this->getHttpClient()->post("{$this->baseUrl}/v1/transactions", $payload);

            if (!$response->successful()) {
                $error = $response->json();
                Log::error('TurbosCash PIX error', [
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
            $pixData = $data['pix'] ?? [];

            Log::info('TurbosCash PIX response', [
                'charge_id' => $charge->id ?? null,
                'transaction_id' => $data['id'] ?? null,
                'status' => $data['status'] ?? null,
            ]);

            return [
                'success' => true,
                'external_id' => $data['id'] ?? null,
                'pix_qr_code' => $pixData['qrcode'] ?? $pixData['emv'] ?? null,
                'pix_qr_code_url' => $pixData['qrcodeUrl'] ?? $pixData['qrCode'] ?? null,
                'pix_expires_at' => $data['expiresAt'] ?? $pixData['expirationDate'] ?? $pixData['expiresAt'] ?? null,
                'end2end_id' => $pixData['endToEndId'] ?? $pixData['end2EndId'] ?? null,
                'status' => $this->mapTransactionStatus($data['status'] ?? 'waiting_payment'),
                'fees' => $data['fee'] ?? $data['fees'] ?? null,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('TurbosCash PIX exception', [
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
     * Generate Boleto via Turbos Cash
     * 
     * POST /v1/transactions
     * paymentMethod: "boleto"
     */
    public function generateBoleto(Charge $charge): array
    {
        try {
            $customer = $charge->customer ?? [];

            $payload = [
                'amount' => (int) $charge->amount, // centavos
                'paymentMethod' => 'boleto',
                'boleto' => [
                    'expiresInDays' => 3,
                    'instructions' => $charge->description ?? 'Pagamento Vector Pagamentos',
                ],
                'customer' => $this->buildCustomerPayload($customer),
                'items' => [[
                    'tangible' => false,
                    'title' => $charge->description ?? 'Pagamento via Boleto',
                    'unitPrice' => (int) $charge->amount,
                    'quantity' => 1,
                ]],
                'postbackUrl' => $this->webhookUrl . '/api/v1/webhooks/turbos',
                'metadata' => json_encode([
                    'charge_id' => $charge->id ?? null,
                    'account_id' => $charge->account_id ?? null,
                ]),
            ];

            if (!empty($charge->external_id)) {
                $payload['externalRef'] = $charge->external_id;
            }

            Log::info('TurbosCash boleto request', [
                'charge_id' => $charge->id ?? null,
                'amount_cents' => $payload['amount'],
            ]);

            $response = $this->getHttpClient()->post("{$this->baseUrl}/v1/transactions", $payload);

            if (!$response->successful()) {
                $error = $response->json();
                Log::error('TurbosCash boleto error', [
                    'charge_id' => $charge->id ?? null,
                    'status' => $response->status(),
                    'error' => $error,
                ]);

                return [
                    'success' => false,
                    'error' => $error['message'] ?? 'Boleto generation failed',
                    'raw_response' => $error,
                ];
            }

            $data = $response->json();
            $boletoData = $data['boleto'] ?? [];

            return [
                'success' => true,
                'external_id' => $data['id'] ?? null,
                'boleto_url' => $boletoData['url'] ?? null,
                'boleto_barcode' => $boletoData['barcode'] ?? $boletoData['digitableLine'] ?? null,
                'boleto_expires_at' => $boletoData['expiresAt'] ?? $data['expiresAt'] ?? null,
                'status' => $this->mapTransactionStatus($data['status'] ?? 'waiting_payment'),
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('TurbosCash boleto exception', [
                'charge_id' => $charge->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send PIX transfer (PIX OUT / Withdrawal)
     * 
     * POST /v1/transfers
     * Requires x-withdraw-key header
     */
    public function sendPixTransfer(array $transferData): array
    {
        try {
            $amountCents = $transferData['amount_cents']
                ?? (int) round(($transferData['amount'] ?? 0) * 100);

            $pixKeyType = strtolower($transferData['pix_key_type'] ?? 'cpf');
            // Turbos accepts: cpf, cnpj, email, phone, evp, copypaste
            $validTypes = ['cpf', 'cnpj', 'email', 'phone', 'evp', 'copypaste'];
            if (!in_array($pixKeyType, $validTypes)) {
                $pixKeyType = 'cpf';
            }

            $payload = [
                'method' => 'pix',
                'amount' => $amountCents,
                'pixKey' => $transferData['pix_key'],
                'pixKeyType' => $pixKeyType,
                'postbackUrl' => $this->webhookUrl . '/api/v1/webhooks/turbos',
            ];

            Log::info('TurbosCash PIX OUT request', [
                'withdrawal_id' => $transferData['external_ref'] ?? null,
                'amount_cents' => $amountCents,
                'pix_key_type' => $pixKeyType,
            ]);

            $response = $this->getHttpClient([
                'x-withdraw-key' => $this->withdrawKey,
            ])->post("{$this->baseUrl}/v1/transfers", $payload);

            if (!$response->successful()) {
                $error = $response->json();
                Log::error('TurbosCash PIX OUT error', [
                    'error' => $error,
                    'status' => $response->status(),
                ]);

                return [
                    'success' => false,
                    'error' => $error['message'] ?? $error['error'] ?? 'PIX transfer failed',
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'transfer_id' => $data['id'] ?? null,
                'amount' => ($data['amount'] ?? $amountCents) / 100,
                'net_amount' => ($data['netAmount'] ?? $data['amount'] ?? $amountCents) / 100,
                'fees' => $data['fee'] ?? $data['fees'] ?? null,
                'status' => $data['status'] ?? 'PROCESSING',
                'pix_key' => $data['pixKey'] ?? $transferData['pix_key'],
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('TurbosCash PIX OUT exception', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get transaction status
     * 
     * GET /v1/transactions/{id}
     */
    public function getTransaction(string $transactionId): array
    {
        try {
            $response = $this->getHttpClient()->get("{$this->baseUrl}/v1/transactions/{$transactionId}");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Failed to get transaction',
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'status' => $data['status'] ?? 'unknown',
                'amount' => $data['amount'] ?? null,
                'paid_at' => $data['paidAt'] ?? $data['paid_at'] ?? null,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('TurbosCash getTransaction exception', [
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
     * Get transfer status
     * 
     * GET /v1/transfers/{id}
     * Requires x-withdraw-key header
     */
    public function getTransfer(string $transferId): array
    {
        try {
            $response = $this->getHttpClient([
                'x-withdraw-key' => $this->withdrawKey,
            ])->get("{$this->baseUrl}/v1/transfers/{$transferId}");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Failed to get transfer',
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'status' => $data['status'] ?? 'unknown',
                'amount' => $data['amount'] ?? null,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('TurbosCash getTransfer exception', [
                'transfer_id' => $transferId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get available balance
     * 
     * GET /v1/balance/available
     */
    public function getBalance(): array
    {
        try {
            $response = $this->getHttpClient()->get("{$this->baseUrl}/v1/balance/available");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Failed to get balance',
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'available' => $data['available'] ?? $data['amount'] ?? 0,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('TurbosCash getBalance exception', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build customer payload for Turbos Cash API
     */
    private function buildCustomerPayload(array $customer): array
    {
        $document = preg_replace('/\D/', '', $customer['document'] ?? '');
        $phone = preg_replace('/\D/', '', $customer['phone'] ?? '');

        $customerPayload = [
            'name' => $customer['name'] ?? 'Cliente',
            'email' => $customer['email'] ?? 'cliente@email.com',
            'document' => [
                'type' => strlen($document) <= 11 ? 'cpf' : 'cnpj',
                'number' => $document,
            ],
        ];

        if (!empty($phone)) {
            $customerPayload['phone'] = $phone;
        }

        // Include address if available
        if (!empty($customer['address']) || !empty($customer['street'])) {
            $address = $customer['address'] ?? $customer;
            $customerPayload['address'] = [
                'street' => $address['street'] ?? '',
                'streetNumber' => $address['number'] ?? $address['street_number'] ?? '',
                'neighborhood' => $address['neighborhood'] ?? '',
                'city' => $address['city'] ?? '',
                'state' => $address['state'] ?? '',
                'zipcode' => preg_replace('/\D/', '', $address['zipcode'] ?? $address['zip_code'] ?? ''),
                'country' => $address['country'] ?? 'br',
            ];
        }

        return $customerPayload;
    }

    /**
     * Map Turbos Cash transaction status to internal status
     * 
     * Turbos statuses: waiting_payment, pending, approved, refused, in_protest, refunded, paid, cancelled, chargeback
     */
    public function mapTransactionStatus(string $status): string
    {
        return match (strtolower($status)) {
            'paid', 'approved' => 'paid',
            'waiting_payment', 'pending' => 'waiting_payment',
            'cancelled', 'canceled' => 'canceled',
            'refunded' => 'refunded',
            'refused' => 'failed',
            'in_protest', 'chargeback' => 'chargedback',
            default => 'waiting_payment',
        };
    }

    /**
     * Map Turbos Cash transfer status to internal status
     * 
     * Turbos statuses: COMPLETED, PROCESSING, CANCELLED, REFUSED, PENDING_ANALYSIS, PENDING_QUEUE
     */
    public function mapTransferStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'COMPLETED' => 'paid',
            'PROCESSING', 'PENDING_ANALYSIS', 'PENDING_QUEUE' => 'processing',
            'CANCELLED' => 'canceled',
            'REFUSED' => 'failed',
            default => 'processing',
        };
    }

    /**
     * Verify Turbos Cash webhook signature (if applicable)
     */
    public static function verifyWebhookSignature(string $payload, string $signatureHeader, string $secret): bool
    {
        if (empty($signatureHeader) || empty($secret)) {
            return false;
        }

        // Turbos Cash uses HMAC-SHA256
        $computed = hash_hmac('sha256', $payload, $secret);
        return hash_equals($computed, $signatureHeader);
    }
}
