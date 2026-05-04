<?php

namespace App\Infrastructure\Acquirers;

use App\Domain\Acquirer\Contracts\AcquirerInterface;
use App\Domain\Payment\Entities\Charge;
use Illuminate\Support\Facades\Http;

class PagarmeAcquirer implements AcquirerInterface
{
    private string $apiKey;
    private string $baseUrl = 'https://api.pagar.me/core/v5';

    public function __construct(array $credentials)
    {
        $this->apiKey = $credentials['secret_key'] ?? '';
    }

    /**
     * Process payment
     */
    public function processPayment(Charge $charge, array $paymentData): array
    {
        try {
            $payload = $this->buildPaymentPayload($charge, $paymentData);

            $response = Http::withBasicAuth($this->apiKey, '')
                ->post("{$this->baseUrl}/orders", $payload);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Payment failed',
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
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
            $payload = [
                'amount' => $amount ? (int)($amount * 100) : (int)($charge->amount * 100),
            ];

            $response = Http::withBasicAuth($this->apiKey, '')
                ->post("{$this->baseUrl}/charges/{$charge->external_id}/refund", $payload);

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
                'amount' => $data['amount'] / 100,
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
                'amount' => (int)($charge->amount * 100),
                'payment_method' => 'pix',
                'pix' => [
                    'expires_in' => 3600, // 1 hour
                ],
                'customer' => [
                    'name' => $charge->customer_name,
                    'email' => $charge->customer_email,
                    'document' => $charge->customer_document,
                    'type' => strlen($charge->customer_document) === 11 ? 'individual' : 'company',
                ],
                'metadata' => [
                    'charge_id' => $charge->id,
                ],
            ];

            $response = Http::withBasicAuth($this->apiKey, '')
                ->post("{$this->baseUrl}/charges", $payload);

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
                'pix_qr_code' => $data['last_transaction']['qr_code'] ?? null,
                'pix_qr_code_url' => $data['last_transaction']['qr_code_url'] ?? null,
                'pix_expires_at' => $data['last_transaction']['expires_at'] ?? null,
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
                'amount' => (int)($charge->amount * 100),
                'payment_method' => 'boleto',
                'boleto' => [
                    'bank' => '237',
                    'instructions' => 'Pagar até o vencimento',
                    'due_at' => now()->addDays(3)->format('Y-m-d'),
                ],
                'customer' => [
                    'name' => $charge->customer_name,
                    'email' => $charge->customer_email,
                    'document' => $charge->customer_document,
                    'type' => strlen($charge->customer_document) === 11 ? 'individual' : 'company',
                ],
                'metadata' => [
                    'charge_id' => $charge->id,
                ],
            ];

            $response = Http::withBasicAuth($this->apiKey, '')
                ->post("{$this->baseUrl}/charges", $payload);

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
                'boleto_url' => $data['last_transaction']['url'] ?? null,
                'boleto_barcode' => $data['last_transaction']['line'] ?? null,
                'boleto_expires_at' => $data['last_transaction']['due_at'] ?? null,
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
            $response = Http::withBasicAuth($this->apiKey, '')
                ->get("{$this->baseUrl}/balance");
            
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
        $customer = $paymentData['customer'] ?? [];
        
        return [
            'items' => [
                [
                    'amount' => (int)($charge->amount * 100),
                    'description' => $charge->description ?? 'Payment',
                    'quantity' => 1,
                ],
            ],
            'customer' => [
                'name' => $charge->customer_name,
                'email' => $charge->customer_email,
                'document' => $charge->customer_document,
                'type' => strlen($charge->customer_document) === 11 ? 'individual' : 'company',
                'phones' => [
                    'mobile_phone' => [
                        'country_code' => '55',
                        'area_code' => substr($charge->customer_phone ?? '', 0, 2),
                        'number' => substr($charge->customer_phone ?? '', 2),
                    ],
                ],
            ],
            'payments' => [
                [
                    'payment_method' => 'credit_card',
                    'credit_card' => [
                        'installments' => $card['installments'] ?? 1,
                        'card' => [
                            'number' => $card['number'],
                            'holder_name' => $card['holder_name'],
                            'exp_month' => $card['exp_month'],
                            'exp_year' => $card['exp_year'],
                            'cvv' => $card['cvv'],
                        ],
                    ],
                ],
            ],
            'metadata' => [
                'charge_id' => $charge->id,
            ],
        ];
    }

    /**
     * Map Pagarme status to internal status
     */
    private function mapStatus(string $pagarmeStatus): string
    {
        return match($pagarmeStatus) {
            'paid' => 'paid',
            'pending' => 'pending',
            'processing' => 'processing',
            'failed', 'refused' => 'failed',
            'canceled' => 'cancelled',
            'refunded' => 'refunded',
            'chargedback' => 'chargeback',
            default => 'pending',
        };
    }
}
