<?php

namespace App\Infrastructure\Acquirers;

use App\Domain\Acquirer\Contracts\AcquirerInterface;
use App\Domain\Payment\Entities\Charge;
use Illuminate\Support\Facades\Http;

class MercadoPagoAcquirer implements AcquirerInterface
{
    use Concerns\ConfigurableTimeouts;

    private string $accessToken;
    private string $baseUrl = 'https://api.mercadopago.com/v1';

    public function __construct(array $credentials)
    {
        $this->accessToken = $credentials['access_token'] ?? '';
    }

    /**
     * Process payment
     */
    public function processPayment(Charge $charge, array $paymentData): array
    {
        try {
            $payload = $this->buildPaymentPayload($charge, $paymentData);

            $response = Http::withToken($this->accessToken)
                ->post("{$this->baseUrl}/payments", $payload);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Payment failed',
                ];
            }

            $data = $response->json();

            return [
                'success' => in_array($data['status'], ['approved', 'authorized']),
                'external_id' => $data['id'],
                'status' => $this->mapStatus($data['status']),
                'data' => $data,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Refund payment
     */
    public function refund(Charge $charge, ?float $amount = null): array
    {
        try {
            $payload = $amount ? ['amount' => $amount] : [];

            $response = Http::withToken($this->accessToken)
                ->post("{$this->baseUrl}/payments/{$charge->external_id}/refunds", $payload);

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
                'amount' => $data['amount'],
                'status' => 'refunded',
            ];
        } catch (\Exception $e) {
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
                'transaction_amount' => $charge->amount,
                'description' => $charge->description ?? 'Payment',
                'payment_method_id' => 'pix',
                'payer' => [
                    'email' => $charge->customer_email,
                    'first_name' => explode(' ', $charge->customer_name)[0] ?? '',
                    'last_name' => explode(' ', $charge->customer_name, 2)[1] ?? '',
                    'identification' => [
                        'type' => strlen($charge->customer_document) === 11 ? 'CPF' : 'CNPJ',
                        'number' => $charge->customer_document,
                    ],
                ],
            ];

            $response = Http::withToken($this->accessToken)
                ->post("{$this->baseUrl}/payments", $payload);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'PIX generation failed',
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'external_id' => $data['id'],
                'pix_qr_code' => $data['point_of_interaction']['transaction_data']['qr_code'] ?? null,
                'pix_qr_code_url' => $data['point_of_interaction']['transaction_data']['qr_code_base64'] ?? null,
                'pix_expires_at' => $data['date_of_expiration'] ?? null,
            ];
        } catch (\Exception $e) {
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
                'transaction_amount' => $charge->amount,
                'description' => $charge->description ?? 'Payment',
                'payment_method_id' => 'bolbradesco',
                'payer' => [
                    'email' => $charge->customer_email,
                    'first_name' => explode(' ', $charge->customer_name)[0] ?? '',
                    'last_name' => explode(' ', $charge->customer_name, 2)[1] ?? '',
                    'identification' => [
                        'type' => strlen($charge->customer_document) === 11 ? 'CPF' : 'CNPJ',
                        'number' => $charge->customer_document,
                    ],
                ],
            ];

            $response = Http::withToken($this->accessToken)
                ->post("{$this->baseUrl}/payments", $payload);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Boleto generation failed',
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'external_id' => $data['id'],
                'boleto_url' => $data['transaction_details']['external_resource_url'] ?? null,
                'boleto_barcode' => $data['barcode']['content'] ?? null,
                'boleto_expires_at' => $data['date_of_expiration'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check health status
     */
    public function checkHealth(): bool
    {
        try {
            $response = Http::withToken($this->accessToken)
                ->get("{$this->baseUrl}/users/me");
            
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build payment payload
     */
    private function buildPaymentPayload(Charge $charge, array $paymentData): array
    {
        $card = $paymentData['card'] ?? [];
        
        return [
            'transaction_amount' => $charge->amount,
            'token' => $card['token'] ?? null, // Card token from frontend
            'description' => $charge->description ?? 'Payment',
            'installments' => $card['installments'] ?? 1,
            'payment_method_id' => 'visa', // Or detect from card
            'payer' => [
                'email' => $charge->customer_email,
                'first_name' => explode(' ', $charge->customer_name)[0] ?? '',
                'last_name' => explode(' ', $charge->customer_name, 2)[1] ?? '',
                'identification' => [
                    'type' => strlen($charge->customer_document) === 11 ? 'CPF' : 'CNPJ',
                    'number' => $charge->customer_document,
                ],
            ],
            'metadata' => [
                'charge_id' => $charge->id,
            ],
        ];
    }

    /**
     * Map Mercado Pago status to internal status
     */
    private function mapStatus(string $mpStatus): string
    {
        return match($mpStatus) {
            'approved', 'authorized' => 'paid',
            'pending', 'in_process' => 'pending',
            'rejected' => 'failed',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'charged_back' => 'chargeback',
            default => 'pending',
        };
    }
}
