<?php

declare(strict_types=1);

namespace App\Infrastructure\Acquirers;

use App\Domain\Acquirer\Contracts\AcquirerInterface;
use App\Domain\Routing\Contracts\AcquirerCredentialRepositoryInterface;
use App\Models\AcquirerConfig;
use App\Models\Charge;
use App\Models\Transaction;
use App\Services\PaymentFailureMessage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Koku Pay API v1 (https://docs.kokupay.com) — somente Pix de entrada.
 *
 * Valores em centavos (a Koku devolve como string). Saques não são integrados:
 * a Koku só transfere para contas da própria empresa verificadas no painel dela.
 *
 * Toda criação usa Idempotency-Key estável (charge-{id}) e order_reference = id
 * da cobrança, então repetir a chamada nunca gera um segundo Pix.
 */
class KokuPayAcquirer implements AcquirerInterface
{
    use Concerns\ConfigurableTimeouts;

    public const KEY = 'kokupay';

    private const DEFAULT_BASE_URL = 'https://api.kokupay.com/v1';

    /** Validade do QR aceita pela Koku. */
    private const MIN_EXPIRES_SECONDS = 60;
    private const MAX_EXPIRES_SECONDS = 604800;
    private const DEFAULT_EXPIRES_SECONDS = 86400;

    /** Tolerância do carimbo de tempo da assinatura do webhook. */
    public const SIGNATURE_TOLERANCE_SECONDS = 300;

    public function __construct(private readonly array $credentials) {}

    /** Instância com as credenciais globais do cofre, ou null se a Koku não estiver configurada/ativa. */
    public static function fromStoredCredentials(): ?self
    {
        $config = AcquirerConfig::where('acquirer_key', self::KEY)->first();
        if (!$config || !$config->enabled) {
            return null;
        }

        $credentials = app(AcquirerCredentialRepositoryInterface::class)->decrypted($config);

        return empty($credentials['api_key']) ? null : new self($credentials);
    }

    public function getName(): string
    {
        return self::KEY;
    }

    public function processPayment(Charge $charge, array $cardData): array
    {
        return ['success' => false, 'error' => 'Koku: cartão não suportado; somente Pix.'];
    }

    public function generateBoleto(Charge $charge): array
    {
        return ['success' => false, 'error' => 'Koku: boleto não suportado; somente Pix.'];
    }

    public function generatePixQrCode(Charge $charge): array
    {
        $customer = $charge->customer ?? [];
        $document = preg_replace('/\D/', '', (string) ($customer['document']['number'] ?? (is_scalar($customer['document'] ?? null) ? $customer['document'] : '')));
        $phone = preg_replace('/\D/', '', (string) ($customer['phone'] ?? ''));

        foreach (['name' => $customer['name'] ?? null, 'document' => $document, 'email' => $customer['email'] ?? null] as $field => $value) {
            $error = PaymentFailureMessage::customerField($field, $value);
            if ($error !== null) {
                return ['success' => false, 'failure_reason' => 'validation_error', 'error' => $error];
            }
        }
        if ((int) $charge->amount < 1) {
            return ['success' => false, 'failure_reason' => 'validation_error', 'error' => 'Valor da cobrança inválido. Informe um valor maior que zero.'];
        }

        $payer = ['name' => mb_substr((string) $customer['name'], 0, 200), 'document' => $document, 'email' => (string) $customer['email']];
        // A emissão exige telefone com DDD; sem ele a Koku pode recusar, então só enviamos se for válido
        if (strlen($phone) >= 10 && strlen($phone) <= 13) {
            $payer['phone'] = $phone;
        }

        $payload = [
            'order_reference' => (string) $charge->id,
            'amount_cents' => (int) $charge->amount,
            // Sem dados pessoais em description/metadata (recomendação da Koku)
            'description' => mb_substr($charge->description ?: 'Pagamento', 0, 500),
            'payer' => $payer,
            'expires_in_seconds' => $this->expiresInSeconds($charge),
            'metadata' => ['charge_id' => (string) $charge->id],
        ];

        try {
            $response = $this->client()
                ->withHeaders(['Idempotency-Key' => 'charge-' . $charge->id])
                ->post($this->url('/pix/charges'), $payload);
        } catch (\Throwable $e) {
            // Sem resposta: a cobrança pode ter sido criada. Repetir com a mesma chave é seguro.
            Log::warning('Koku: criação de cobrança sem resposta', ['charge_id' => $charge->id, 'error' => $e->getMessage()]);

            return ['success' => false, 'failure_reason' => 'ambiguous_result', 'error' => 'Koku indisponível no momento (timeout). Tente novamente.'];
        }

        if (!$response->successful()) {
            return $this->failureFromResponse($response, $customer);
        }

        $data = $response->json();
        if (!is_array($data) || empty($data['id'])) {
            return ['success' => false, 'failure_reason' => 'ambiguous_result', 'error' => 'Koku: resposta de emissão sem identificador.'];
        }

        // "created" = emissão ainda em andamento: consulta de novo em vez de recriar
        for ($attempt = 0; ($data['status'] ?? null) === 'created' && $attempt < 3; $attempt++) {
            usleep(1_500_000);
            $fresh = $this->fetchCharge((string) $data['id']);
            if ($fresh !== null) {
                $data = $fresh;
            }
        }

        $status = (string) ($data['status'] ?? '');
        if ($status === 'failed') {
            return ['success' => false, 'failure_reason' => 'declined', 'error' => (string) ($data['failure_message'] ?? 'Emissão recusada pela Koku.')];
        }

        $copyPaste = $data['pix']['qr_code_payload'] ?? null;
        if ($status !== 'pending' || !is_string($copyPaste) || $copyPaste === '') {
            return ['success' => false, 'failure_reason' => 'ambiguous_result', 'error' => 'Koku: o Pix ainda não foi emitido. Tente novamente em instantes.'];
        }
        if ((int) ($data['amount_cents'] ?? 0) !== (int) $charge->amount || (string) ($data['order_reference'] ?? '') !== (string) $charge->id) {
            Log::critical('Koku: cobrança emitida não confere com o pedido', [
                'charge_id' => $charge->id,
                'koku_charge_id' => $data['id'],
                'amount' => $data['amount_cents'] ?? null,
                'order_reference' => $data['order_reference'] ?? null,
            ]);

            return ['success' => false, 'failure_reason' => 'ambiguous_result', 'error' => 'Koku: cobrança emitida com dados divergentes.'];
        }

        $expiresAt = $data['pix']['expires_at'] ?? $data['expires_at'] ?? null;
        $result = [
            'success' => true,
            'external_id' => $data['id'],
            'transaction_id' => $data['id'],
            'status' => 'pending',
            'pix_qr_code' => $copyPaste,
            'pix_copy_paste' => $copyPaste,
            'pix_expires_at' => $expiresAt,
            'data' => $data,
        ];

        if ($charge->exists) {
            DB::transaction(function () use ($charge, $data, $copyPaste, $expiresAt): void {
                $charge->update([
                    'acquirer' => self::KEY,
                    'acquirer_transaction_id' => $data['id'],
                    'external_id' => $data['id'],
                    'status' => 'pending',
                    'pix_qr_code' => $copyPaste,
                    'pix_copy_paste' => $copyPaste,
                    'expires_at' => $expiresAt ?? $charge->expires_at,
                ]);
                Transaction::firstOrCreate(['charge_id' => $charge->id, 'acquirer_tid' => $data['id']], [
                    'acquirer_id' => DB::table('acquirers')->where('slug', self::KEY)->value('id'),
                    'type' => 'authorization',
                    'status' => 'pending',
                    'amount' => (int) $charge->amount,
                    'net_amount' => (int) $charge->amount,
                ]);
            });
        }

        return $result;
    }

    /**
     * Consulta normalizada de uma cobrança (usada na sincronização e no roteamento).
     *
     * @return array{success: bool, error?: string, id?: string, status?: string, amount_cents?: int,
     *               order_reference?: string, fee_cents?: ?int, net_cents?: ?int, paid_at?: ?string,
     *               end_to_end_id?: ?string, late_payment?: bool, failure_message?: ?string, refunded_cents?: int}
     */
    public function getTransaction(string $id): array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) {
            return ['success' => false, 'error' => 'Identificador de cobrança Koku inválido.'];
        }

        $data = $this->fetchCharge($id);
        if ($data === null) {
            return ['success' => false, 'error' => 'Koku: consulta temporariamente indisponível.'];
        }

        $cents = static fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null;

        return [
            'success' => true,
            'id' => (string) $data['id'],
            'status' => (string) ($data['status'] ?? ''),
            'amount_cents' => (int) ($data['amount_cents'] ?? 0),
            'order_reference' => (string) ($data['order_reference'] ?? ''),
            'fee_cents' => $cents($data['fee_cents'] ?? null),
            'net_cents' => $cents($data['net_cents'] ?? null),
            'refunded_cents' => (int) ($data['refunded_cents'] ?? 0),
            'paid_at' => $data['paid_at'] ?? null,
            'end_to_end_id' => $data['end_to_end_id'] ?? null,
            'late_payment' => (bool) ($data['late_payment'] ?? false),
            'failure_message' => $data['failure_message'] ?? null,
        ];
    }

    public function refund(Charge $charge, float $amount): array
    {
        $koku = (string) ($charge->acquirer_transaction_id ?? '');
        $cents = (int) round($amount * 100);
        if ($koku === '' || $cents < 1) {
            return ['success' => false, 'error' => 'Koku: cobrança ou valor de devolução inválidos.'];
        }

        // Estável por operação: a mesma devolução repetida não é enviada duas vezes
        $key = sprintf('refund-%s-%d-%d', $charge->id, (int) ($charge->refunded_amount ?? 0), $cents);

        try {
            $response = $this->client()
                ->withHeaders(['Idempotency-Key' => $key])
                ->post($this->url('/pix/charges/' . rawurlencode($koku) . '/refunds'), [
                    'amount_cents' => $cents,
                    'reason' => 'Estorno solicitado pela plataforma',
                ]);
        } catch (\Throwable) {
            return ['success' => false, 'ambiguous' => true, 'error' => 'Koku: devolução sem confirmação. Confira no painel antes de repetir.'];
        }

        if (!$response->successful()) {
            $reason = $response->json('error.details.reason');

            return ['success' => false, 'error' => match (true) {
                $reason === 'manual_refund_required' => 'Koku: esta devolução precisa ser feita junto com a equipe Koku.',
                $response->json('error.code') === 'insufficient_funds' => 'Koku: saldo insuficiente para a devolução.',
                default => 'Koku: ' . $this->errorMessage($response),
            }];
        }

        return [
            'success' => true,
            'refund_id' => $response->json('id'),
            'status' => $response->json('status'),
            'data' => $response->json(),
        ];
    }

    /**
     * Código Pix da cobrança mais recente já emitida, para ler a nominal (campo 59 do BR Code)
     * sem criar cobrança nova nem enviar dados de pagador. Null se ainda não houver nenhuma.
     */
    public function latestPixPayload(): ?string
    {
        try {
            $response = $this->client()->get($this->url('/pix/charges'), ['limit' => 20]);
        } catch (\Throwable) {
            return null;
        }

        foreach ((array) ($response->successful() ? $response->json('items') : []) as $item) {
            $payload = is_array($item) ? ($item['pix']['qr_code_payload'] ?? null) : null;
            if (is_string($payload) && $payload !== '') {
                return $payload;
            }
        }

        return null;
    }

    /** Saldo em centavos (o roteamento usa para a verificação de saúde). */
    public function getBalance(): array
    {
        try {
            $response = $this->client()->get($this->url('/balance'));
        } catch (\Throwable) {
            return ['success' => false, 'error' => 'Koku: consulta de saldo indisponível.'];
        }

        if (!$response->successful() || !is_array($response->json())) {
            return ['success' => false, 'error' => 'Koku: consulta de saldo recusada (HTTP ' . $response->status() . ').'];
        }

        return array_merge($response->json(), ['success' => true]);
    }

    /**
     * Confere x-koku-signature: "t=<unix>,v1=<hex>", HMAC-SHA256 de "<t>.<corpo bruto>".
     */
    public static function verifySignature(string $rawBody, ?string $header, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || $header === null
            || !preg_match('/(?:^|,)\s*t=(\d+)/', $header, $t)
            || !preg_match('/(?:^|,)\s*v1=([0-9a-f]{64})/', $header, $v1)) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $t[1]) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $t[1] . '.' . $rawBody, $secret), $v1[1]);
    }

    public function webhookSecret(): string
    {
        return (string) ($this->credentials['webhook_secret'] ?? '');
    }

    private function fetchCharge(string $id): ?array
    {
        try {
            $response = $this->client()->get($this->url('/pix/charges/' . rawurlencode($id)));
        } catch (\Throwable) {
            return null;
        }

        $data = $response->json();

        return $response->successful() && is_array($data) && !empty($data['id']) ? $data : null;
    }

    private function expiresInSeconds(Charge $charge): int
    {
        $expiresAt = $charge->expires_at;
        if ($expiresAt === null || $expiresAt->isPast()) {
            return self::DEFAULT_EXPIRES_SECONDS;
        }

        return max(self::MIN_EXPIRES_SECONDS, min(self::MAX_EXPIRES_SECONDS, (int) now()->diffInSeconds($expiresAt)));
    }

    private function failureFromResponse(Response $response, array $customer): array
    {
        $status = $response->status();
        $code = (string) $response->json('error.code');
        $reason = (string) $response->json('error.details.reason');
        $message = $this->errorMessage($response);

        Log::warning('Koku: emissão recusada', [
            'http_status' => $status,
            'code' => $code,
            'reason' => $reason,
            'correlation_id' => $response->json('error.correlation_id') ?? $response->header('X-Correlation-Id'),
        ]);

        // As palavras-chave das mensagens guiam a classificação do roteador (FailureReason::fromMessage)
        return match (true) {
            $status === 401 || $status === 403 => ['success' => false, 'failure_reason' => 'authentication_failed',
                'error' => 'Koku: credenciais inválidas ou sem permissão (api key).'],
            $status === 503 && $reason === 'account_not_enabled' => ['success' => false, 'failure_reason' => 'not_configured',
                'error' => 'Koku: recebimento Pix ainda não habilitado para a conta (não configurado).'],
            $status === 429 || $status >= 500 => ['success' => false, 'failure_reason' => 'ambiguous_result', 'http_status' => $status,
                'error' => 'Koku indisponível no momento (HTTP ' . $status . ').'],
            $status === 422 || $status === 400 => ['success' => false, 'failure_reason' => 'validation_error', 'http_status' => $status,
                'error' => PaymentFailureMessage::fromProvider('Dados inválidos: ' . $message, 'Koku', $customer, $status)],
            default => ['success' => false, 'http_status' => $status, 'error' => 'Koku: ' . $message],
        };
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('error.message');
        $message = is_string($message) ? $message : 'requisição recusada (HTTP ' . $response->status() . ').';
        $fields = $response->json('error.details.fields');
        if (is_array($fields)) {
            $names = array_filter(array_map(static fn ($f) => is_array($f) ? ($f['field'] ?? null) : null, $fields));
            if ($names !== []) {
                $message .= ' Campos: ' . implode(', ', $names) . '.';
            }
        }

        return mb_substr(strip_tags($message), 0, 400);
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) ($this->credentials['api_key'] ?? ''))
            ->acceptJson()->asJson()
            ->timeout($this->httpTimeout(25))
            ->connectTimeout($this->httpConnectTimeout(5))
            // A Koku recusa (400 empty_json_body) requisição sem corpo com Content-Type JSON
            ->withRequestMiddleware(static fn ($request) => $request->getBody()->getSize() === 0
                ? $request->withoutHeader('Content-Type')
                : $request);
    }

    private function url(string $path): string
    {
        return rtrim((string) ($this->credentials['base_url'] ?? '') ?: self::DEFAULT_BASE_URL, '/') . $path;
    }
}
