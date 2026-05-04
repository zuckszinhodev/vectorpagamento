<?php

namespace App\Infrastructure\Acquirers;

use App\Domain\Acquirer\Contracts\AcquirerInterface;
use App\Models\Charge;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AscentPay Acquirer Implementation
 * 
 * API Documentation: https://docs.ascentpayip.com
 * 
 * Authentication: Basic Auth — base64(publicKey:secretKey)
 * Base URL: https://api.ascentpayip.com
 * Amounts: in cents (centavos)
 * 
 * Endpoints:
 *   Create Transaction: POST /v1/payment-transaction/create
 *   Get Transaction:    GET  /v1/payment-transaction/info/{id}
 *   Refund:             POST /v1/payment-transaction/{id}/refund
 *   Withdrawal:         POST /v1/wallet-transaction/create/withdrawal
 *   Balance:            GET  /v1/dashboard/balance
 */
class AscentPayAcquirer implements AcquirerInterface
{
    private string $secretKey;
    private string $publicKey;
    private string $companyId;
    private string $baseUrl;
    private string $webhookSecret;
    private string $webhookUrl;

    public function __construct(array $credentials)
    {
        $this->secretKey = $credentials['secret_key'] ?? '';
        $this->publicKey = $credentials['public_key'] ?? '';
        $this->companyId = $credentials['company_id'] ?? '';
        $this->baseUrl = $credentials['base_url'] ?? 'https://api.ascentpayip.com';
        $this->webhookSecret = $credentials['webhook_secret'] ?? '';
        $this->webhookUrl = $credentials['webhook_url'] ?? '';
    }

    /**
     * Get the postback URL for webhooks
     */
    private function getPostbackUrl(): string
    {
        if (!empty($this->webhookUrl)) {
            return $this->webhookUrl;
        }

        $configUrl = config('acquirers.ascentpay.webhook_url');
        if (!empty($configUrl)) {
            return $configUrl;
        }

        return rtrim(config('app.url', ''), '/') . '/api/v1/webhooks/ascentpay';
    }

    /**
     * Get acquirer name
     */
    public function getName(): string
    {
        return 'AscentPay';
    }

    /**
     * Get authorization header (Basic Auth)
     * Format: base64(public_key:secret_key)
     */
    private function getAuthHeader(): string
    {
        return base64_encode($this->publicKey . ':' . $this->secretKey);
    }

