<?php

declare(strict_types=1);

namespace Infrastructure\Acquirers\Babylon;

use Domain\Acquirer\Contracts\AcquirerGatewayInterface;
use Domain\Acquirer\ValueObjects\AcquirerResponse;
use Domain\Payment\Entities\Charge;
use Domain\Payment\ValueObjects\CardData;
use Domain\Shared\ValueObjects\Money;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Banco Babylon Payment Gateway Integration
 * 
 * API Documentation: https://bancobabylon.readme.io/reference/introdução
 * 
 * Authentication: Basic Auth with Secret Key as username and Company ID as password
 */
class BabylonGateway implements AcquirerGatewayInterface
{
    private ?Client $client = null;
    private array $credentials = [];
    private bool $available = true;
    private string $baseUrl = 'https://api.bancobabylon.com/functions/v1';

    public function __construct()
    {
        if (config('acquirers.babylon.secret_key') && config('acquirers.babylon.company_id')) {
            $this->credentials = [
                'secret_key' => config('acquirers.babylon.secret_key'),
                'company_id' => config('acquirers.babylon.company_id'),
                'public_key' => config('acquirers.babylon.public_key'),
                'webhook_secret' => config('acquirers.babylon.webhook_secret'),
            ];
            $this->initClient();
        }
    }

    public function setCredentials(array $credentials): void
    {
        $this->credentials = $credentials;
        $this->initClient();
    }

