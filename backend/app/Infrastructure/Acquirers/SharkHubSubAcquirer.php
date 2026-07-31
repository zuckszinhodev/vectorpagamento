<?php

namespace App\Infrastructure\Acquirers;

use App\Domain\Acquirer\Contracts\AcquirerInterface;
use App\Models\Account;
use App\Models\Charge;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SharkHubSubAcquirer implements AcquirerInterface
{
    use Concerns\ConfigurableTimeouts;

    private string $apiKey;
    private string $withdrawalKey;
    private string $baseUrl;
    private ?string $webhookUrl;
    private ?string $transferWebhookUrl;
    private string $webhookSecret;
    private string $transferWebhookSecret;

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
        $this->transferWebhookSecret = (string) ($credentials['transfer_webhook_secret'] ?? $this->webhookSecret);
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
                'base_url' => $this->baseUrl,
                'payload' => $payload,
            ]);

            // Retry on transient provider errors — SharkHub intermittently
            // returns {"message":"Provider error."} / 5xx even for valid requests.
            $maxAttempts = 3;
            $response = null;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $response = $this->paymentClient()
                    ->post("{$this->baseUrl}/v1/payment", $payload);

                if ($response->successful()) {
                    break;
                }

                $err = $this->responseJson($response);
                $msg = strtolower((string) ($err['message'] ?? $err['error'] ?? ''));
                $retryable = str_contains($msg, 'provider error')
                    || in_array($response->status(), [429, 500, 502, 503, 504], true);

                Log::warning('SharkHubSub: PIX payment attempt failed', [
                    'charge_id' => $charge->id,
                    'attempt' => $attempt,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'retryable' => $retryable,
                ]);

                if (!$retryable || $attempt === $maxAttempts) {
                    break;
                }

                usleep(700000); // 0.7s backoff before retrying
            }

            Log::info('SharkHubSub: PIX payment response', [
                'charge_id' => $charge->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                $error = $this->responseJson($response);

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
            $transfer = is_array($data['data'] ?? null) ? $data['data'] : $data;

            return [
                'success' => true,
                'transfer_id' => $transfer['id'] ?? $data['id'] ?? null,
                'status' => $this->mapTransferStatus((string) ($transfer['status'] ?? $data['status'] ?? 'IN_QUEUE')),
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

            Log::info('SharkHubSub: get payment response', [
                'payment_id' => $paymentId,
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                Log::warning('SharkHubSub: get payment failed', [
                    'payment_id' => $paymentId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $this->responseJson($response);
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

            if (!$response->successful()) {
                Log::warning('SharkHubSub: get transfer failed', [
                    'transfer_id' => $transferId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $this->responseJson($response);
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
        $account = $charge->relationLoaded('account') ? $charge->account : Account::find($charge->account_id);
        $document = $this->normalizeTaxId((string) ($customer['document'] ?? ''));
        $sellerDocument = $this->resolveSellerTaxId($account);

        // SharkHub requires several string fields to be >= 5 characters.
        $rawDescription = trim((string) ($charge->description ?? ''));
        $description = mb_strlen($rawDescription) >= 5
            ? $rawDescription
            : ($rawDescription !== '' ? $rawDescription . ' - Pagamento' : 'Pagamento PIX');

        $rawPayerName = trim((string) ($customer['name'] ?? ''));
        $payerName = mb_strlen($rawPayerName) >= 5
            ? $rawPayerName
            : ($rawPayerName !== '' ? $rawPayerName . ' Cliente' : 'Cliente PIX');

        // O checkout nem sempre coleta e-mail e telefone, mas a SharkHub recusa
        // o pedido quando o bloco `payer` chega incompleto — o array_filter abaixo
        // remove os vazios e o campo simplesmente some. Preenchemos aqui.
        $rawPayerEmail = trim((string) ($customer['email'] ?? ''));
        $payerEmail = filter_var($rawPayerEmail, FILTER_VALIDATE_EMAIL)
            ? $rawPayerEmail
            : 'cliente@vectorpagamentos.com';

        $rawPayerPhone = preg_replace('/\D/', '', (string) ($customer['phone'] ?? ''));
        $payerPhone = strlen((string) $rawPayerPhone) >= 10
            ? (string) $rawPayerPhone
            : '11999999999';

        $metadata = array_merge($charge->metadata ?? [], [
            'charge_id' => $charge->id,
            'account_id' => $charge->account_id,
            'source' => 'vectorpagamentos',
            'provider' => 'vectorpagamentos',
            'orderId' => $charge->id,
            'sellerEmail' => (string) ($account?->email ?? ''),
        ]);

        if ($sellerDocument !== null) {
            $metadata['sellerTaxId'] = $sellerDocument;
        }

        return [
            'amount' => (int) $charge->amount,
            'currency' => $charge->currency ?? 'BRL',
            'method' => $method,
            'description' => $description,
            'externalRef' => $charge->id,
            'notificationUrl' => $this->webhookUrl,
            'payer' => array_filter([
                'name' => $payerName,
                'taxId' => $document,
                'email' => $payerEmail,
                'phone' => $payerPhone,
            ], static fn ($value) => $value !== null && $value !== ''),
            'items' => [[
                'quantity' => 1,
                'name' => $description,
                'price' => (int) $charge->amount,
                'type' => 'DIGITAL',
            ]],
            'metadata' => array_map(
                fn ($value) => is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                array_filter($metadata, static fn ($value) => $value !== null && $value !== '')
            ),
        ];
    }

    private function normalizeTaxId(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value);

        if ($digits === '' || !in_array(strlen($digits), [11, 14], true)) {
            return null;
        }

        $isValid = strlen($digits) === 11
            ? $this->isValidCpf($digits)
            : $this->isValidCnpj($digits);

        if (!$isValid) {
            return null;
        }

        return $digits;
    }

    private function normalizeSellerTaxId(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value);

        if ($digits === '' || !in_array(strlen($digits), [11, 14], true)) {
            return null;
        }

        return $digits;
    }

    private function resolveSellerTaxId(?Account $account): ?string
    {
        $accountDocument = $this->normalizeSellerTaxId((string) ($account?->document ?? ''));
        if ($accountDocument !== null) {
            return $accountDocument;
        }

        if (!$account?->id) {
            return null;
        }

        $user = User::query()
            ->where('account_id', $account->id)
            ->with('latestKycSubmission')
            ->orderByDesc('created_at')
            ->first();

        $kyc = $user?->latestKycSubmission;
        if (!$kyc) {
            return null;
        }

        $candidates = [
            (string) ($kyc->company_document ?? ''),
            (string) ($kyc->document_number ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeSellerTaxId($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function isValidCpf(string $cpf): bool
    {
        if (!preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($digitIndex = 9; $digitIndex < 11; $digitIndex++) {
            $sum = 0;

            for ($cursor = 0; $cursor < $digitIndex; $cursor++) {
                $sum += ((int) $cpf[$cursor]) * (($digitIndex + 1) - $cursor);
            }

            $checkDigit = ((10 * $sum) % 11) % 10;
            if ((int) $cpf[$digitIndex] !== $checkDigit) {
                return false;
            }
        }

        return true;
    }

    private function isValidCnpj(string $cnpj): bool
    {
        if (!preg_match('/^\d{14}$/', $cnpj) || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $weightsOne = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $weightsTwo = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        $sum = 0;
        for ($index = 0; $index < 12; $index++) {
            $sum += ((int) $cnpj[$index]) * $weightsOne[$index];
        }

        $remainder = $sum % 11;
        $digitOne = $remainder < 2 ? 0 : 11 - $remainder;
        if ((int) $cnpj[12] !== $digitOne) {
            return false;
        }

        $sum = 0;
        for ($index = 0; $index < 13; $index++) {
            $sum += ((int) $cnpj[$index]) * $weightsTwo[$index];
        }

        $remainder = $sum % 11;
        $digitTwo = $remainder < 2 ? 0 : 11 - $remainder;

        return (int) $cnpj[13] === $digitTwo;
    }

    private function paymentClient()
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->connectTimeout($this->httpConnectTimeout(5))->timeout($this->httpTimeout(30));
    }

    private function transferClient()
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->withdrawalKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->connectTimeout($this->httpConnectTimeout(5))->timeout($this->httpTimeout(30));
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
            'COMPLETED', 'COMPLETE', 'PAID', 'APPROVED', 'SUCCESS', 'SUCCEEDED', 'DONE' => 'completed',
            'REFUSED', 'FAILED', 'CANCELLED', 'CANCELED' => 'failed',
            'IN_QUEUE', 'IN_ANALYSIS', 'PROCESSING', 'PENDING', 'CREATED' => 'processing',
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
