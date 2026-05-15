<?php

declare(strict_types=1);

namespace App\Infrastructure\Acquirers;

use App\Domain\Acquirer\Contracts\AcquirerInterface;
use App\Models\Charge;
use App\Models\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EnkiAcquirer implements AcquirerInterface
{
    private string $publicKey;
    private string $secretKey;
    private string $withdrawalKey;
    private string $baseUrl;
    private ?string $webhookUrl;

    public function __construct(array $credentials)
    {
        $this->publicKey = (string) ($credentials['public_key'] ?? '');
        $this->secretKey = (string) ($credentials['secret_key'] ?? '');
        $this->withdrawalKey = (string) ($credentials['withdrawal_key'] ?? '');
        $this->baseUrl = rtrim((string) ($credentials['base_url'] ?? 'https://api.enki-bank.com'), '/');
        $this->webhookUrl = isset($credentials['webhook_url']) ? (string) $credentials['webhook_url'] : null;
    }

    public function getName(): string
    {
        return 'enki';
    }

    public function processPayment(Charge $charge, array $cardData): array
    {
        return [
            'success' => false,
            'error' => 'Enki Bank suporta apenas pagamentos via PIX nesta integração.',
        ];
    }

    public function refund(Charge $charge, float $amount): array
    {
        return [
            'success' => false,
            'error' => 'Reembolso da Enki não está exposto nesta integração.',
        ];
    }

    public function generatePixQrCode(Charge $charge): array
    {
        try {
            $payload = [
                'amount' => (int) $charge->amount,
                'payment_method' => 'PIX',
                'postback_url' => $this->resolveWebhookUrl('/api/v1/webhooks/enki'),
                'items' => $this->buildItems($charge),
                'customer' => $this->buildCustomerPayload($charge),
            ];

            $utm = $this->buildUtmString($charge);
            if ($utm !== null) {
                $payload['utm'] = $utm;
            }

            $diagnostics = $this->buildPixDiagnostics($payload);

            Log::info('Enki PIX request', [
                'charge_id' => $charge->id,
                'amount' => $charge->amount,
                'diagnostics' => $diagnostics,
            ]);

            $response = $this->getHttpClient()->post("{$this->baseUrl}/v1/transactions", $payload);

            if (!$response->successful()) {
                $error = $response->json();

                Log::error('Enki PIX error', [
                    'charge_id' => $charge->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'diagnostics' => $diagnostics,
                ]);

                return [
                    'success' => false,
                    'error' => Arr::get($error, 'message') ?? Arr::get($error, 'error') ?? 'Falha ao gerar PIX na Enki',
                    'raw_response' => $error,
                ];
            }

            $data = $response->json();
            $pix = Arr::get($data, 'pix', []);

            return [
                'success' => true,
                'external_id' => Arr::get($data, 'id'),
                'pix_qr_code' => Arr::get($pix, 'copy_paste'),
                'pix_copy_paste' => Arr::get($pix, 'copy_paste'),
                'pix_qr_code_url' => null,
                'pix_expires_at' => Arr::get($pix, 'expires_at'),
                'status' => $this->mapTransactionStatus((string) Arr::get($data, 'status', 'WAITING_PAYMENT')),
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('Enki PIX exception', [
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
            'error' => 'Enki Bank não suporta boleto nesta integração.',
        ];
    }

    public function getTransaction(string $transactionId): array
    {
        try {
            $response = $this->getHttpClient()->get("{$this->baseUrl}/v1/transactions/{$transactionId}");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => 'Consulta de transação Enki retornou ' . $response->status(),
                ];
            }

            $body = $response->json();
            $data = Arr::get($body, 'data', $body);

            return [
                'success' => true,
                'status' => (string) Arr::get($data, 'status', 'UNKNOWN'),
                'amount' => Arr::get($data, 'amount'),
                'fee' => Arr::get($data, 'fee'),
                'net_amount' => Arr::get($data, 'net_amount'),
                'paid_at' => Arr::get($data, 'paid_at'),
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('Enki getTransaction exception', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getBalance(): array
    {
        try {
            $response = $this->getHttpClient()->get("{$this->baseUrl}/v1/company/balance");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => 'Consulta de saldo Enki retornou ' . $response->status(),
                ];
            }

            $body = $response->json();
            $data = Arr::get($body, 'data', []);

            return [
                'success' => true,
                'available' => (int) Arr::get($data, 'available', 0),
                'reserved' => (int) Arr::get($data, 'reserved', 0),
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('Enki getBalance exception', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function sendPixTransfer(array $transferData): array
    {
        if ($this->withdrawalKey === '') {
            return [
                'success' => false,
                'error' => 'ENKI_WITHDRAWAL_KEY não configurada.',
            ];
        }

        try {
            $pixKeyType = $this->normalizePixKeyType((string) ($transferData['pix_key_type'] ?? 'EVP'));

            $payload = [
                'amount' => (int) ($transferData['amount_cents'] ?? round(((float) ($transferData['amount'] ?? 0)) * 100)),
                'pix_key' => (string) ($transferData['pix_key'] ?? ''),
                'pix_key_type' => $pixKeyType,
                'postback_url' => $this->resolveWebhookUrl('/api/v1/webhooks/enki'),
            ];

            $response = $this->getHttpClient([
                'x-withdrawal-key' => $this->withdrawalKey,
            ])->post("{$this->baseUrl}/v1/transfers", $payload);

            if (!$response->successful()) {
                $error = $response->json();

                Log::error('Enki transfer error', [
                    'withdrawal_id' => $transferData['external_ref'] ?? null,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'error' => Arr::get($error, 'message') ?? Arr::get($error, 'error') ?? 'Falha ao solicitar saque na Enki',
                    'raw_response' => $error,
                ];
            }

            $body = $response->json();
            $data = Arr::get($body, 'data', $body);

            return [
                'success' => true,
                'transfer_id' => Arr::get($data, 'id'),
                'amount' => ((int) Arr::get($data, 'amount', 0)) / 100,
                'net_amount' => ((int) Arr::get($data, 'net_amount', 0)) / 100,
                'fee' => Arr::get($data, 'fee'),
                'status' => $this->mapWithdrawalStatus((string) Arr::get($data, 'status', 'PROCESSING')),
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('Enki transfer exception', [
                'withdrawal_id' => $transferData['external_ref'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function getHttpClient(array $extraHeaders = [])
    {
        $client = Http::withHeaders(array_merge([
            'Authorization' => 'Basic ' . base64_encode($this->publicKey . ':' . $this->secretKey),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $extraHeaders))->connectTimeout(3)->timeout(10);

        if (config('app.env') === 'local') {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    private function buildItems(Charge $charge): array
    {
        $metadata = $charge->metadata ?? [];
        $products = is_array($metadata['products'] ?? null) ? $metadata['products'] : [];
        $orderBumps = is_array($metadata['order_bumps'] ?? null) ? $metadata['order_bumps'] : [];

        $productIds = [];
        foreach ($products as $product) {
            if (!empty($product['id'])) {
                $productIds[] = $product['id'];
            }
            if (!empty($product['product_id'])) {
                $productIds[] = $product['product_id'];
            }
        }
        foreach ($orderBumps as $orderBump) {
            if (!empty($orderBump['product_id'])) {
                $productIds[] = $orderBump['product_id'];
            }
        }

        $productMap = Product::query()
            ->whereIn('id', array_values(array_unique($productIds)))
            ->get(['id', 'name', 'price'])
            ->keyBy('id');

        $items = [];

        if (!empty($products)) {
            foreach ($products as $index => $product) {
                $productId = $product['id'] ?? $product['product_id'] ?? null;
                $productModel = $productId ? $productMap->get($productId) : null;
                $unitPrice = (int) ($product['price'] ?? $productModel?->price ?? 0);
                $quantity = max(1, (int) ($product['quantity'] ?? 1));
                $title = (string) ($product['name'] ?? $productModel?->name ?? $charge->description ?? 'Pagamento PIX');

                $items[] = [
                    'title' => $title,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'tangible' => (bool) ($product['tangible'] ?? false),
                    'external_ref' => (string) ($product['external_ref'] ?? $productId ?? $charge->id . ':' . $index),
                ];
            }
        }

        foreach ($orderBumps as $index => $orderBump) {
            $productId = $orderBump['product_id'] ?? null;
            $productModel = $productId ? $productMap->get($productId) : null;

            $items[] = [
                'title' => (string) ($orderBump['title'] ?? $productModel?->name ?? 'Order bump'),
                'unit_price' => (int) ($orderBump['price'] ?? 0),
                'quantity' => 1,
                'tangible' => false,
                'external_ref' => (string) ($productId ?? $charge->id . ':bump:' . $index),
            ];
        }

        $calculatedAmount = 0;
        foreach ($items as $item) {
            $calculatedAmount += ((int) $item['unit_price']) * ((int) $item['quantity']);
        }

        if ($items !== [] && $calculatedAmount === (int) $charge->amount) {
            return $items;
        }

        return [[
            'title' => (string) ($charge->description ?? 'Pagamento PIX'),
            'unit_price' => (int) $charge->amount,
            'quantity' => 1,
            'tangible' => false,
            'external_ref' => (string) $charge->id,
        ]];
    }

    private function buildPixDiagnostics(array $payload): array
    {
        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $document = is_array($customer['document'] ?? null) ? $customer['document'] : [];
        $phoneDigits = preg_replace('/\D+/', '', (string) ($customer['phone'] ?? ''));
        $documentDigits = preg_replace('/\D+/', '', (string) ($document['number'] ?? ''));

        return [
            'customer_name_present' => trim((string) ($customer['name'] ?? '')) !== '',
            'customer_email_present' => trim((string) ($customer['email'] ?? '')) !== '',
            'customer_phone_digits' => strlen($phoneDigits),
            'customer_phone_is_fallback' => $phoneDigits === '11999999999',
            'customer_document_digits' => strlen($documentDigits),
            'customer_document_type' => $document['type'] ?? null,
            'items_count' => count($payload['items'] ?? []),
            'postback_host' => parse_url((string) ($payload['postback_url'] ?? ''), PHP_URL_HOST),
            'has_utm' => isset($payload['utm']),
        ];
    }

    private function buildCustomerPayload(Charge $charge): array
    {
        $customer = $charge->customer ?? [];
        $document = preg_replace('/\D+/', '', (string) ($customer['document'] ?? ''));
        $phone = $this->resolveCustomerPhone($charge, $customer);

        return [
            'name' => $this->normalizeCustomerName((string) ($customer['name'] ?? 'Cliente Vector Pagamentos')),
            'email' => (string) ($customer['email'] ?? 'cliente@example.com'),
            'phone' => $phone,
            'document' => [
                'number' => $document,
                'type' => strlen($document) > 11 ? 'CNPJ' : 'CPF',
            ],
        ];
    }

    private function resolveCustomerPhone(Charge $charge, array $customer): string
    {
        $billing = is_array($charge->billing ?? null) ? $charge->billing : [];
        $metadata = is_array($charge->metadata ?? null) ? $charge->metadata : [];

        $candidates = [
            $customer['phone'] ?? null,
            $customer['telefone'] ?? null,
            $billing['phone'] ?? null,
            $billing['telefone'] ?? null,
            $metadata['customer_phone'] ?? null,
            $metadata['phone'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $digits = preg_replace('/\D+/', '', (string) $candidate);

            if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
                $digits = substr($digits, 2);
            }

            if (strlen($digits) >= 10) {
                return $digits;
            }
        }

        Log::warning('Enki using fallback customer phone', [
            'charge_id' => $charge->id,
        ]);

        return '11999999999';
    }

    private function buildUtmString(Charge $charge): ?string
    {
        $tracking = is_array($charge->metadata['tracking'] ?? null) ? $charge->metadata['tracking'] : [];
        $params = array_filter([
            'utm_source' => $tracking['utm_source'] ?? null,
            'utm_medium' => $tracking['utm_medium'] ?? null,
            'utm_campaign' => $tracking['utm_campaign'] ?? null,
            'utm_content' => $tracking['utm_content'] ?? null,
            'utm_term' => $tracking['utm_term'] ?? null,
            'src' => $tracking['src'] ?? null,
            'sck' => $tracking['sck'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        if ($params === []) {
            return null;
        }

        return '?' . http_build_query($params);
    }

    private function normalizeCustomerName(string $name): string
    {
        $trimmed = trim(preg_replace('/\s+/', ' ', $name));

        if ($trimmed === '') {
            return 'Cliente Vector Pagamentos';
        }

        if (str_contains($trimmed, ' ')) {
            return $trimmed;
        }

        return $trimmed . ' Cliente';
    }

    private function resolveWebhookUrl(string $path): string
    {
        $base = $this->webhookUrl ?: config('app.url');

        return rtrim((string) $base, '/') . $path;
    }

    private function normalizePixKeyType(string $pixKeyType): string
    {
        $normalized = strtoupper(trim($pixKeyType));

        return match ($normalized) {
            'RANDOM' => 'EVP',
            'CPF', 'CNPJ', 'EMAIL', 'PHONE', 'EVP' => $normalized,
            default => 'EVP',
        };
    }

    private function mapTransactionStatus(string $status): string
    {
        return match (strtolower($status)) {
            'paid' => 'approved',
            'refunded' => 'refunded',
            'waiting_payment' => 'pending',
            default => 'pending',
        };
    }

    private function mapWithdrawalStatus(string $status): string
    {
        return match (strtolower($status)) {
            'completed' => 'completed',
            'failed', 'refused' => 'failed',
            default => 'processing',
        };
    }
}