    private function initClient(): void
    {
        if (!empty($this->credentials['secret_key']) && !empty($this->credentials['company_id'])) {
            $authString = base64_encode(
                $this->credentials['secret_key'] . ':' . $this->credentials['company_id']
            );

            // Disable SSL verification in local/development environment only
            $verifySSL = config('app.env') !== 'local';

            $this->client = new Client([
                'base_uri' => $this->baseUrl,
                'headers' => [
                    'Authorization' => 'Basic ' . $authString,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'timeout' => 30,
                'verify' => $verifySSL,
            ]);
            $this->available = true;
        }
    }

    public function getIdentifier(): string
    {
        return 'babylon';
    }

    public function getName(): string
    {
        return 'Banco Babylon';
    }

    public function isAvailable(): bool
    {
        return $this->available && $this->client !== null;
    }

    public function getSupportedMethods(): array
    {
        return ['credit_card', 'pix', 'boleto'];
    }

    /**
     * Process credit card payment
     * 
     * Can use either token_id (from frontend tokenization) or full card data
     */
    public function processCard(
        Charge $charge,
        CardData $cardData,
        int $installments = 1
    ): AcquirerResponse {
        try {
            $customer = $charge->getCustomer();
            $billing = $charge->getBilling() ?? [];
            $items = $charge->getItems() ?? [];

            $payload = [
                'paymentMethod' => 'CREDIT_CARD',
                'amount' => $charge->getAmount()->getAmount(), // Amount in cents
                'installments' => $installments,
                'description' => 'Payment ' . $charge->getId()->toString(),
                'customer' => [
                    'name' => $customer['name'],
                    'email' => $customer['email'],
                    'phone' => $customer['phone'] ?? null,
                    'document' => $customer['document'] ?? null,
                ],
                'card' => [
                    'number' => $cardData->getNumber(),
                    'holderName' => $cardData->getHolderName(),
                    'expMonth' => (int) $cardData->getExpirationMonth(),
                    'expYear' => (int) $cardData->getExpirationYear(),
                    'cvv' => $cardData->getCvv(),
                ],
                'postbackUrl' => config('app.url') . '/api/v1/webhooks/babylon',
                'metadata' => [
                    'charge_id' => $charge->getId()->toString(),
                ],
            ];

            // Add shipping address if available
            if (!empty($billing)) {
                $payload['shipping'] = [
                    'street' => $billing['street'] ?? '',
                    'streetNumber' => $billing['number'] ?? '',
                    'neighborhood' => $billing['neighborhood'] ?? '',
                    'city' => $billing['city'] ?? '',
                    'state' => $billing['state'] ?? '',
                    'zipCode' => $billing['zip_code'] ?? '',
                    'complement' => $billing['complement'] ?? '',
                ];
            }

            // Add items if available
            if (!empty($items)) {
                $payload['items'] = array_map(fn($item) => [
                    'title' => $item['name'] ?? $item['title'] ?? 'Product',
                    'unitPrice' => $item['price'] ?? $item['unit_price'] ?? 0,
                    'quantity' => $item['quantity'] ?? 1,
                    'externalRef' => $item['external_ref'] ?? null,
                ], $items);
            }

            $response = $this->client->post('/transactions', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $this->parseTransactionResponse($data);

        } catch (GuzzleException $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Process credit card payment using token_id from frontend tokenization
     */
    public function processCardWithToken(
        Charge $charge,
        string $tokenId,
        int $installments = 1
    ): AcquirerResponse {
        try {
            $customer = $charge->getCustomer();

            $payload = [
                'paymentMethod' => 'CREDIT_CARD',
                'amount' => $charge->getAmount()->getAmount(),
                'installments' => $installments,
                'description' => 'Payment ' . $charge->getId()->toString(),
                'customer' => [
                    'name' => $customer['name'],
                    'email' => $customer['email'],
                    'phone' => $customer['phone'] ?? null,
                    'document' => $customer['document'] ?? null,
                ],
                'card' => [
                    'token_id' => $tokenId,
                ],
                'postbackUrl' => config('app.url') . '/api/v1/webhooks/babylon',
                'metadata' => [
                    'charge_id' => $charge->getId()->toString(),
                ],
            ];

            $response = $this->client->post('/transactions', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $this->parseTransactionResponse($data);

        } catch (GuzzleException $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Generate PIX payment
     */
    public function generatePix(Charge $charge): AcquirerResponse
    {
        try {
            $customer = $charge->getCustomer();
            $billing = $charge->getBilling() ?? [];
            $items = $charge->getItems() ?? [];

            $payload = [
                'paymentMethod' => 'PIX',
                'amount' => $charge->getAmount()->getAmount(), // Amount in cents
                'description' => $charge->getDescription() ?? 'PIX Payment',
                'customer' => [
                    'name' => $customer['name'],
                    'email' => $customer['email'],
                    'phone' => $customer['phone'] ?? null,
                    'document' => $customer['document'] ?? null,
                ],
                'postbackUrl' => config('app.url') . '/api/v1/webhooks/babylon',
                'metadata' => [
                    'charge_id' => $charge->getId()->toString(),
                ],
            ];

            // Add shipping if available
            if (!empty($billing)) {
                $payload['shipping'] = [
                    'street' => $billing['street'] ?? '',
                    'streetNumber' => $billing['number'] ?? '',
                    'neighborhood' => $billing['neighborhood'] ?? '',
                    'city' => $billing['city'] ?? '',
                    'state' => $billing['state'] ?? '',
                    'zipCode' => $billing['zip_code'] ?? '',
                    'complement' => $billing['complement'] ?? '',
                ];
            }

            // Add items if available
            if (!empty($items)) {
                $payload['items'] = array_map(fn($item) => [
                    'title' => $item['name'] ?? $item['title'] ?? 'Product',
                    'unitPrice' => $item['price'] ?? $item['unit_price'] ?? 0,
                    'quantity' => $item['quantity'] ?? 1,
                    'externalRef' => $item['external_ref'] ?? null,
                ], $items);
            }

            Log::info('Babylon PIX request', ['payload' => $payload]);

            $response = $this->client->post('/transactions', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            Log::info('Babylon PIX response', ['data' => $data]);

            // PIX response contains qrcode in pix object
            $pixData = $data['pix'] ?? [];

            return AcquirerResponse::pending(
                transactionId: $data['id'],
                status: $this->mapStatus($data['status'] ?? 'waiting_payment'),
                data: [
                    'pix_qr_code' => $pixData['qrcode'] ?? null,
                    'pix_copy_paste' => $pixData['qrcode'] ?? null, // Copy-paste code
                    'expires_at' => $pixData['expirationDate'] ?? null,
                    'end2end_id' => $pixData['end2EndId'] ?? null,
                    'receipt_url' => $pixData['receiptUrl'] ?? null,
                ],
                rawResponse: $data
            );

        } catch (GuzzleException $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Generate Boleto payment
     */
    public function generateBoleto(Charge $charge): AcquirerResponse
    {
        try {
            $customer = $charge->getCustomer();
            $billing = $charge->getBilling() ?? [];

            $payload = [
                'paymentMethod' => 'BOLETO',
                'amount' => $charge->getAmount()->getAmount(),
                'description' => $charge->getDescription() ?? 'Boleto Payment',
                'customer' => [
                    'name' => $customer['name'],
                    'email' => $customer['email'],
                    'phone' => $customer['phone'] ?? null,
                    'document' => $customer['document'] ?? null,
                ],
                'postbackUrl' => config('app.url') . '/api/v1/webhooks/babylon',
                'metadata' => [
                    'charge_id' => $charge->getId()->toString(),
                ],
            ];

            if (!empty($billing)) {
                $payload['shipping'] = [
                    'street' => $billing['street'] ?? '',
                    'streetNumber' => $billing['number'] ?? '',
                    'neighborhood' => $billing['neighborhood'] ?? '',
                    'city' => $billing['city'] ?? '',
                    'state' => $billing['state'] ?? '',
                    'zipCode' => $billing['zip_code'] ?? '',
                    'complement' => $billing['complement'] ?? '',
                ];
            }

            $response = $this->client->post('/transactions', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            $boletoData = $data['boleto'] ?? [];

            return AcquirerResponse::pending(
                transactionId: $data['id'],
                status: $this->mapStatus($data['status'] ?? 'waiting_payment'),
                data: [
                    'boleto_url' => $boletoData['url'] ?? null,
                    'boleto_barcode' => $boletoData['barcode'] ?? null,
                    'boleto_digitable_line' => $boletoData['digitableLine'] ?? null,
                    'expires_at' => $boletoData['expirationDate'] ?? null,
                ],
                rawResponse: $data
            );

        } catch (GuzzleException $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Process refund (partial or full)
     */
    public function refund(string $transactionId, Money $amount): AcquirerResponse
    {
        try {
            // Babylon uses DELETE /transactions/{id} for cancellation
            // For partial refunds, the amount needs to be specified
            $response = $this->client->delete("/transactions/{$transactionId}", [
                'json' => [
                    'amount' => $amount->getAmount(),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return AcquirerResponse::success(
                transactionId: $data['id'] ?? $transactionId,
                authorizationCode: $data['id'] ?? $transactionId,
                nsu: $data['id'] ?? $transactionId,
                status: 'refunded',
                rawResponse: $data
            );

        } catch (GuzzleException $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Capture an authorized transaction (not commonly used with PIX)
     */
    public function capture(string $transactionId, Money $amount): AcquirerResponse
    {
        // Babylon may not have explicit capture - payments are auto-captured
        return AcquirerResponse::success(
            transactionId: $transactionId,
            authorizationCode: $transactionId,
            nsu: $transactionId,
            status: 'approved'
        );
    }

    /**
     * Cancel/void a transaction
     */
    public function cancel(string $transactionId): AcquirerResponse
    {
        try {
            $response = $this->client->delete("/transactions/{$transactionId}");

            $data = json_decode($response->getBody()->getContents(), true);

            return AcquirerResponse::success(
                transactionId: $data['id'] ?? $transactionId,
                authorizationCode: $data['id'] ?? $transactionId,
                nsu: $data['id'] ?? $transactionId,
                status: 'cancelled',
                rawResponse: $data
            );

        } catch (GuzzleException $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Get transaction status
     */
    public function getStatus(string $transactionId): AcquirerResponse
    {
        try {
            $response = $this->client->get("/transactions/{$transactionId}");

            $data = json_decode($response->getBody()->getContents(), true);

            return AcquirerResponse::pending(
                transactionId: $data['id'],
                status: $this->mapStatus($data['status'] ?? 'unknown'),
                rawResponse: $data
            );

        } catch (GuzzleException $e) {
            return $this->handleException($e);
        }
    }

    /**
     * List all transactions with optional filters
     */
    public function listTransactions(array $filters = []): array
    {
        try {
            $queryParams = [];

            if (isset($filters['status'])) {
                $queryParams['status'] = $filters['status'];
            }
            if (isset($filters['payment_method'])) {
                $queryParams['paymentMethod'] = $filters['payment_method'];
            }
            if (isset($filters['start_date'])) {
                $queryParams['startDate'] = $filters['start_date'];
            }
            if (isset($filters['end_date'])) {
                $queryParams['endDate'] = $filters['end_date'];
            }
            if (isset($filters['page'])) {
                $queryParams['page'] = $filters['page'];
            }
            if (isset($filters['limit'])) {
                $queryParams['limit'] = $filters['limit'];
            }

            $response = $this->client->get('/transactions', [
                'query' => $queryParams,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => true,
                'transactions' => $data['data'] ?? $data,
                'pagination' => [
                    'total' => $data['total'] ?? count($data['data'] ?? $data),
                    'page' => $data['page'] ?? 1,
                    'per_page' => $data['perPage'] ?? $filters['limit'] ?? 20,
                ],
            ];

        } catch (GuzzleException $e) {
            Log::error('Babylon list transactions error', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update delivery status for a transaction
     */
    public function updateDeliveryStatus(string $transactionId, array $deliveryData): AcquirerResponse
    {
        try {
            $payload = [
                'status' => $deliveryData['status'] ?? 'delivered',
                'trackingCode' => $deliveryData['tracking_code'] ?? null,
                'carrier' => $deliveryData['carrier'] ?? null,
                'deliveredAt' => $deliveryData['delivered_at'] ?? null,
            ];

            $response = $this->client->put("/transactions/{$transactionId}/delivery", [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return AcquirerResponse::success(
                transactionId: $data['id'] ?? $transactionId,
                authorizationCode: $data['id'] ?? $transactionId,
                nsu: $data['id'] ?? $transactionId,
                status: 'updated',
                rawResponse: $data
            );

        } catch (GuzzleException $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Create a withdrawal (saque)
     */
    public function createWithdrawal(array $withdrawalData): AcquirerResponse
    {
        try {
            $payload = [
                'amount' => $withdrawalData['amount'], // Amount in cents
                'pixKey' => $withdrawalData['pix_key'] ?? null,
                'pixKeyType' => $withdrawalData['pix_key_type'] ?? null, // CPF, CNPJ, EMAIL, PHONE, EVP
                'bankAccount' => isset($withdrawalData['bank_account']) ? [
                    'bankCode' => $withdrawalData['bank_account']['bank_code'] ?? null,
                    'agency' => $withdrawalData['bank_account']['agency'] ?? null,
                    'accountNumber' => $withdrawalData['bank_account']['account_number'] ?? null,
                    'accountDigit' => $withdrawalData['bank_account']['account_digit'] ?? null,
                    'accountType' => $withdrawalData['bank_account']['account_type'] ?? 'checking', // checking, savings
                    'holderName' => $withdrawalData['bank_account']['holder_name'] ?? null,
                    'holderDocument' => $withdrawalData['bank_account']['holder_document'] ?? null,
                ] : null,
                'description' => $withdrawalData['description'] ?? 'Withdrawal',
                'postbackUrl' => config('app.url') . '/api/v1/webhooks/babylon',
            ];

            $response = $this->client->post('/withdrawals', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return AcquirerResponse::success(
                transactionId: $data['id'],
                authorizationCode: $data['id'],
                nsu: $data['id'],
                status: $data['status'] ?? 'pending',
                data: [
                    'withdrawal_id' => $data['id'],
                    'amount' => $data['amount'] ?? $withdrawalData['amount'],
                    'status' => $data['status'] ?? 'pending',
                    'estimated_date' => $data['estimatedDate'] ?? null,
                ],
                rawResponse: $data
            );

        } catch (GuzzleException $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Verify webhook signature
     * 
     * Babylon webhook verification should be implemented based on their specific requirements
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (empty($this->credentials['webhook_secret'])) {
            // If no webhook secret is configured, allow all webhooks (not recommended for production)
            Log::warning('Babylon webhook secret not configured');
            return true;
        }

        // Babylon may use HMAC-SHA256 for webhook verification
        $expectedSignature = hash_hmac('sha256', $payload, $this->credentials['webhook_secret']);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Calculate transaction fee
     */
    public function calculateFee(Money $amount, string $paymentMethod, int $installments = 1): Money
    {
        // Babylon fee structure (these are example values - adjust based on actual contract)
        $baseRate = match ($paymentMethod) {
            'credit_card' => 0.0399, // 3.99%
            'pix' => 0.0099, // 0.99%
            'boleto' => 0.0, // Fixed fee only
            default => 0.0399,
        };

        $fixedFee = match ($paymentMethod) {
            'pix' => 1, // R$ 0.01 example
            'boleto' => 350, // R$ 3.50
            default => 0,
        };

        // Additional installment fees for credit card
        if ($installments > 1 && $paymentMethod === 'credit_card') {
            $baseRate += ($installments - 1) * 0.0099;
        }

        $percentageFee = (int) round($amount->getAmount() * $baseRate);
        $totalFee = $percentageFee + $fixedFee;

        return Money::fromCents($totalFee, $amount->getCurrency());
    }

    /**
     * Parse transaction response from Babylon API
     */
    private function parseTransactionResponse(array $data): AcquirerResponse
    {
        $status = $data['status'] ?? 'unknown';

        // Map Babylon status to our internal status
        $mappedStatus = $this->mapStatus($status);

        if (in_array($status, ['paid', 'approved', 'authorized'])) {
            return AcquirerResponse::success(
                transactionId: $data['id'],
                authorizationCode: $data['authorizationCode'] ?? $data['id'],
                nsu: $data['nsu'] ?? $data['id'],
                status: $mappedStatus,
                data: [
                    'card_brand' => $data['card']['brand'] ?? null,
                    'card_last_four' => $data['card']['lastFourDigits'] ?? null,
                ],
                rawResponse: $data
            );
        }

        if (in_array($status, ['waiting_payment', 'pending', 'in_analysis', 'processing'])) {
            return AcquirerResponse::pending(
                transactionId: $data['id'],
                status: $mappedStatus,
                rawResponse: $data
            );
        }

        // Failed transaction
        return AcquirerResponse::failure(
            errorCode: $data['refusedReason'] ?? $status,
            errorMessage: $data['refusedReason'] ?? 'Transaction declined',
            status: $mappedStatus,
            transactionId: $data['id'] ?? null,
            rawResponse: $data
        );
    }

    /**
     * Map Babylon status to internal status
     * 
     * Babylon statuses:
     * - waiting_payment: Aguardando pagamento
     * - paid: Pago
     * - refused: Recusado
     * - canceled: Cancelado
     * - refunded: Estornado
     * - chargedback: Chargeback
     * - failed: Falhou
     * - expired: Expirado
     * - in_analysis: Em análise
     * - in_protest: Em protesto
     */
    private function mapStatus(string $status): string
    {
        return match ($status) {
            'paid', 'approved', 'authorized' => 'approved',
            'waiting_payment' => 'waiting_payment',
            'pending', 'in_analysis' => 'pending',
            'processing' => 'processing',
            'refused', 'declined' => 'declined',
            'canceled', 'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'chargedback' => 'chargedback',
            'failed', 'error' => 'failed',
            'expired' => 'expired',
            'in_protest' => 'in_protest',
            default => 'pending',
        };
    }

    /**
     * Handle Guzzle exceptions
     */
    private function handleException(GuzzleException $e): AcquirerResponse
    {
        $response = method_exists($e, 'getResponse') ? $e->getResponse() : null;
        $body = [];

        if ($response) {
            try {
                $body = json_decode($response->getBody()->getContents(), true) ?? [];
            } catch (\Exception $ex) {
                // Ignore JSON parse errors
            }
        }

        Log::error('Babylon API error', [
            'message' => $e->getMessage(),
            'response' => $body,
        ]);

        // Check for 401 Unauthorized
        if ($response && $response->getStatusCode() === 401) {
            return AcquirerResponse::failure(
                errorCode: 'unauthorized',
                errorMessage: 'Invalid credentials - check Secret Key and Company ID',
                status: 'failed',
                rawResponse: $body
            );
        }

        return AcquirerResponse::failure(
            errorCode: $body['error'] ?? $body['code'] ?? 'babylon_error',
            errorMessage: $body['message'] ?? $e->getMessage(),
            status: 'failed',
            rawResponse: $body
        );
    }

    /**
     * Get the public key for frontend tokenization
     */
    public function getPublicKey(): ?string
    {
        return $this->credentials['public_key'] ?? null;
    }
}
