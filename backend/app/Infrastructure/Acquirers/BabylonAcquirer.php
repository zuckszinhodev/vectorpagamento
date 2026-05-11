<?php

namespace App\Infrastructure\Acquirers;

use App\Domain\Acquirer\Contracts\AcquirerInterface;
use App\Models\Charge;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Banco Babylon Acquirer Implementation
 * 
 * API Documentation: https://bancobabylon.readme.io/reference/introduÃ§Ã£o
 * 
 * This class implements the simplified AcquirerInterface.
 * For full gateway functionality, use BabylonGateway instead.
 */
class BabylonAcquirer implements AcquirerInterface
{
    private string $secretKey;
    private string $companyId;
    private string $baseUrl = 'https://api.bancobabylon.com/functions/v1';

    public function __construct(array $credentials)
    {
        $this->secretKey = $credentials['secret_key'] ?? '';
        $this->companyId = $credentials['company_id'] ?? '';
    }

    /**
     * Get acquirer name
     */
    public function getName(): string
    {
        return 'Banco Babylon';
    }

    /**
     * Get authorization header
     */
    private function getAuthHeader(): string
    {
        return base64_encode($this->secretKey . ':' . $this->companyId);
    }

    /**
     * Get HTTP client with proper SSL configuration
     */
    private function getHttpClient()
    {
        $client = Http::withHeaders([
            'Authorization' => 'Basic ' . $this->getAuthHeader(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(30);

        // Disable SSL verification in local/development environment only
        if (config('app.env') === 'local') {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * Process card payment
     */
    public function processPayment(Charge $charge, array $paymentData): array
    {
        try {
            $payload = $this->buildCardPayload($charge, $paymentData);

            $response = $this->getHttpClient()->post("{$this->baseUrl}/transactions", $payload);

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
                'external_id' => $data['id'],
                'status' => $this->mapStatus($data['status']),
                'authorization_code' => $data['authorizationCode'] ?? $data['id'],
                'nsu' => $data['nsu'] ?? $data['id'],
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('Babylon payment error', [
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
            $response = $this->getHttpClient()->delete("{$this->baseUrl}/transactions/{$charge->external_id}", [
                'amount' => (int)($amount * 100), // Convert to cents
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
                'external_id' => $data['id'],
                'amount' => $data['refundedAmount'] ?? $amount,
                'status' => 'refunded',
            ];
        } catch (\Exception $e) {
            Log::error('Babylon refund error', [
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
            $payload = [
                'paymentMethod' => 'PIX',
                'amount' => (int)($charge->amount * 100), // Convert to cents
                'description' => $charge->description ?? 'PIX Payment',
                'customer' => [
                    'name' => $charge->customer_name,
                    'email' => $charge->customer_email,
                    'document' => $charge->customer_document,
                    'phone' => $charge->customer_phone ?? null,
                ],
                'postbackUrl' => config('app.url') . '/api/v1/webhooks/babylon',
                'metadata' => [
                    'charge_id' => $charge->id,
                ],
            ];

            // Add items if available
            if (isset($charge->items) && is_array($charge->items)) {
                $payload['items'] = array_map(fn($item) => [
                    'title' => $item['name'] ?? $item['title'] ?? 'Product',
                    'unitPrice' => (int)(($item['price'] ?? $item['unit_price'] ?? 0) * 100),
                    'quantity' => $item['quantity'] ?? 1,
                    'externalRef' => $item['external_ref'] ?? null,
                ], $charge->items);
            }

            // Add shipping if available
            if (isset($charge->address) && is_array($charge->address)) {
                $payload['shipping'] = [
                    'street' => $charge->address['street'] ?? '',
                    'streetNumber' => $charge->address['number'] ?? '',
                    'neighborhood' => $charge->address['neighborhood'] ?? '',
                    'city' => $charge->address['city'] ?? '',
                    'state' => $charge->address['state'] ?? '',
                    'zipCode' => $charge->address['zip_code'] ?? '',
                    'complement' => $charge->address['complement'] ?? '',
                ];
            }

            Log::info('Babylon PIX request', ['charge_id' => $charge->id, 'amount' => $payload['amount'] ?? null]);

            $response = $this->getHttpClient()->post("{$this->baseUrl}/transactions", $payload);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'PIX generation failed',
                    'raw_response' => $response->json(),
                ];
            }

            $data = $response->json();
            $pixData = $data['pix'] ?? [];

            Log::info('Babylon PIX response', ['charge_id' => $charge->id, 'status' => $data['status'] ?? null, 'external_id' => $data['id'] ?? null]);

            return [
                'success' => true,
                'external_id' => $data['id'],
                'pix_qr_code' => $pixData['qrcode'] ?? null,
                'pix_qr_code_url' => $pixData['qrcode'] ?? null, // Babylon returns qrcode as the copy-paste string
                'pix_expires_at' => $pixData['expirationDate'] ?? null,
                'end2end_id' => $pixData['end2EndId'] ?? null,
                'status' => $this->mapStatus($data['status'] ?? 'waiting_payment'),
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('Babylon PIX error', [
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
            $payload = [
                'paymentMethod' => 'BOLETO',
                'amount' => (int)($charge->amount * 100),
                'description' => $charge->description ?? 'Boleto Payment',
                'customer' => [
                    'name' => $charge->customer_name,
                    'email' => $charge->customer_email,
                    'document' => $charge->customer_document,
                    'phone' => $charge->customer_phone ?? null,
                ],
                'postbackUrl' => config('app.url') . '/api/v1/webhooks/babylon',
                'metadata' => [
                    'charge_id' => $charge->id,
                ],
            ];

            // Add shipping/billing address if available
            if (isset($charge->address) && is_array($charge->address)) {
                $payload['shipping'] = [
                    'street' => $charge->address['street'] ?? '',
                    'streetNumber' => $charge->address['number'] ?? '',
                    'neighborhood' => $charge->address['neighborhood'] ?? '',
                    'city' => $charge->address['city'] ?? '',
                    'state' => $charge->address['state'] ?? '',
                    'zipCode' => $charge->address['zip_code'] ?? '',
                    'complement' => $charge->address['complement'] ?? '',
                ];
            }

            $response = $this->getHttpClient()->post("{$this->baseUrl}/transactions", $payload);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Boleto generation failed',
                ];
            }

            $data = $response->json();
            $boletoData = $data['boleto'] ?? [];

            return [
                'success' => true,
                'external_id' => $data['id'],
                'boleto_url' => $boletoData['url'] ?? null,
                'boleto_barcode' => $boletoData['barcode'] ?? null,
                'boleto_digitable_line' => $boletoData['digitableLine'] ?? null,
                'boleto_expires_at' => $boletoData['expirationDate'] ?? null,
                'status' => $this->mapStatus($data['status'] ?? 'waiting_payment'),
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('Babylon Boleto error', [
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
            $response = $this->getHttpClient()->get("{$this->baseUrl}/transactions/{$transactionId}");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Transaction not found',
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'transaction' => $data,
                'status' => $this->mapStatus($data['status'] ?? 'unknown'),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * List transactions
     */
    public function listTransactions(array $filters = []): array
    {
        try {
            $response = $this->getHttpClient()->get("{$this->baseUrl}/transactions", $filters);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Failed to list transactions',
                ];
            }

            return [
                'success' => true,
                'transactions' => $response->json(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Cancel transaction
     */
    public function cancelTransaction(string $transactionId): array
    {
        try {
            $response = $this->getHttpClient()->delete("{$this->baseUrl}/transactions/{$transactionId}");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Failed to cancel transaction',
                ];
            }

            return [
                'success' => true,
                'transaction' => $response->json(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update delivery status
     */
    public function updateDeliveryStatus(string $transactionId, array $deliveryData): array
    {
        try {
            $response = $this->getHttpClient()->put("{$this->baseUrl}/transactions/{$transactionId}/delivery", $deliveryData);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Failed to update delivery status',
                ];
            }

            return [
                'success' => true,
                'transaction' => $response->json(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create withdrawal (saque) - PIX OUT
     * 
     * API: POST https://api.bancobabylon.com/functions/v1/withdrawals/cashout
     * 
     * @param array $withdrawalData Must contain:
     *   - pix_key: PIX key value
     *   - pix_key_type: cpf, cnpj, email, phone, evp
     *   - amount_cents: Amount in cents
     *   - description: Withdrawal description
     *   - idempotency_key: Unique key for idempotency
     * 
     * @return array ['success' => bool, 'transfer_id' => string, 'status' => string, 'error' => string]
     */
    public function createWithdrawal(array $withdrawalData): array
    {
        try {
            $amountCents = $withdrawalData['amount_cents'] ?? (int)($withdrawalData['amount'] * 100);
            $pixKeyType = strtolower($withdrawalData['pix_key_type'] ?? $withdrawalData['pix_type'] ?? 'cpf');
            
            $payload = [
                'pixkeytype' => $pixKeyType,
                'pixkey' => $withdrawalData['pix_key'] ?? '',
                'requestedamount' => $amountCents,
                'description' => $withdrawalData['description'] ?? 'Saque Vector Pagamentos',
                'isPix' => true,
                'postbackUrl' => config('app.url') . '/api/v1/webhooks/babylon/withdrawal',
            ];

            $headers = [
                'Idempotency-Key' => $withdrawalData['idempotency_key'] ?? \Illuminate\Support\Str::uuid()->toString(),
            ];

            Log::info('Babylon withdrawal request', [
                'amount_cents' => $amountCents,
                'pix_key_type' => $pixKeyType,
                'pix_key' => substr($withdrawalData['pix_key'] ?? '', 0, 5) . '***',
            ]);

            $response = $this->getHttpClient()
                ->withHeaders($headers)
                ->post("{$this->baseUrl}/withdrawals/cashout", $payload);

            if (!$response->successful()) {
                $errorData = $response->json();
                $errorMessage = $errorData['message'] ?? $errorData['error'] ?? 'Withdrawal failed';
                
                Log::error('Babylon withdrawal failed', [
                    'status' => $response->status(),
                    'error' => $errorMessage,
                    'response' => $errorData,
                ]);

                return [
                    'success' => false,
                    'error' => $errorMessage,
                    'raw_response' => $errorData,
                ];
            }

            $data = $response->json();

            Log::info('Babylon withdrawal created', [
                'withdrawal_id' => $data['id'] ?? null,
                'status' => $data['status'] ?? null,
                'amount' => $data['requested_amount'] ?? null,
            ]);

            return [
                'success' => true,
                'transfer_id' => $data['id'] ?? null,
                'withdrawal_id' => $data['id'] ?? null,
                'amount' => ($data['requested_amount'] ?? $amountCents) / 100,
                'net_amount' => ($data['net_amount'] ?? $amountCents) / 100,
                'fee' => ($data['fee'] ?? 0) / 100,
                'status' => $this->mapWithdrawalStatus($data['status'] ?? 'pending'),
                'pix_key' => $data['pix']['key_value'] ?? null,
                'pix_key_type' => $data['pix']['key_type'] ?? null,
                'created_at' => $data['created_at'] ?? null,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('Babylon withdrawal exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
     * Map Babylon withdrawal status to internal status
     */
    private function mapWithdrawalStatus(string $status): string
    {
        return match (strtolower($status)) {
            'done', 'done_manual', 'completed' => 'completed',
            'approved', 'processing' => 'processing',
            'pending' => 'pending',
            'failed', 'refused' => 'failed',
            'cancelled' => 'cancelled',
            default => 'pending',
        };
    }

    /**
     * Build card payment payload
     */
    private function buildCardPayload(Charge $charge, array $paymentData): array
    {
        $payload = [
            'paymentMethod' => 'CREDIT_CARD',
            'amount' => (int)($charge->amount * 100),
            'installments' => $paymentData['installments'] ?? 1,
            'description' => $charge->description ?? 'Card Payment',
            'customer' => [
                'name' => $charge->customer_name,
                'email' => $charge->customer_email,
                'document' => $charge->customer_document,
                'phone' => $charge->customer_phone ?? null,
            ],
            'postbackUrl' => config('app.url') . '/api/v1/webhooks/babylon',
            'metadata' => [
                'charge_id' => $charge->id,
            ],
        ];

        // Use token_id if available (from frontend tokenization)
        if (isset($paymentData['token_id'])) {
            $payload['card'] = [
                'token_id' => $paymentData['token_id'],
            ];
        } else {
            // Use full card data
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
     * Map Babylon status to internal status
     */
    private function mapStatus(string $status): string
    {
        return match ($status) {
            'paid', 'approved', 'authorized' => 'paid',
            'waiting_payment' => 'pending',
            'pending', 'in_analysis' => 'pending',
            'processing' => 'processing',
            'refused', 'declined' => 'refused',
            'canceled', 'cancelled' => 'canceled',
            'refunded' => 'refunded',
            'chargedback' => 'chargedback',
            'failed', 'error' => 'failed',
            'expired' => 'expired',
            'in_protest' => 'in_protest',
            default => 'pending',
        };
    }
}
