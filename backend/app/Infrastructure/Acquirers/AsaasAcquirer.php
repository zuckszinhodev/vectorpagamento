<?php

declare(strict_types=1);

namespace App\Infrastructure\Acquirers;

use App\Domain\Acquirer\Contracts\AcquirerInterface;
use App\Models\Charge;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Asaas Acquirer Implementation
 *
 * API Documentation: https://docs.asaas.com
 * Production URL: https://api.asaas.com/v3
 * Sandbox URL: https://api-sandbox.asaas.com/v3
 *
 * Authentication: access_token header
 * API Key Format: Production $aact_prod_*, Sandbox $aact_hmlg_*
 *
 * Supported Methods: PIX, Credit Card, Boleto
 * All amounts in REAIS (decimal, not cents)
 *
 * Split: Uses walletId + fixedValue/percentualValue in charge creation
 *
 * Webhook Events:
 *   PAYMENT_CREATED, PAYMENT_CONFIRMED, PAYMENT_RECEIVED,
 *   PAYMENT_OVERDUE, PAYMENT_DELETED, PAYMENT_REFUNDED,
 *   PAYMENT_PARTIALLY_REFUNDED, PAYMENT_CHARGEBACK_REQUESTED,
 *   PAYMENT_CHARGEBACK_DISPUTE, PAYMENT_AWAITING_CHARGEBACK_REVERSAL
 */
class AsaasAcquirer implements AcquirerInterface
{
    use Concerns\ConfigurableTimeouts;

    private string $apiKey;
    private string $baseUrl;
    private string $webhookToken;

    public function __construct(array $credentials)
    {
        $this->apiKey = $credentials['api_key'] ?? '';
        $this->webhookToken = $credentials['webhook_token'] ?? '';

        $defaultUrl = str_starts_with($this->apiKey, '$aact_prod_')
            ? 'https://api.asaas.com/v3'
            : 'https://api-sandbox.asaas.com/v3';

        $this->baseUrl = rtrim($credentials['base_url'] ?? $defaultUrl, '/');
    }

    public function getName(): string
    {
        return 'Asaas';
    }

    // ── HTTP Client ──────────────────────────────────────────────

    private function getHttpClient()
    {
        return Http::withHeaders([
            'access_token' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'Vector Pagamentos',
        ])->connectTimeout($this->httpConnectTimeout(3))->timeout($this->httpTimeout(10));
    }

