<?php

namespace App\Infrastructure\Acquirers;

use App\Domain\Acquirer\Contracts\AcquirerInterface;
use App\Models\Charge;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Witetec Acquirer Implementation
 * 
 * API Documentation: https://doc.witetec.com
 * Base URL: https://api.witetec.net
 * Authentication: x-api-key header
 * 
 * Supported Methods: PIX, Credit Card (3DS), Boleto
 * All amounts are in CENTS (centavos)
 */
class WitetecAcquirer implements AcquirerInterface
{
    private string $apiKey;
    private string $baseUrl = 'https://api.witetec.net';
    private string $webhookSecret;

    public function __construct(array $credentials)
    {
        $this->apiKey = $credentials['api_key'] ?? '';
        $this->webhookSecret = $credentials['webhook_secret'] ?? '';
        
        if (!empty($credentials['base_url'])) {
            $this->baseUrl = rtrim($credentials['base_url'], '/');
        }
    }

    public function getName(): string
    {
        return 'Witetec';
    }

    private function getHttpClient()
    {
        $client = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->connectTimeout(3)->timeout(10);

        if (config('app.env') === 'local') {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    public function processPayment(Charge $charge, array $paymentData): array
    {
        try {
            $payload = $this->buildTransactionPayload($charge, 'CREDIT_CARD', $paymentData);

            Log::info('Witetec Card payment request', [
                'charge_id' => $charge->id,
                'amount_cents' => $payload['amount'],
            ]);

            $response = $this->getHttpClient()->post("{$this->baseUrl}/transactions", $payload);

            if (!$response->successful()) {
                $error = $response->json();
                return [
                    'success' => false,
                    'error' => $error['message'] ?? $error['error'] ?? 'Card payment failed',
                    'raw_response' => $error,
                ];
            }

            $data = $response->json();
            $txData = $data['data'] ?? $data;

            return [
                'success' => true,
                'external_id' => $txData['id'],
                'status' => $this->mapStatus($txData['status'] ?? 'PENDING'),
                'data' => $txData,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function refund(Charge $charge, float $amount): array
    {
        return [
            'success' => false,
            'error' => 'Refunds must be processed through Witetec dashboard',
        ];
    }

    public function generatePixQrCode(Charge $charge): array
    {
        try {
            $payload = $this->buildTransactionPayload($charge, 'PIX');

            Log::info('Witetec PIX request', [
                'charge_id' => $charge->id,
                'amount_cents' => $payload['amount'],
            ]);

            $response = $this->getHttpClient()->post("{$this->baseUrl}/transactions", $payload);

            if (!$response->successful()) {
                $error = $response->json();
                return [
                    'success' => false,
                    'error' => $error['message'] ?? $error['error'] ?? 'PIX generation failed',
                    'raw_response' => $error,
                ];
            }

            $data = $response->json();
            $txData = $data['data'] ?? $data;
            $pixData = $txData['pix'] ?? [];

            return [
                'success' => true,
                'external_id' => $txData['id'],
                'pix_qr_code' => $pixData['qrcode'] ?? null,
                'pix_qr_code_url' => $pixData['qrcodeUrl'] ?? $pixData['copyPaste'] ?? null,
                'pix_expires_at' => $pixData['expirationDate'] ?? null,
                'status' => $this->mapStatus($txData['status'] ?? 'PENDING'),
                'data' => $txData,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function generateBoleto(Charge $charge): array
    {
        try {
            $payload = $this->buildTransactionPayload($charge, 'BOLETO');

            Log::info('Witetec Boleto request', [
                'charge_id' => $charge->id,
                'amount_cents' => $payload['amount'],
            ]);

            $response = $this->getHttpClient()->post("{$this->baseUrl}/transactions", $payload);

            if (!$response->successful()) {
                $error = $response->json();
                return [
                    'success' => false,
                    'error' => $error['message'] ?? $error['error'] ?? 'Boleto generation failed',
                    'raw_response' => $error,
                ];
            }

            $data = $response->json();
            $txData = $data['data'] ?? $data;
            $boletoData = $txData['boleto'] ?? [];

            return [
                'success' => true,
                'external_id' => $txData['id'],
                'boleto_url' => $boletoData['url'] ?? null,
                'boleto_barcode' => $boletoData['barcode'] ?? null,
                'boleto_digitable_line' => $boletoData['digitableLine'] ?? null,
                'boleto_expires_at' => $boletoData['expirationDate'] ?? null,
                'status' => $this->mapStatus($txData['status'] ?? 'WAITING_PAYMENT'),
                'data' => $txData,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getBalance(): array
    {
        try {
            $response = $this->getHttpClient()->get("{$this->baseUrl}/seller-wallet/gestao");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Failed to get balance',
                ];
            }

            $data = $response->json();
            $walletData = $data['data'] ?? $data;

            return [
                'success' => true,
                'balance' => ($walletData['balance'] ?? 0) / 100,
                'blocked_balance' => ($walletData['blockedBalance'] ?? 0) / 100,
                'data' => $walletData,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function buildTransactionPayload(Charge $charge, string $method, array $paymentData = []): array
    {
        $customer = $charge->customer ?? [];
        $metadata = $charge->metadata ?? [];

        // Build customer data
        $customerData = [
            'name' => $customer['name'] ?? 'Cliente',
            'email' => $customer['email'] ?? 'cliente@email.com',
            'phone' => preg_replace('/\D/', '', $customer['phone'] ?? ''),
            'documentType' => $this->getDocumentType($customer['document'] ?? ''),
            'document' => preg_replace('/\D/', '', $customer['document'] ?? ''),
        ];

        // Build items array
        $items = [[
            'title' => $charge->description ?? 'Produto',
            'amount' => (int) $charge->amount,
            'quantity' => 1,
            'tangible' => true,
        ]];

        $payload = [
            'amount' => (int) $charge->amount,
            'method' => $method,
            'customer' => $customerData,
            'items' => $items,
            'postbackUrl' => config('app.url') . '/api/v1/webhooks/witetec',
            'metadata' => [
                'charge_id' => $charge->id,
                'account_id' => $charge->account_id,
            ],
        ];

        if ($method === 'CREDIT_CARD') {
            $payload['installments'] = $charge->installments ?? 1;
            
            // Card data - Witetec expects 'card' field with encrypted or plain data
            if (isset($paymentData['card'])) {
                $cardData = $paymentData['card'];
                
                // If it's mock data (for testing), structure it properly
                if (is_array($cardData) && isset($cardData['_mock'])) {
                    unset($cardData['_mock']);
                    $payload['card'] = $cardData;
                } else {
                    // Encrypted data from ShieldHelper
                    $payload['card'] = $cardData;
                }
            }
            
            // Add 3DS data if available
            if (isset($paymentData['threeDSecure'])) {
                $payload['threeDSecure'] = $paymentData['threeDSecure'];
            }
            
            // Add IP address for 3DS
            if (isset($paymentData['ipAddress'])) {
                $payload['ipAddress'] = $paymentData['ipAddress'];
            }
        }

        return $payload;
    }

    private function getDocumentType(string $document): string
    {
        $cleanDoc = preg_replace('/\D/', '', $document);
        return strlen($cleanDoc) <= 11 ? 'CPF' : 'CNPJ';
    }

    private function mapStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'PAID' => 'paid',
            'APPROVED' => 'paid',
            'PENDING' => 'pending',
            'WAITING_PAYMENT' => 'pending',
            'PROCESSING' => 'processing',
            'AUTHORIZED' => 'authorized',
            'REFUSED' => 'declined',
            'FAILED' => 'failed',
            'CANCELLED', 'CANCELED' => 'cancelled',
            'REFUNDED' => 'refunded',
            'CHARGEDBACK' => 'chargeback',
            default => 'pending',
        };
    }

    public static function verifyWebhookSignature(string $payload, ?string $signature, string $secret): bool
    {
        if (empty($secret)) {
            Log::warning('Witetec webhook secret not configured — rejecting request');
            return false;
        }

        if (empty($signature)) {
            return false;
        }

        $computedSignature = hash_hmac('sha256', $payload, $secret);
        return hash_equals($computedSignature, $signature);
    }
}
