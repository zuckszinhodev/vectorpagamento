<?php

namespace App\Infrastructure\Acquirers;

use App\Domain\Acquirer\Contracts\AcquirerInterface;
use App\Domain\Acquirer\ValueObjects\ChargeRequest;
use App\Domain\Acquirer\ValueObjects\ChargeResponse;
use App\Domain\Acquirer\ValueObjects\RefundRequest;
use App\Domain\Acquirer\ValueObjects\RefundResponse;
use App\Models\Charge;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HunterSubAcquirer implements AcquirerInterface
{
    private const DEFAULT_PIX_EXPIRATION_SECONDS = 86400;

    private string $baseUrl = 'https://api.huntersubv2.com/functions/v1';
    private string $apiKey;
    private string $companyId;

    public function __construct(array $credentials)
    {
        $this->apiKey = $credentials['api_key'] ?? '';
        $this->companyId = $credentials['company_id'] ?? '';
    }

    public function getName(): string
    {
        return 'huntersub';
    }

    public function processPayment(Charge $charge, array $cardData): array
    {
        $chargeRequest = new ChargeRequest(
            amount: $charge->amount,
            paymentMethod: $charge->payment_method,
            customer: $charge->customer ?? [],
            description: $charge->description ?? 'Pagamento',
            installments: $charge->installments ?? 1,
            card: $cardData,
            metadata: $charge->metadata ?? []
        );

        $response = $this->charge($chargeRequest);

        return [
            'success' => $response->success,
            'transaction_id' => $response->transactionId,
            'status' => $response->status,
            'message' => $response->message,
            'payment_data' => $response->paymentData,
        ];
    }

    public function refund(Charge $charge, float $amount): array
    {
        $refundRequest = new RefundRequest(
            transactionId: $charge->external_id,
            amount: (int)($amount * 100)
        );

        $response = $this->processRefund($refundRequest);

        return [
            'success' => $response->success,
            'refund_id' => $response->refundId,
            'status' => $response->status,
            'message' => $response->message,
        ];
    }

    public function generatePixQrCode(Charge $charge): array
    {
        try {
            $payload = [
                'paymentMethod' => 'PIX',
                'amount' => $charge->amount,
                'installments' => 1,
                'customer' => [
                    'name' => $charge->customer['name'] ?? 'Cliente',
                    'email' => $charge->customer['email'] ?? 'cliente@email.com',
                    'document' => [
                        'number' => $charge->customer['document'] ?? '',
                        'type' => 'CPF',
                    ],
                    'phone' => $charge->customer['phone'] ?? null,
                ],
                'items' => [[
                    'title' => $charge->description ?? 'Pagamento PIX',
                    'unitPrice' => $charge->amount,
                    'quantity' => 1,
                    'tangible' => false,
                ]],
                'postbackUrl' => config('app.url') . '/api/v1/webhooks/huntersub',
                'externalRef' => $charge->id,
                'metadata' => array_merge($charge->metadata ?? [], [
                    'charge_id' => $charge->id,
                ]),
            ];

            \Illuminate\Support\Facades\Log::info('HunterSub: Sending request', [
                'url' => "{$this->baseUrl}/transactions",
                'auth_header' => 'Basic ' . substr(base64_encode("{$this->apiKey}:{$this->companyId}"), 0, 30) . '...',
                'api_key_length' => strlen($this->apiKey),
                'company_id_length' => strlen($this->companyId),
            ]);

            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode("{$this->apiKey}:{$this->companyId}"),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/transactions", $payload);

            \Illuminate\Support\Facades\Log::info('HunterSub: Response received', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                $error = $response->json();
                return [
                    'success' => false,
                    'error' => $error['message'] ?? 'Falha ao gerar PIX',
                    'raw_response' => $error,
                ];
            }

            $data = $response->json();
            $pixData = $data['pix'] ?? [];

            return [
                'success' => true,
                'external_id' => $data['id'] ?? null,
                'pix_qr_code' => $pixData['qrcode'] ?? null,  // lowercase 'qrcode'
                'pix_qr_code_url' => $pixData['qrcode'] ?? null,  // HunterSub usa o mesmo campo
                'pix_emv' => $pixData['qrcode'] ?? null,
                'pix_expires_at' => $pixData['expirationDate'] ?? null,
                'end2end_id' => $pixData['end2EndId'] ?? null,
                'status' => $this->mapStatus($data['status'] ?? 'pending'),
                'data' => $data,
            ];
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('HunterSub: Exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function generateBoleto(Charge $charge): array
    {
        $chargeRequest = new ChargeRequest(
            amount: $charge->amount,
            paymentMethod: 'boleto',
            customer: $charge->customer ?? [],
            description: $charge->description ?? 'Pagamento Boleto',
            installments: 1,
            metadata: $charge->metadata ?? []
        );

        $response = $this->charge($chargeRequest);

        if (!$response->success) {
            return [
                'success' => false,
                'message' => $response->message,
            ];
        }

        return [
            'success' => true,
            'barcode' => $response->paymentData['barcode'] ?? null,
            'boleto_url' => $response->paymentData['boleto_url'] ?? null,
            'expires_at' => $response->paymentData['expires_at'] ?? null,
            'transaction_id' => $response->transactionId,
        ];
    }

    private function charge(ChargeRequest $request): ChargeResponse
    {
        try {
            $payload = $this->buildChargePayload($request);
            
            Log::info('HunterSub: Creating charge', [
                'amount' => $request->amount,
                'payment_method' => $request->paymentMethod,
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode("{$this->apiKey}:{$this->companyId}"),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/transactions", $payload);

            if (!$response->successful()) {
                Log::error('HunterSub: Charge failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return new ChargeResponse(
                    success: false,
                    transactionId: null,
                    status: 'failed',
                    amount: $request->amount,
                    message: $response->json('message') ?? 'Erro ao processar pagamento',
                    rawResponse: $response->json()
                );
            }

            $data = $response->json();
            
            Log::info('HunterSub: Charge created', [
                'transaction_id' => $data['id'] ?? null,
                'status' => $data['status'] ?? null,
            ]);

            // Mapear status
            $status = $this->mapStatus($data['status'] ?? 'pending');

            // Preparar dados de pagamento
            $paymentData = [
                'external_id' => $data['id'] ?? null,
            ];

            // Adicionar dados específicos do método de pagamento
            if ($request->paymentMethod === 'pix' && isset($data['pix'])) {
                $paymentData['qr_code'] = $data['pix']['qrCode'] ?? null;
                $paymentData['qr_code_url'] = $data['pix']['qrCodeUrl'] ?? null;
                $paymentData['emv'] = $data['pix']['qrCode'] ?? null;
                $paymentData['expires_at'] = $data['pix']['expiresAt'] ?? null;
            }

            if ($request->paymentMethod === 'credit_card' && isset($data['card'])) {
                $paymentData['card_brand'] = $data['card']['brand'] ?? null;
                $paymentData['card_last_digits'] = $data['card']['lastDigits'] ?? null;
            }

            if ($request->paymentMethod === 'boleto' && isset($data['boleto'])) {
                $paymentData['barcode'] = $data['boleto']['barcode'] ?? null;
                $paymentData['boleto_url'] = $data['boleto']['url'] ?? null;
                $paymentData['expires_at'] = $data['boleto']['expiresAt'] ?? null;
            }

            return new ChargeResponse(
                success: true,
                transactionId: $data['id'] ?? null,
                status: $status,
                amount: $data['amount'] ?? $request->amount,
                message: 'Transação criada com sucesso',
                paymentData: $paymentData,
                rawResponse: $data
            );

        } catch (\Exception $e) {
            Log::error('HunterSub: Exception during charge', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new ChargeResponse(
                success: false,
                transactionId: null,
                status: 'failed',
                amount: $request->amount,
                message: 'Erro ao processar pagamento: ' . $e->getMessage(),
                rawResponse: ['error' => $e->getMessage()]
            );
        }
    }

    private function processRefund(RefundRequest $request): RefundResponse
    {
        try {
            Log::info('HunterSub: Processing refund', [
                'transaction_id' => $request->transactionId,
                'amount' => $request->amount,
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode("{$this->apiKey}:{$this->companyId}"),
                'Accept' => 'application/json',
            ])->delete("{$this->baseUrl}/transactions/{$request->transactionId}");

            if (!$response->successful()) {
                Log::error('HunterSub: Refund failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return new RefundResponse(
                    success: false,
                    refundId: null,
                    status: 'failed',
                    amount: $request->amount,
                    message: $response->json('message') ?? 'Erro ao processar estorno',
                    rawResponse: $response->json()
                );
            }

            $data = $response->json();

            Log::info('HunterSub: Refund processed', [
                'transaction_id' => $data['id'] ?? null,
                'status' => $data['status'] ?? null,
            ]);

            return new RefundResponse(
                success: true,
                refundId: $data['id'] ?? null,
                status: 'refunded',
                amount: $data['amount'] ?? $request->amount,
                message: 'Estorno processado com sucesso',
                rawResponse: $data
            );

        } catch (\Exception $e) {
            Log::error('HunterSub: Exception during refund', [
                'error' => $e->getMessage(),
            ]);

            return new RefundResponse(
                success: false,
                refundId: null,
                status: 'failed',
                amount: $request->amount,
                message: 'Erro ao processar estorno: ' . $e->getMessage(),
                rawResponse: ['error' => $e->getMessage()]
            );
        }
    }

    public function getTransaction(string $transactionId): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode("{$this->apiKey}:{$this->companyId}"),
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}/transactions/{$transactionId}");

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            Log::error('HunterSub: Error getting transaction', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function handleWebhook(array $payload): array
    {
        Log::info('HunterSub: Webhook received', ['payload' => $payload]);

        $eventType = $payload['type'] ?? null;
        $data = $payload['data'] ?? [];

        if (!$eventType || !$data) {
            Log::warning('HunterSub: Invalid webhook payload');
            return ['success' => false, 'message' => 'Invalid payload'];
        }

        // Mapear evento para status
        $status = match($eventType) {
            'transaction.paid' => 'approved',
            'transaction.pending' => 'pending',
            'transaction.failed' => 'failed',
            'transaction.refunded' => 'refunded',
            'transaction.chargeback' => 'chargeback',
            'transaction.delivered' => 'delivered',
            default => 'pending',
        };

        return [
            'success' => true,
            'transaction_id' => $data['id'] ?? null,
            'status' => $status,
            'amount' => $data['amount'] ?? null,
            'payment_method' => $this->mapPaymentMethod($data['paymentMethod'] ?? null),
            'event_type' => $eventType,
            'raw_data' => $data,
        ];
    }

    public function verifyWebhookSignature(array $payload, string $signature, string $secret): bool
    {
        if (empty($signature) || empty($secret)) {
            return false;
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            return false;
        }

        $normalizedSignature = trim($signature);
        if (str_starts_with(strtolower($normalizedSignature), 'sha256=')) {
            $normalizedSignature = substr($normalizedSignature, 7);
        }

        $expectedSignature = hash_hmac('sha256', $payloadJson, $secret);
        
        return hash_equals($expectedSignature, $normalizedSignature);
    }

    private function buildChargePayload(ChargeRequest $request): array
    {
        $payload = [
            'amount' => $request->amount,
            'paymentMethod' => strtoupper($this->mapPaymentMethodToHunterSub($request->paymentMethod)),
            'installments' => $request->installments ?? 1,
            'customer' => [
                'name' => $request->customer['name'] ?? '',
                'email' => $request->customer['email'] ?? '',
                'document' => [
                    'number' => preg_replace('/\D/', '', $request->customer['document'] ?? ''),
                    'type' => strlen(preg_replace('/\D/', '', $request->customer['document'] ?? '')) === 11 ? 'CPF' : 'CNPJ',
                ],
            ],
            'items' => [
                [
                    'title' => $request->description ?? 'Produto',
                    'unitPrice' => $request->amount,
                    'quantity' => 1,
                    'tangible' => false,
                ],
            ],
        ];

        // Adicionar telefone se disponível
        if (!empty($request->customer['phone'])) {
            $payload['customer']['phone'] = preg_replace('/\D/', '', $request->customer['phone']);
        }

        // Adicionar endereço se disponível
        if (!empty($request->customer['address'])) {
            $address = $request->customer['address'];
            $payload['customer']['address'] = [
                'street' => $address['street'] ?? '',
                'number' => $address['number'] ?? 'S/N',
                'complement' => $address['complement'] ?? '',
                'neighborhood' => $address['neighborhood'] ?? '',
                'city' => $address['city'] ?? '',
                'state' => $address['state'] ?? '',
                'zipCode' => preg_replace('/\D/', '', $address['zip_code'] ?? ''),
                'country' => 'BR',
            ];
        }

        // Configurações específicas por método de pagamento
        if ($request->paymentMethod === 'pix') {
            $payload['pix'] = [
                'expiresInSeconds' => self::DEFAULT_PIX_EXPIRATION_SECONDS,
            ];
        }

        if ($request->paymentMethod === 'credit_card' && !empty($request->card)) {
            $payload['card'] = [
                'number' => preg_replace('/\D/', '', $request->card['number'] ?? ''),
                'holderName' => $request->card['holder_name'] ?? '',
                'expirationMonth' => $request->card['exp_month'] ?? '',
                'expirationYear' => $request->card['exp_year'] ?? '',
                'cvv' => $request->card['cvv'] ?? '',
            ];
        }

        if ($request->paymentMethod === 'boleto') {
            $payload['boleto'] = [
                'expiresInDays' => 3,
            ];
        }

        // Adicionar URL de postback se configurada
        if (!empty($request->metadata['postback_url'])) {
            $payload['postbackUrl'] = $request->metadata['postback_url'];
        }

        // Adicionar referência externa
        if (!empty($request->metadata['external_ref'])) {
            $payload['externalRef'] = $request->metadata['external_ref'];
        }

        // Adicionar metadata
        if (!empty($request->metadata)) {
            $payload['metadata'] = array_filter($request->metadata, function($key) {
                return !in_array($key, ['postback_url', 'external_ref']);
            }, ARRAY_FILTER_USE_KEY);
        }

        return $payload;
    }

    private function mapStatus(string $hunterSubStatus): string
    {
        return match($hunterSubStatus) {
            'paid' => 'approved',
            'pending' => 'pending',
            'waiting_payment' => 'pending',
            'failed' => 'failed',
            'refunded' => 'refunded',
            'chargeback' => 'chargeback',
            default => 'pending',
        };
    }

    private function mapPaymentMethod(?string $hunterSubMethod): string
    {
        if (!$hunterSubMethod) {
            return 'unknown';
        }

        return match(strtoupper($hunterSubMethod)) {
            'PIX' => 'pix',
            'CARD' => 'credit_card',
            'BOLETO' => 'boleto',
            default => strtolower($hunterSubMethod),
        };
    }

    private function mapPaymentMethodToHunterSub(string $method): string
    {
        return match($method) {
            'pix' => 'PIX',
            'credit_card', 'card' => 'CARD',
            'boleto' => 'BOLETO',
            default => 'PIX',
        };
    }
}