    private function makeRequest(string $method, string $endpoint, array $data = []): array
    {
        $url = "{$this->baseUrl}/{$endpoint}";

        Log::info("Asaas API request: {$method} {$endpoint}", [
            'payload_keys' => array_keys($data),
        ]);

        try {
            $response = match (strtoupper($method)) {
                'GET' => $this->getHttpClient()->get($url, $data),
                'POST' => $this->getHttpClient()->post($url, $data),
                'PUT' => $this->getHttpClient()->put($url, $data),
                'DELETE' => $this->getHttpClient()->delete($url, $data),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };

            $body = $response->json() ?? [];

            if (!$response->successful()) {
                Log::error('Asaas API error', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'response' => $body,
                ]);

                return [
                    'success' => false,
                    'error' => $body['errors'][0]['description'] ?? $body['message'] ?? 'Asaas API error',
                    'error_code' => $body['errors'][0]['code'] ?? null,
                    'raw_response' => $body,
                ];
            }

            return [
                'success' => true,
                'data' => $body,
            ];
        } catch (\Exception $e) {
            Log::error('Asaas API exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // ── Customer Management ──────────────────────────────────────

    /**
     * Find or create an Asaas customer for the charge.
     * Returns the Asaas customer ID (cus_*).
     */
    private function resolveCustomerId(Charge $charge): ?string
    {
        $cpfCnpj = $charge->customer_document ?? $charge->metadata['customer_document'] ?? null;
        $email = $charge->customer_email ?? $charge->metadata['customer_email'] ?? null;
        $name = $charge->customer_name ?? $charge->metadata['customer_name'] ?? 'Cliente Vector Pagamentos';

        // Try to find existing customer by CPF/CNPJ
        if ($cpfCnpj) {
            $cleanDoc = preg_replace('/\D/', '', $cpfCnpj);
            $result = $this->makeRequest('GET', 'customers', ['cpfCnpj' => $cleanDoc]);

            if ($result['success'] && !empty($result['data']['data'])) {
                return $result['data']['data'][0]['id'];
            }
        }

        // Create new customer
        $customerData = [
            'name' => $name,
        ];

        if ($cpfCnpj) {
            $customerData['cpfCnpj'] = preg_replace('/\D/', '', $cpfCnpj);
        }

        if ($email) {
            $customerData['email'] = $email;
        }

        $phone = $charge->customer_phone ?? $charge->metadata['customer_phone'] ?? null;
        if ($phone) {
            $customerData['phone'] = preg_replace('/\D/', '', $phone);
        }

        $result = $this->makeRequest('POST', 'customers', $customerData);

        if ($result['success']) {
            return $result['data']['id'] ?? null;
        }

        Log::error('Asaas: failed to create customer', [
            'charge_id' => $charge->id,
            'error' => $result['error'] ?? 'Unknown',
        ]);

        return null;
    }

    // ── Payment Processing ───────────────────────────────────────

    public function processPayment(Charge $charge, array $cardData): array
    {
        try {
            $customerId = $this->resolveCustomerId($charge);
            if (!$customerId) {
                return [
                    'success' => false,
                    'error' => 'Failed to resolve Asaas customer',
                ];
            }

            // Amount in REAIS (Asaas uses decimal, our system uses cents)
            $amountReais = $charge->amount / 100;

            $payload = [
                'customer' => $customerId,
                'billingType' => 'CREDIT_CARD',
                'value' => $amountReais,
                'dueDate' => now()->format('Y-m-d'),
                'description' => $charge->description ?? "Cobrança #{$charge->id}",
                'externalReference' => $charge->id,
            ];

            // Add credit card data
            if (!empty($cardData['credit_card_token'])) {
                $payload['creditCardToken'] = $cardData['credit_card_token'];
            } else {
                $payload['creditCard'] = [
                    'holderName' => $cardData['holder_name'] ?? $cardData['card_holder_name'] ?? '',
                    'number' => $cardData['number'] ?? $cardData['card_number'] ?? '',
                    'expiryMonth' => $cardData['expiry_month'] ?? $cardData['exp_month'] ?? '',
                    'expiryYear' => $cardData['expiry_year'] ?? $cardData['exp_year'] ?? '',
                    'ccv' => $cardData['cvv'] ?? $cardData['cvc'] ?? $cardData['ccv'] ?? '',
                ];

                $payload['creditCardHolderInfo'] = [
                    'name' => $cardData['holder_name'] ?? $cardData['card_holder_name'] ?? $charge->customer_name ?? '',
                    'email' => $charge->customer_email ?? $cardData['email'] ?? '',
                    'cpfCnpj' => preg_replace('/\D/', '', $charge->customer_document ?? $cardData['cpf_cnpj'] ?? ''),
                    'postalCode' => $cardData['postal_code'] ?? $cardData['zip_code'] ?? '',
                    'addressNumber' => $cardData['address_number'] ?? '',
                    'phone' => preg_replace('/\D/', '', $charge->customer_phone ?? $cardData['phone'] ?? ''),
                ];
            }

            // Installments
            $installments = $cardData['installments'] ?? $cardData['installment_count'] ?? 1;
            if ($installments > 1) {
                $payload['installmentCount'] = (int) $installments;
                $payload['totalValue'] = $amountReais;
                unset($payload['value']);
            }

            // Remote IP for anti-fraud
            if (!empty($cardData['remote_ip'])) {
                $payload['remoteIp'] = $cardData['remote_ip'];
            }

            // Add split configuration
            $splits = $this->buildSplitPayload($charge);
            if (!empty($splits)) {
                $payload['split'] = $splits;
            }

            $result = $this->makeRequest('POST', 'payments', $payload);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'Card payment failed',
                    'raw_response' => $result['raw_response'] ?? null,
                ];
            }

            $data = $result['data'];

            return [
                'success' => true,
                'external_id' => $data['id'],
                'status' => $this->mapStatus($data['status'] ?? 'PENDING'),
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('Asaas processPayment exception', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // ── PIX ──────────────────────────────────────────────────────

    public function generatePixQrCode(Charge $charge): array
    {
        try {
            $customerId = $this->resolveCustomerId($charge);
            if (!$customerId) {
                return [
                    'success' => false,
                    'error' => 'Failed to resolve Asaas customer',
                ];
            }

            $amountReais = $charge->amount / 100;

            $payload = [
                'customer' => $customerId,
                'billingType' => 'PIX',
                'value' => $amountReais,
                'dueDate' => now()->format('Y-m-d'),
                'description' => $charge->description ?? "Cobrança PIX #{$charge->id}",
                'externalReference' => $charge->id,
            ];

            // Add split configuration
            $splits = $this->buildSplitPayload($charge);
            if (!empty($splits)) {
                $payload['split'] = $splits;
            }

            // Step 1: Create the payment
            $result = $this->makeRequest('POST', 'payments', $payload);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'PIX charge creation failed',
                    'raw_response' => $result['raw_response'] ?? null,
                ];
            }

            $paymentId = $result['data']['id'];
            $paymentData = $result['data'];

            // Step 2: Get the PIX QR Code
            $qrResult = $this->makeRequest('GET', "payments/{$paymentId}/pixQrCode");

            if (!$qrResult['success']) {
                Log::warning('Asaas: PIX charge created but QR code retrieval failed', [
                    'payment_id' => $paymentId,
                    'error' => $qrResult['error'],
                ]);

                return [
                    'success' => true,
                    'external_id' => $paymentId,
                    'status' => $this->mapStatus($paymentData['status'] ?? 'PENDING'),
                    'pix_qr_code' => null,
                    'pix_copy_paste' => null,
                    'data' => $paymentData,
                ];
            }

            $qrData = $qrResult['data'];

            return [
                'success' => true,
                'external_id' => $paymentId,
                'status' => $this->mapStatus($paymentData['status'] ?? 'PENDING'),
                'pix_qr_code' => $qrData['encodedImage'] ?? null,
                'pix_copy_paste' => $qrData['payload'] ?? null,
                'pix_expiration' => $qrData['expirationDate'] ?? null,
                'data' => array_merge($paymentData, ['pixQrCode' => $qrData]),
            ];
        } catch (\Exception $e) {
            Log::error('Asaas generatePixQrCode exception', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // ── Boleto ───────────────────────────────────────────────────

    public function generateBoleto(Charge $charge): array
    {
        try {
            $customerId = $this->resolveCustomerId($charge);
            if (!$customerId) {
                return [
                    'success' => false,
                    'error' => 'Failed to resolve Asaas customer',
                ];
            }

            $amountReais = $charge->amount / 100;

            $daysToExpire = $charge->metadata['boleto_days_to_expire'] ?? 3;

            $payload = [
                'customer' => $customerId,
                'billingType' => 'BOLETO',
                'value' => $amountReais,
                'dueDate' => now()->addDays((int) $daysToExpire)->format('Y-m-d'),
                'description' => $charge->description ?? "Cobrança Boleto #{$charge->id}",
                'externalReference' => $charge->id,
            ];

            // Add split configuration
            $splits = $this->buildSplitPayload($charge);
            if (!empty($splits)) {
                $payload['split'] = $splits;
            }

            // Step 1: Create payment
            $result = $this->makeRequest('POST', 'payments', $payload);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'Boleto creation failed',
                    'raw_response' => $result['raw_response'] ?? null,
                ];
            }

            $paymentId = $result['data']['id'];
            $paymentData = $result['data'];

            // Step 2: Get boleto digitable line
            $boletoResult = $this->makeRequest('GET', "payments/{$paymentId}/identificationField");

            $digitableLine = null;
            $barcode = null;

            if ($boletoResult['success']) {
                $digitableLine = $boletoResult['data']['identificationField'] ?? null;
                $barcode = $boletoResult['data']['barCode'] ?? null;
            } else {
                Log::warning('Asaas: Boleto created but digitable line retrieval failed', [
                    'payment_id' => $paymentId,
                ]);
            }

            return [
                'success' => true,
                'external_id' => $paymentId,
                'status' => $this->mapStatus($paymentData['status'] ?? 'PENDING'),
                'boleto_url' => $paymentData['bankSlipUrl'] ?? $paymentData['invoiceUrl'] ?? null,
                'boleto_barcode' => $barcode,
                'boleto_digitable_line' => $digitableLine,
                'data' => $paymentData,
            ];
        } catch (\Exception $e) {
            Log::error('Asaas generateBoleto exception', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // ── Refund ───────────────────────────────────────────────────

    public function refund(Charge $charge, float $amount): array
    {
        try {
            $externalId = $charge->external_id ?? $charge->acquirer_transaction_id;

            if (!$externalId) {
                return [
                    'success' => false,
                    'error' => 'No external payment ID found for refund',
                ];
            }

            // Amount in reais (our system stores cents)
            $amountReais = $amount / 100;

            $payload = [];

            // Partial refund: pass value; full refund: no value needed
            if ($amountReais > 0 && $amountReais < ($charge->amount / 100)) {
                $payload['value'] = $amountReais;
            }

            if (!empty($charge->metadata['refund_reason'])) {
                $payload['description'] = $charge->metadata['refund_reason'];
            }

            $result = $this->makeRequest('POST', "payments/{$externalId}/refund", $payload);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'Refund failed',
                    'raw_response' => $result['raw_response'] ?? null,
                ];
            }

            return [
                'success' => true,
                'refund_id' => $result['data']['id'] ?? $externalId,
                'status' => 'refunded',
                'data' => $result['data'],
            ];
        } catch (\Exception $e) {
            Log::error('Asaas refund exception', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // ── Split ────────────────────────────────────────────────────

    /**
     * Build Asaas split payload from charge metadata.
     *
     * Expected format in charge metadata:
     *   'asaas_splits' => [
     *       ['walletId' => 'uuid', 'fixedValue' => 20.00],
     *       ['walletId' => 'uuid', 'percentualValue' => 10.0000],
     *   ]
     */
    private function buildSplitPayload(Charge $charge): array
    {
        $splits = $charge->metadata['asaas_splits'] ?? $charge->metadata['splits'] ?? [];

        if (empty($splits)) {
            return [];
        }

        $asaasSplits = [];
        foreach ($splits as $split) {
            if (empty($split['walletId'])) {
                continue;
            }

            $entry = ['walletId' => $split['walletId']];

            if (isset($split['fixedValue'])) {
                $entry['fixedValue'] = (float) $split['fixedValue'];
            } elseif (isset($split['percentualValue'])) {
                $entry['percentualValue'] = (float) $split['percentualValue'];
            } else {
                continue;
            }

            $asaasSplits[] = $entry;
        }

        return $asaasSplits;
    }

    // ── Status Mapping ───────────────────────────────────────────

    /**
     * Map Asaas payment status to internal Charge status.
     *
     * Asaas statuses:
     *   PENDING, RECEIVED, CONFIRMED, OVERDUE, REFUNDED,
     *   RECEIVED_IN_CASH, REFUND_REQUESTED, REFUND_IN_PROGRESS,
     *   CHARGEBACK_REQUESTED, CHARGEBACK_DISPUTE,
     *   AWAITING_CHARGEBACK_REVERSAL, DUNNING_REQUESTED,
     *   DUNNING_RECEIVED, AWAITING_RISK_ANALYSIS
     */
    public static function mapStatus(string $asaasStatus): string
    {
        return match (strtoupper($asaasStatus)) {
            'PENDING', 'AWAITING_RISK_ANALYSIS' => Charge::STATUS_PENDING,
            'CONFIRMED' => Charge::STATUS_AUTHORIZED,
            'RECEIVED', 'RECEIVED_IN_CASH' => Charge::STATUS_APPROVED,
            'OVERDUE' => Charge::STATUS_EXPIRED,
            'REFUNDED' => Charge::STATUS_REFUNDED,
            'REFUND_REQUESTED', 'REFUND_IN_PROGRESS' => Charge::STATUS_REFUNDED,
            'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE',
            'AWAITING_CHARGEBACK_REVERSAL' => Charge::STATUS_CHARGEBACK,
            'DUNNING_REQUESTED', 'DUNNING_RECEIVED' => Charge::STATUS_APPROVED,
            default => Charge::STATUS_PENDING,
        };
    }

    // ── Webhook Verification ─────────────────────────────────────

    /**
     * Verify Asaas webhook authenticity.
     * Asaas sends an access token in the webhook configuration;
     * we validate it matches our stored token.
     */
    public static function verifyWebhookToken(string $receivedToken, string $expectedToken): bool
    {
        if (empty($expectedToken)) {
            return true; // No token configured, skip verification
        }

        return hash_equals($expectedToken, $receivedToken);
    }

    // ── Retrieve Payment ─────────────────────────────────────────

    /**
     * Retrieve payment details from Asaas.
     */
    public function getPayment(string $paymentId): array
    {
        return $this->makeRequest('GET', "payments/{$paymentId}");
    }

    /**
     * Tokenize a credit card for future use.
     */
    public function tokenizeCreditCard(array $cardData, array $customerData): array
    {
        $payload = [
            'creditCard' => [
                'holderName' => $cardData['holder_name'] ?? '',
                'number' => $cardData['number'] ?? '',
                'expiryMonth' => $cardData['expiry_month'] ?? '',
                'expiryYear' => $cardData['expiry_year'] ?? '',
                'ccv' => $cardData['cvv'] ?? '',
            ],
            'creditCardHolderInfo' => [
                'name' => $customerData['name'] ?? '',
                'email' => $customerData['email'] ?? '',
                'cpfCnpj' => preg_replace('/\D/', '', $customerData['cpf_cnpj'] ?? ''),
                'postalCode' => $customerData['postal_code'] ?? '',
                'addressNumber' => $customerData['address_number'] ?? '',
                'phone' => preg_replace('/\D/', '', $customerData['phone'] ?? ''),
            ],
        ];

        if (!empty($customerData['asaas_customer_id'])) {
            $payload['customer'] = $customerData['asaas_customer_id'];
        }

        if (!empty($cardData['remote_ip'])) {
            $payload['remoteIp'] = $cardData['remote_ip'];
        }

        return $this->makeRequest('POST', 'creditCard/tokenize', $payload);
    }
}
