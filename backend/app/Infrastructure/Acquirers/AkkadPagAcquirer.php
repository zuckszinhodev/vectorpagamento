<?php

declare(strict_types=1);

namespace App\Infrastructure\Acquirers;

use App\Domain\Acquirer\Contracts\AcquirerInterface;
use App\Models\Charge;
use App\Services\PaymentFailureMessage;
use App\Models\Transaction;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** AkkadPag API v1. Amounts and balances are integer BRL cents. */
class AkkadPagAcquirer implements AcquirerInterface
{
    use Concerns\ConfigurableTimeouts;

    public const MIN_PIX_AMOUNT = 500;

    public const MIN_PIX_MESSAGE = 'O valor mínimo para gerar Pix na adquirente AkkadPag é R$ 5,00.';

    /** AkkadPag rejects transfers whose submitted (gross) amount is below this. */
    public const MIN_WITHDRAWAL_AMOUNT = 2000;

    public function __construct(private readonly array $credentials) {}

    public function getName(): string
    {
        return 'akkadpag';
    }

    public function processPayment(Charge $charge, array $cardData): array
    {
        return ['success' => false, 'error' => 'AkkadPag: cartão não habilitado; somente PIX e boleto.'];
    }

    public function refund(Charge $charge, float $amount): array
    {
        return ['success' => false, 'error' => 'AkkadPag: a documentação não disponibiliza endpoint de estorno.'];
    }

    public function generatePixQrCode(Charge $charge): array
    {
        return $this->create($charge, 'PIX');
    }

    public function generateBoleto(Charge $charge): array
    {
        return $this->create($charge, 'BOLETO');
    }

    public function getBalance(): array
    {
        return $this->read('/company/balance');
    }

    public function getCompanyDetails(): array
    {
        return $this->read('/company/details');
    }

