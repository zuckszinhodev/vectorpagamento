<?php

namespace App\Infrastructure\Acquirers;

use App\Domain\Acquirer\Contracts\AcquirerInterface;
use App\Models\Charge;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SharkHubSubAcquirer implements AcquirerInterface
{
    private string $apiKey;
    private string $withdrawalKey;
    private string $baseUrl;
    private ?string $webhookUrl;
    private ?string $transferWebhookUrl;
    private string $webhookSecret;

    public function __construct(array $credentials)
    {
        $this->apiKey = (string) ($credentials['api_key'] ?? '');
        $this->withdrawalKey = (string) ($credentials['withdrawal_key'] ?? $credentials['transfer_api_key'] ?? '');
        $this->baseUrl = rtrim((string) ($credentials['base_url'] ?? config('acquirers.sharkhubsub.base_url', 'https://api.sharkhubsubadquirente.com')), '/');
        $this->webhookUrl = !empty($credentials['webhook_url'])
            ? (string) $credentials['webhook_url']
            : config('acquirers.sharkhubsub.webhook_url');
        $this->transferWebhookUrl = !empty($credentials['transfer_webhook_url'])
            ? (string) $credentials['transfer_webhook_url']
            : config('acquirers.sharkhubsub.transfer_webhook_url');
        $this->webhookSecret = (string) ($credentials['webhook_secret'] ?? '');
    }

    public function getName(): string
    {
        return 'sharkhubsub';
    }

    public function processPayment(Charge $charge, array $cardData): array
    {
        return [
            'success' => false,
            'error' => 'SharkHub Subadquirente ainda não possui payload de cartão configurado neste gateway.',
        ];
    }

    public function refund(Charge $charge, float $amount): array
    {
        $transactionId = $charge->acquirer_transaction_id ?? $charge->external_id;

        if (!$transactionId) {
            return [
                'success' => false,
                'error' => 'Transação SharkHub sem ID externo para estorno.',
            ];
        }

        try {
            $response = $this->paymentClient()
                ->post("{$this->baseUrl}/v1/payment/{$transactionId}/refund");

            if (!$response->successful()) {
                $error = $this->responseJson($response);

                return [
                    'success' => false,
                    'error' => $error['message'] ?? $error['error'] ?? 'Falha ao estornar pagamento SharkHub',
                    'raw_response' => $error,
                ];
            }

            return [
                'success' => true,
                'refund_id' => $transactionId,
                'status' => 'refunded',
                'message' => $response->json('message') ?? 'success',
                'raw_response' => $this->responseJson($response),
            ];
        } catch (\Throwable $e) {
            Log::error('SharkHubSub: refund exception', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function generatePixQrCode(Charge $charge): array
    {
        try {
            $payload = $this->buildPaymentPayload($charge, 'PIX');

            Log::info('SharkHubSub: creating PIX payment', [
                'charge_id' => $charge->id,
                'amount' => $charge->amount,
            ]);

            $response = $this->paymentClient()
                ->post("{$this->baseUrl}/v1/payment", $payload);

            if (!$response->successful()) {
                $error = $this->responseJson($response);

                Log::warning('SharkHubSub: PIX payment failed', [
                    'charge_id' => $charge->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'error' => $error['message'] ?? $error['error'] ?? 'Falha ao gerar PIX SharkHub',
                    'raw_response' => $error,
                ];
            }

            $data = $this->responseJson($response);
            $pixData = $data['data'] ?? [];
            $copyPaste = $pixData['copypaste'] ?? $pixData['copyPaste'] ?? $pixData['qrcode'] ?? null;

            return [
                'success' => true,
                'external_id' => $data['id'] ?? null,
                'pix_qr_code' => $copyPaste,
                'pix_copy_paste' => $copyPaste,
                'pix_qr_code_url' => $pixData['qrCodeUrl'] ?? $pixData['qrcodeUrl'] ?? null,
                'pix_emv' => $copyPaste,
                'pix_expires_at' => $data['expiresAt'] ?? null,
                'end2end_id' => $pixData['e2e'] ?? null,
                'status' => $this->mapPaymentStatus((string) ($data['status'] ?? 'PENDING')),
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('SharkHubSub: PIX exception', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function generateBoleto(Charge $charge): array
    {
        return [
            'success' => false,
            'error' => 'Boleto SharkHub ainda não foi habilitado porque a documentação enviada não trouxe payload específico de boleto.',
        ];
    }

    public function sendPixTransfer(array $transferData): array
    {
        if ($this->withdrawalKey === '') {
            return [
                'success' => false,
                'error' => 'Credencial de saque SharkHub não configurada.',
            ];
        }

        try {
            $payload = [
                'amount' => (int) ($transferData['amount_cents'] ?? round(((float) ($transferData['amount'] ?? 0)) * 100)),
                'method' => 'PIX',
                'externalRef' => (string) ($transferData['external_ref'] ?? $transferData['idempotency_key'] ?? ''),
                'notificationUrl' => $this->transferWebhookUrl,
                'pix' => [
                    'pixKeyType' => $this->mapPixKeyType((string) ($transferData['pix_key_type'] ?? 'CPF')),
                    'pixKey' => (string) ($transferData['pix_key'] ?? ''),
                ],
            ];

            $response = $this->transferClient()
                ->post("{$this->baseUrl}/v1/transfer", array_filter($payload, fn ($value) => $value !== null));

            if (!$response->successful()) {
                $error = $this->responseJson($response);

                Log::warning('SharkHubSub: PIX OUT failed', [
                    'external_ref' => $payload['externalRef'],
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'error' => $error['message'] ?? $error['error'] ?? 'Falha ao enviar PIX OUT SharkHub',
                    'raw_response' => $error,
                ];
            }

            $data = $this->responseJson($response);

            return [
                'success' => true,
                'transfer_id' => $data['id'] ?? null,
                'status' => $this->mapTransferStatus((string) ($data['status'] ?? 'IN_QUEUE')),
                'raw_response' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('SharkHubSub: PIX OUT exception', [
                'external_ref' => $transferData['external_ref'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getPayment(string $paymentId): ?array
    {
        try {
            $response = $this->paymentClient()->get("{$this->baseUrl}/v1/payment/{$paymentId}");

            return $response->successful() ? $this->responseJson($response) : null;
        } catch (\Throwable $e) {
            Log::warning('SharkHubSub: get payment exception', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function getTransfer(string $transferId): ?array
    {
        try {
            $response = $this->transferClient()->get("{$this->baseUrl}/v1/transfer/{$transferId}");

            return $response->successful() ? $this->responseJson($response) : null;
        } catch (\Throwable $e) {
            Log::warning('SharkHubSub: get transfer exception', [
                'transfer_id' => $transferId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public static function verifyWebhookSignature(string $rawPayload, ?string $signature, string $secret): bool
    {
        if ($secret === '' || empty($signature)) {
            return false;
        }

        $normalized = trim($signature);
        if (str_starts_with(strtolower($normalized), 'sha256=')) {
            $normalized = substr($normalized, 7);
        }

        $expected = base64_encode(hash_hmac('sha256', $rawPayload, $secret, true));
        $expectedHex = hash_hmac('sha256', $rawPayload, $secret);

        return hash_equals($expected, $normalized) || hash_equals($expectedHex, $normalized);
    }

    public static function mapWebhookPaymentStatus(string $status): string
    {
        return self::normalizePaymentStatus($status);
    }

    public static function mapWebhookTransferStatus(string $status): string
    {
        return self::normalizeTransferStatus($status);
    }

    private function buildPaymentPayload(Charge $charge, string $method): array
    {
        $customer = $charge->customer ?? [];
        $document = preg_replace('/\D/', '', (string) ($customer['document'] ?? ''));
        $description = (string) ($charge->description ?? 'Pagamento PIX');

        return [
            'amount' => (int) $charge->amount,
            'currency' => $charge->currency ?? 'BRL',
            'method' => $method,
            'description' => $description,
            'externalRef' => $charge->id,
            'notificationUrl' => $this->webhookUrl,
            'payer' => [
                'name' => (string) ($customer['name'] ?? 'Cliente'),
                'taxId' => $document,
                'email' => (string) ($customer['email'] ?? 'cliente@email.com'),
                'phone' => preg_replace('/\D/', '', (string) ($customer['phone'] ?? '')),
            ],
            'items' => [[
                'quantity' => 1,
                'name' => $description,
                'price' => (int) $charge->amount,
                'type' => 'DIGITAL',
            ]],
            'metadata' => array_merge($charge->metadata ?? [], [
                'charge_id' => $charge->id,
                'account_id' => $charge->account_id,
                'source' => 'vectorpagamentos',
            ]),
        ];
    }

    private function paymentClient()
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(30);
    }

    private function transferClient()
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->withdrawalKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(30);
    }

    private function responseJson($response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : ['raw' => $response->body()];
    }

    private function mapPaymentStatus(string $status): string
    {
        return self::normalizePaymentStatus($status);
    }

    private static function normalizePaymentStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'PAID', 'APPROVED', 'COMPLETED', 'SUCCESS' => 'approved',
            'PENDING', 'PROCESSING', 'IN_ANALYSIS' => 'pending',
            'REFUSED', 'FAILED', 'DECLINED', 'REJECTED' => 'failed',
            'REFUNDED' => 'refunded',
            'MED' => 'processing',
            'CHARGEDBACK', 'CHARGEBACK' => 'chargeback',
            default => 'pending',
        };
    }

    private function mapTransferStatus(string $status): string
    {
        return self::normalizeTransferStatus($status);
    }

    private static function normalizeTransferStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'COMPLETED', 'PAID' => 'completed',
            'REFUSED', 'FAILED', 'CANCELLED', 'CANCELED' => 'failed',
            'IN_QUEUE', 'IN_ANALYSIS', 'APPROVED', 'PROCESSING' => 'processing',
            default => 'processing',
        };
    }

    private function mapPixKeyType(string $type): string
    {
        $normalized = strtoupper(trim($type));

        return match ($normalized) {
            'PHONE', 'TELEFONE', 'CELULAR' => 'PHONE',
            'RANDOM', 'EVP', 'ALEATORIA', 'ALEATÓRIA' => 'EVP',
            'EMAIL' => 'EMAIL',
            'CNPJ' => 'CNPJ',
            'COPYPASTE' => 'COPYPASTE',
            default => 'CPF',
        };
    }
}