    /**
     * Get HTTP client with proper configuration
     */
    private function getHttpClient()
    {
        $client = Http::withHeaders([
            'Authorization' => 'Basic ' . $this->getAuthHeader(),
            'Accept' => 'application/json',
        ])->asJson()->timeout(30);

        // Disable SSL verification in local/development environment only
        if (config('app.env') === 'local') {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * Build customer data for the API payload.
     * AscentPay expects document as an object: {type: "cpf"|"cnpj", number: "..."}
     */
    private function buildCustomerData(array $customer): array
    {
        $documentNumber = preg_replace('/\D/', '', $customer['document'] ?? '');
        $documentType = strlen($documentNumber) <= 11 ? 'cpf' : 'cnpj';

        $phone = preg_replace('/\D/', '', $customer['phone'] ?? '');
        if (empty($phone)) {
            $phone = '11999999999'; // Default phone (required by AscentPay)
        }

        return [
            'name' => $customer['name'] ?? 'Cliente',
            'email' => $customer['email'] ?? 'cliente@email.com',
            'phone' => $phone,
            'document' => [
                'type' => $documentType,
                'number' => $documentNumber,
            ],
        ];
    }

    /**
     * Build split rules for the API payload from charge metadata.
     * Returns null if no split rules are defined.
     */
    private function buildSplitRules(Charge $charge): ?array
    {
        $metadata = $charge->metadata ?? [];
        $splitRules = $metadata['split_rules'] ?? null;

        if (empty($splitRules) || !is_array($splitRules)) {
            return null;
        }

        $rules = [];
        foreach ($splitRules as $rule) {
            $splitRule = [
                'recipientId' => $rule['recipient_id'] ?? $rule['recipientId'] ?? '',
                'chargeProcessingFee' => $rule['charge_processing_fee'] ?? $rule['chargeProcessingFee'] ?? false,
            ];

            $type = $rule['type'] ?? 'percentage';
            if ($type === 'percentage') {
                $splitRule['percentage'] = (float) ($rule['percentage'] ?? $rule['value'] ?? 0);
            } else {
                // fixed / flat — amount in cents
                $splitRule['amount'] = (int) ($rule['amount'] ?? $rule['value'] ?? 0);
            }

            $rules[] = $splitRule;
        }

        return $rules;
    }

    /**
     * Process card payment
     */
    public function processPayment(Charge $charge, array $paymentData): array
    {
        try {
            $payload = ['request' => $this->buildCardPayload($charge, $paymentData)];

            $response = $this->getHttpClient()->post("{$this->baseUrl}/v1/payment-transaction/create", $payload);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Payment failed',
                    'raw_response' => $response->json(),
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'external_id' => $data['id'] ?? $data['data']['id'] ?? null,
                'status' => $this->mapStatus($data['status'] ?? $data['data']['status'] ?? 'waiting_payment'),
                'authorization_code' => $data['id'] ?? $data['data']['id'] ?? null,
                'nsu' => $data['id'] ?? $data['data']['id'] ?? null,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('AscentPay payment error', [
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
     * Process refund
     */
    public function refund(Charge $charge, float $amount): array
    {
        try {
            $transactionId = $charge->acquirer_transaction_id ?? $charge->external_id;

            $response = $this->getHttpClient()->post("{$this->baseUrl}/v1/payment-transaction/{$transactionId}/refund", [
                'request' => [
                    'amount' => (int) $amount, // Already in cents (centavos)
                ],
            ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Refund failed',
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'external_id' => $data['id'] ?? $transactionId,
                'amount' => $amount,
                'status' => 'refunded',
            ];
        } catch (\Exception $e) {
            Log::error('AscentPay refund error', [
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
     * Generate PIX QR Code
     */
    public function generatePixQrCode(Charge $charge): array
    {
        try {
            $customer = $charge->customer ?? [];
            $description = $charge->description ?? 'Pagamento via PIX';

            $metadata = [
                'charge_id' => (string) $charge->id,
                'account_id' => (string) ($charge->account_id ?? ''),
            ];

            $inner = [
                'amount' => (int) $charge->amount, // Already in cents (centavos)
                'paymentMethod' => 'pix',
                'postbackUrl' => $this->getPostbackUrl(),
                'externalRef' => (string) $charge->id,
                'customer' => $this->buildCustomerData($customer),
                'items' => [
                    [
                        'title' => $description,
                        'unitPrice' => (int) $charge->amount,
                        'quantity' => 1,
                        'tangible' => false,
                    ],
                ],
                'metadata' => $metadata,
            ];

            // Include split rules if provided in charge metadata
            $splitRules = $this->buildSplitRules($charge);
            if ($splitRules) {
                $inner['splitRules'] = $splitRules;
            }

            if (!empty($this->webhookSecret)) {
                $inner['webhookSecret'] = $this->webhookSecret;
            }

            $payload = ['request' => $inner];

            Log::info('AscentPay PIX request', [
                'charge_id' => $charge->id,
                'amount' => $inner['amount'] ?? null,
                'base_url' => $this->baseUrl,
            ]);

            $response = $this->getHttpClient()->post("{$this->baseUrl}/v1/payment-transaction/create", $payload);

            if (!$response->successful()) {
                Log::error('AscentPay PIX HTTP error', [
                    'status_code' => $response->status(),
                    'response' => $response->json(),
                    'base_url' => $this->baseUrl,
                    'auth_header_preview' => 'Basic ' . substr($this->getAuthHeader(), 0, 12) . '...',
                ]);
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? $response->json()['error'] ?? 'PIX generation failed',
                    'raw_response' => $response->json(),
                ];
            }

            $data = $response->json();
            $txData = $data['data'] ?? $data;
            $pixData = $txData['pix'] ?? [];
            $feeData = $txData['fee'] ?? [];

            Log::info('AscentPay PIX response', [
                'data' => $data,
                'transaction_id' => $txData['id'] ?? null,
                'status' => $txData['status'] ?? null,
                'has_qrcode' => !empty($pixData['qrcode']),
            ]);

            return [
                'success' => true,
                'external_id' => $txData['id'] ?? null,
                'pix_qr_code' => $pixData['qrcode'] ?? $pixData['qr_code'] ?? null,
                'pix_qr_code_url' => $txData['secureUrl'] ?? $pixData['qrcode_url'] ?? $pixData['qrcode'] ?? null,
                'pix_expires_at' => $pixData['expiresAt'] ?? $pixData['expiration_date'] ?? null,
                'end2end_id' => $pixData['end2EndId'] ?? $pixData['end2end_id'] ?? null,
                'status' => $this->mapStatus($txData['status'] ?? 'waiting_payment'),
                'fees' => $feeData,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('AscentPay PIX error', [
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
     */
    public function generateBoleto(Charge $charge): array
    {
        try {
            $customer = $charge->customer ?? [];
            $description = $charge->description ?? 'Pagamento via Boleto';

            $metadata = [
                'charge_id' => (string) $charge->id,
                'account_id' => (string) ($charge->account_id ?? ''),
            ];

            $inner = [
                'amount' => (int) $charge->amount, // Already in cents (centavos)
                'paymentMethod' => 'boleto',
                'postbackUrl' => $this->getPostbackUrl(),
                'externalRef' => (string) $charge->id,
                'customer' => $this->buildCustomerData($customer),
                'items' => [
                    [
                        'title' => $description,
                        'unitPrice' => (int) $charge->amount,
                        'quantity' => 1,
                        'tangible' => false,
                    ],
                ],
                'metadata' => $metadata,
            ];

            // Include split rules if provided in charge metadata
            $splitRules = $this->buildSplitRules($charge);
            if ($splitRules) {
                $inner['splitRules'] = $splitRules;
            }

            if (!empty($this->webhookSecret)) {
                $inner['webhookSecret'] = $this->webhookSecret;
            }

            $payload = ['request' => $inner];

            $response = $this->getHttpClient()->post("{$this->baseUrl}/v1/payment-transaction/create", $payload);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Boleto generation failed',
                ];
            }

            $data = $response->json();
            $txData = $data['data'] ?? $data;
            $boletoData = $txData['boleto'] ?? [];

            return [
                'success' => true,
                'external_id' => $txData['id'] ?? null,
                'boleto_url' => $boletoData['url'] ?? null,
                'boleto_barcode' => $boletoData['barcode'] ?? null,
                'boleto_digitable_line' => $boletoData['digitable_line'] ?? $boletoData['digitableLine'] ?? null,
                'boleto_expires_at' => $boletoData['expiration_date'] ?? $boletoData['expirationDate'] ?? null,
                'status' => $this->mapStatus($txData['status'] ?? 'waiting_payment'),
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('AscentPay Boleto error', [
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
     * Get transaction by ID
     */
    public function getTransaction(string $transactionId): array
    {
        try {
            $response = $this->getHttpClient()->get("{$this->baseUrl}/v1/payment-transaction/info/{$transactionId}");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Transaction not found',
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'transaction' => $data['data'] ?? $data,
                'status' => $this->mapStatus($data['data']['status'] ?? $data['status'] ?? 'unknown'),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create withdrawal (PIX OUT / saque)
     * 
     * POST /v1/wallet-transaction/create/withdrawal
     * pix_type: cpf, cnpj, evp, phone, email
     */
    public function createWithdrawal(array $withdrawalData): array
    {
        try {
            $payload = ['request' => [
                'amount' => (int)($withdrawalData['amount'] * 100), // Convert to cents
                'pixKey' => $withdrawalData['pix_key'] ?? null,
                'pixType' => $withdrawalData['pix_key_type'] ?? $withdrawalData['pix_type'] ?? 'evp',
                'postbackUrl' => $this->getPostbackUrl(),
            ]];

            $response = $this->getHttpClient()->post("{$this->baseUrl}/v1/wallet-transaction/create/withdrawal", $payload);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Withdrawal failed',
                ];
            }

            $data = $response->json();
            $txData = $data['data'] ?? $data;

            return [
                'success' => true,
                'withdrawal_id' => $txData['id'] ?? null,
                'amount' => ($txData['amount'] ?? 0) / 100,
                'status' => $txData['status'] ?? 'pending',
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('AscentPay withdrawal error', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send PIX transfer (alias for createWithdrawal for interface compatibility)
     */
    public function sendPixTransfer(array $transferData): array
    {
        return $this->createWithdrawal($transferData);
    }

    /**
     * Get account balance
     */
    public function getBalance(): array
    {
        try {
            $response = $this->getHttpClient()->get("{$this->baseUrl}/v1/dashboard/balance");

            if (!$response->successful()) {
                $body = $response->json() ?? [];
                return [
                    'success' => false,
                    'error' => $body['message'] ?? $body['error'] ?? 'Failed to get balance (HTTP ' . $response->status() . ')',
                    'status_code' => $response->status(),
                    'raw_response' => $body,
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'balance' => $data,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => 0,
                'raw_response' => null,
            ];
        }
    }

    /**
     * Test authentication against the API (for debugging)
     * Returns detailed diagnostic information about the connection
     */
    public function testAuth(): array
    {
        $diagnostics = [
            'base_url' => $this->baseUrl,
            'has_secret_key' => !empty($this->secretKey),
            'secret_key_length' => strlen($this->secretKey),
            'secret_key_preview' => strlen($this->secretKey) > 8
                ? substr($this->secretKey, 0, 6) . '...' . substr($this->secretKey, -4)
                : '(too short: ' . strlen($this->secretKey) . ' chars)',
            'has_company_id' => !empty($this->companyId),
            'company_id_length' => strlen($this->companyId),
            'company_id_preview' => strlen($this->companyId) > 8
                ? substr($this->companyId, 0, 6) . '...' . substr($this->companyId, -4)
                : (strlen($this->companyId) > 0 ? $this->companyId : '(empty)'),
            'auth_header_preview' => 'Basic ' . substr($this->getAuthHeader(), 0, 16) . '...',
        ];

        try {
            // Try balance endpoint first
            $response = $this->getHttpClient()->get("{$this->baseUrl}/v1/dashboard/balance");
            $body = $response->json() ?? [];

            $diagnostics['http_status'] = $response->status();
            $diagnostics['response_body'] = $body;

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Authentication successful',
                    'diagnostics' => $diagnostics,
                ];
            }

            return [
                'success' => false,
                'error' => $body['message'] ?? $body['error'] ?? 'HTTP ' . $response->status(),
                'diagnostics' => $diagnostics,
            ];
        } catch (\Exception $e) {
            $diagnostics['exception'] = $e->getMessage();
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'diagnostics' => $diagnostics,
            ];
        }
    }

    /**
     * Verify webhook signature
     */
    public static function verifyWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        $computedSignature = hash_hmac('sha256', $payload, $secret);
        return hash_equals($computedSignature, $signature);
    }

    /**
     * Build card payment payload
     */
    private function buildCardPayload(Charge $charge, array $paymentData): array
    {
        $customer = $charge->customer ?? [];
        $description = $charge->description ?? 'Pagamento via Cartão';

        $metadata = [
            'charge_id' => (string) $charge->id,
            'account_id' => (string) ($charge->account_id ?? ''),
        ];

        $payload = [
            'amount' => (int) $charge->amount, // Already in cents (centavos)
            'paymentMethod' => 'credit_card',
            'installments' => $paymentData['installments'] ?? 1,
            'postbackUrl' => $this->getPostbackUrl(),
            'externalRef' => $charge->id,
            'customer' => $this->buildCustomerData($customer),
            'items' => [
                [
                    'title' => $description,
                    'unitPrice' => (int) $charge->amount,
                    'quantity' => 1,
                    'tangible' => false,
                ],
            ],
            'metadata' => $metadata,
            'ip' => request()->ip() ?? '127.0.0.1',
        ];

        // Include split rules if provided in charge metadata
        $splitRules = $this->buildSplitRules($charge);
        if ($splitRules) {
            $payload['splitRules'] = $splitRules;
        }

        if (!empty($this->webhookSecret)) {
            $payload['webhookSecret'] = $this->webhookSecret;
        }

        // Use token_id if available
        if (isset($paymentData['token_id'])) {
            $payload['card'] = [
                'tokenId' => $paymentData['token_id'],
            ];
        } else {
            $payload['card'] = [
                'number' => $paymentData['card_number'],
                'holderName' => $paymentData['holder_name'],
                'expMonth' => (int) $paymentData['exp_month'],
                'expYear' => (int) $paymentData['exp_year'],
                'cvv' => $paymentData['cvv'],
            ];
        }

        return $payload;
    }

    /**
     * Map AscentPay status to internal status
     */
    private function mapStatus(string $status): string
    {
        return match ($status) {
            'paid', 'approved', 'authorized' => 'paid',
            'waiting_payment' => 'pending',
            'pending', 'in_analysis', 'processing' => 'pending',
            'refused', 'declined' => 'refused',
            'canceled', 'cancelled' => 'canceled',
            'refunded' => 'refunded',
            'chargedback', 'chargeback' => 'chargedback',
            'failed', 'error' => 'failed',
            'expired' => 'expired',
            default => 'pending',
        };
    }
}