    public static function mapTransferStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'COMPLETED' => 'completed',
            'REFUSED' => 'failed',
            'CANCELLED' => 'cancelled',
            default => 'processing',
        };
    }

    public function getTransfer(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) {
            return null;
        }
        $result = $this->read('/transfers/'.rawurlencode($id));
        return ($result['success'] ?? false) && ($result['id'] ?? null) === $id ? $result : null;
    }

    public function sendPixTransfer(array $transferData): array
    {
        // This API does not document idempotency. Only the durable, locked
        // withdrawal flow may dispatch; generic fallback/splitting must not retry.
        if (($transferData['_akkadpag_dispatch_authorized'] ?? false) !== true) {
            return ['success' => false, 'error' => 'AkkadPag: processe o saque pelo fluxo protegido de aprovação.'];
        }
        if (empty($this->credentials['withdrawal_key'])) {
            return ['success' => false, 'error' => 'Chave de saque externo da AkkadPag não configurada.'];
        }
        $net = (int) ($transferData['amount_cents'] ?? 0);
        $type = strtoupper((string) ($transferData['pix_key_type'] ?? ''));
        $type = $type === 'RANDOM' ? 'EVP' : $type;
        if ($net < 1 || empty($transferData['pix_key']) || !in_array($type, ['CPF', 'CNPJ', 'EMAIL', 'PHONE', 'EVP'], true)) {
            return ['success' => false, 'error' => 'Valor ou chave Pix do saque inválidos.'];
        }
        // AkkadPag deducts its API fee from the submitted amount. Gross up the
        // request so the beneficiary still receives the net promised by our UI.
        $details = $this->getCompanyDetails();
        $fee = $details['fees']['withdraw_api'] ?? null;
        if (!($details['success'] ?? false) || !is_array($fee)
            || !is_numeric($fee['fixed'] ?? null) || !is_numeric($fee['percent'] ?? null)) {
            return ['success' => false, 'error' => 'Não foi possível consultar a taxa de saque da AkkadPag. Nenhum saque foi enviado.'];
        }
        $fixed = (float) $fee['fixed'];
        $percent = (float) $fee['percent'];
        if ($fixed < 0 || $percent < 0 || $percent >= 100) {
            return ['success' => false, 'error' => 'Taxa de saque inválida na AkkadPag.'];
        }
        $gross = (int) ceil(($net + $fixed) / (1 - $percent / 100));
        $reserved = (int) ($transferData['reserved_amount_cents'] ?? 0);
        // Below the provider minimum, top up from the already-reserved platform
        // fee: the beneficiary receives more than the promised net, never less.
        if ($gross < self::MIN_WITHDRAWAL_AMOUNT) {
            if ($reserved < self::MIN_WITHDRAWAL_AMOUNT) {
                return ['success' => false, 'error' => 'O valor mínimo de saque pela AkkadPag é R$ 20,00 (valor total do saque, com taxas).'];
            }
            $gross = self::MIN_WITHDRAWAL_AMOUNT;
            $net = (int) floor($gross * (1 - $percent / 100) - $fixed);
        }
        if ($gross > $reserved) {
            return ['success' => false, 'error' => 'A taxa da AkkadPag supera a taxa reservada para este saque. Ajuste as taxas antes de solicitar novamente.'];
        }
        $payload = ['amount' => $gross, 'pix_key' => (string) $transferData['pix_key'], 'pix_key_type' => $type];
        $document = preg_replace('/\D/', '', (string) ($transferData['destination_document'] ?? ''));
        if ($document !== '') {
            $payload['document'] = $document;
        }
        try {
            $response = $this->client()->withHeaders(['x-withdrawal-key' => $this->credentials['withdrawal_key']])
                ->post($this->url('/transfers'), $payload);
            if (!$response->successful()) {
                // 5xx/timeout may occur after acceptance; keep funds reserved.
                $ambiguous = $response->serverError() || in_array($response->status(), [408, 409], true);
                $message = $response->json('message');
                $message = is_string($message) ? strip_tags($message) : 'A adquirente recusou a solicitação de saque.';
                foreach (['public_key', 'secret_key', 'withdrawal_key'] as $key) {
                    if (!empty($this->credentials[$key])) {
                        $message = str_replace($this->credentials[$key], '[redigido]', $message);
                    }
                }
                return ['success' => false, 'ambiguous' => $ambiguous, 'error' => $ambiguous
                    ? 'AkkadPag não confirmou o resultado. Confira no provedor antes de repetir o saque.'
                    : 'AkkadPag: '.mb_substr($message, 0, 350)];
            }
            $data = $response->json('data');
            if (!is_array($data) || empty($data['id'])) {
                return ['success' => false, 'ambiguous' => true, 'error' => 'AkkadPag respondeu sem o ID do saque. É necessário conciliar antes de repetir.'];
            }
            return [
                'success' => true, 'transfer_id' => (string) $data['id'], 'status' => self::mapTransferStatus($data['status'] ?? ''),
                'provider_status' => $data['status'] ?? '', 'provider_amount' => $data['amount'] ?? null,
                'provider_net_amount' => $data['net_amount'] ?? null, 'provider_fee' => $data['fee'] ?? null,
                'requested_amount' => $gross, 'expected_net_amount' => $net,
            ];
        } catch (\Throwable) {
            return ['success' => false, 'ambiguous' => true, 'error' => 'Sem confirmação da AkkadPag. O saldo permanece reservado para conferência; não reenvie o saque.'];
        }
    }

    public function getTransaction(string $id): array
    {
        if (! preg_match('/^[a-f0-9-]{36}$/i', $id)) {
            return ['success' => false, 'error' => 'Identificador de transação inválido.'];
        }

        return $this->read('/transactions/'.rawurlencode($id));
    }

    private function client(): PendingRequest
    {
        return Http::withBasicAuth($this->credentials['public_key'] ?? '', $this->credentials['secret_key'] ?? '')
            ->acceptJson()->asJson()->timeout($this->httpTimeout(25))->connectTimeout($this->httpConnectTimeout(5));
    }

    private function url(string $path): string
    {
        return rtrim($this->credentials['base_url'] ?? 'https://api.akkadpag.com/v1', '/').$path;
    }

    private function read(string $path): array
    {
        try {
            $response = $this->client()->get($this->url($path));
            $body = $response->json();
            if (! $response->successful() || ! is_array($body)) {
                return ['success' => false, 'error' => 'AkkadPag: consulta recusada (HTTP '.$response->status().').'];
            }
            $data = $body['data'] ?? $body;

            return is_array($data) ? array_merge($data, ['success' => true]) : ['success' => false, 'error' => 'Resposta inválida da AkkadPag.'];
        } catch (\Throwable $e) {
            preg_match('/cURL error (\d+)/', $e->getMessage(), $curlError);

            return ['success' => false, 'error' => 'AkkadPag: consulta temporariamente indisponível.', 'transport_code' => isset($curlError[1]) ? (int) $curlError[1] : null];
        }
    }

    private function create(Charge $charge, string $method): array
    {
        if ($method === 'PIX' && (int) $charge->amount < self::MIN_PIX_AMOUNT) {
            return ['success' => false, 'error' => self::MIN_PIX_MESSAGE, 'failure_reason' => 'validation_error'];
        }

        $customer = $charge->customer ?? [];
        $document = preg_replace('/\D/', '', (string) ($customer['document']['number'] ?? (is_scalar($customer['document'] ?? null) ? $customer['document'] : '')));
        $phone = preg_replace('/\D/', '', (string) ($customer['phone'] ?? ''));
        foreach (['phone' => $customer['phone'] ?? null, 'document' => $document,
            'email' => $customer['email'] ?? null, 'name' => $customer['name'] ?? null] as $field => $value) {
            $error = PaymentFailureMessage::customerField($field, $value);
            if ($error !== null) {
                return ['success' => false, 'failure_reason' => 'validation_error', 'error' => $error];
            }
        }
        if ((int) $charge->amount < 1) {
            return ['success' => false, 'failure_reason' => 'validation_error', 'error' => 'Valor da cobrança inválido. Informe um valor maior que zero.'];
        }
        $payload = [
            'amount' => (int) $charge->amount,
            'payment_method' => $method,
            // One consolidated item preserves discounts, bumps and shipping exactly.
            'items' => [['title' => mb_substr($charge->description ?: 'Pagamento', 0, 200), 'unit_price' => (int) $charge->amount, 'quantity' => 1, 'tangible' => false, 'external_ref' => (string) $charge->id]],
            'customer' => [
                'name' => $customer['name'], 'email' => $customer['email'],
                'phone' => $phone,
                'document' => $method === 'BOLETO' ? $document : ['number' => $document, 'type' => strlen($document) === 14 ? 'CNPJ' : 'CPF'],
            ],
            'postback_url' => $this->credentials['webhook_url'] ?? rtrim((string) config('app.url'), '/').'/api/v1/webhooks/akkadpag',
        ];
        if ($method === 'BOLETO') {
            $payload['boleto_expiration_days'] = max(1, (int) ($this->credentials['boleto_expiration_days'] ?? 3));
        }
        if ($payload['postback_url'] === '') {
            unset($payload['postback_url']);
        }
        try {
            // No automatic retry: the published API documents no idempotency contract.
            $response = $this->client()->post($this->url('/transactions'), $payload);
            if (! $response->successful()) {
                $message = $response->json('message');
                $errors = $response->json('errors');
                if (is_array($errors)) {
                    $details = PaymentFailureMessage::validationDetails($errors);
                    if ($details !== []) {
                        $message = implode('; ', $details);
                    }
                }
                $message = is_array($message) ? implode('; ', array_filter($message, 'is_string')) : (is_string($message) ? $message : '');
                foreach (['public_key', 'secret_key'] as $key) {
                    if (! empty($this->credentials[$key])) {
                        $message = str_replace($this->credentials[$key], '[redigido]', $message);
                    }
                }
                $message = mb_substr(strip_tags($message), 0, 500);

                return ['success' => false, 'failure_reason' => $response->serverError() ? 'ambiguous_result' : null, 'error' => PaymentFailureMessage::fromProvider($message, 'AkkadPag', $customer, $response->status()), 'http_status' => $response->status()];
            }
            $body = $response->json();
            $data = is_array($body) ? ($body['data'] ?? $body) : [];
            if (! is_array($data) || empty($data['id']) || ($method === 'PIX' && empty($data['pix']['copy_paste']))
                || (isset($data['amount']) && (int) $data['amount'] !== (int) $charge->amount)
                || ($method === 'BOLETO' && (empty($data['boleto']['barcode']) || empty($data['boleto']['url'])))) {
                return ['success' => false, 'failure_reason' => 'ambiguous_result', 'error' => 'AkkadPag: resposta de emissão sem identificador ou dados de pagamento.'];
            }
            $result = [
                'success' => true, 'external_id' => $data['id'], 'transaction_id' => $data['id'],
                'status' => 'pending', 'pix_qr_code' => $data['pix']['copy_paste'] ?? null,
                'pix_copy_paste' => $data['pix']['copy_paste'] ?? null,
                'pix_expires_at' => $data['pix']['expires_at'] ?? null,
                'boleto_url' => $data['boleto']['url'] ?? null, 'boleto_barcode' => $data['boleto']['barcode'] ?? null,
                'boleto_due_date' => $data['boleto']['expires_at'] ?? null, 'data' => $data,
            ];
            if ($charge->exists) {
                DB::transaction(function () use ($charge, $data, $result): void {
                    $charge->update([
                        'acquirer' => 'akkadpag', 'acquirer_transaction_id' => $data['id'],
                        'external_id' => $data['id'], 'status' => 'pending',
                        'pix_qr_code' => $result['pix_qr_code'], 'pix_copy_paste' => $result['pix_copy_paste'],
                        'boleto_url' => $result['boleto_url'], 'boleto_barcode' => $result['boleto_barcode'],
                        'expires_at' => $result['pix_expires_at'] ?? $result['boleto_due_date'] ?? $charge->expires_at,
                    ]);
                    Transaction::firstOrCreate(['charge_id' => $charge->id, 'acquirer_tid' => $data['id']], [
                        'acquirer_id' => DB::table('acquirers')->where('slug', 'akkadpag')->value('id'),
                        'type' => 'authorization', 'status' => 'pending', 'amount' => (int) $charge->amount,
                        'net_amount' => (int) $charge->amount,
                    ]);
                });
            }

            return $result;
        } catch (\Throwable) {
            return ['success' => false, 'failure_reason' => 'ambiguous_result', 'error' => 'AkkadPag: resultado da emissão indeterminado; confira no provedor antes de repetir.'];
        }
    }
}
