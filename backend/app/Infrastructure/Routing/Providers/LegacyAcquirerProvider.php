<?php

declare(strict_types=1);

namespace App\Infrastructure\Routing\Providers;

use App\Domain\Routing\Contracts\BalanceInquiryInterface;
use App\Domain\Routing\Contracts\PaymentProviderInterface;
use App\Domain\Routing\Enums\FailureReason;
use App\Domain\Routing\Enums\PaymentMethod;
use App\Domain\Routing\ValueObjects\AcquirerBalance;
use App\Domain\Routing\ValueObjects\ProbeResult;
use App\Domain\Routing\ValueObjects\ProviderRequest;
use App\Domain\Routing\ValueObjects\ProviderResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Adapts the existing acquirer classes (PayFortAcquirer, BluPayAcquirer, ...)
 * to {@see PaymentProviderInterface}.
 *
 * The thirteen acquirer implementations already share a de-facto interface
 * (generatePixQrCode / generateBoleto / processPayment / refund /
 * sendPixTransfer), so adapting them is safer than rewriting battle-tested
 * integration code. New acquirers can implement PaymentProviderInterface
 * directly and skip this class entirely.
 *
 * Its real job is normalisation: turning heterogeneous `['success' => false,
 * 'error' => '...']` arrays into typed responses with a {@see FailureReason},
 * which is what lets the router tell "acquirer is down" (fail over, trip the
 * breaker) from "card was declined" (final answer, breaker untouched).
 */
final class LegacyAcquirerProvider implements PaymentProviderInterface, BalanceInquiryInterface
{
    /**
     * @param object $acquirer one of the App\Infrastructure\Acquirers classes
     * @param array<int, PaymentMethod> $supports
     */
    public function __construct(
        private readonly string $acquirerKey,
        private readonly string $displayName,
        private readonly object $acquirer,
        private readonly array $supports,
    ) {
    }

    public function key(): string
    {
        return $this->acquirerKey;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function supports(): array
    {
        return $this->supports;
    }

    public function supportsMethod(PaymentMethod $method): bool
    {
        return in_array($method, $this->supports, true);
    }

    public function charge(ProviderRequest $request): ProviderResponse
    {
        $this->applyTimeouts($request);

        return match ($request->method) {
            PaymentMethod::PIX => $this->call(
                'generatePixQrCode',
                fn (): array => $this->acquirer->generatePixQrCode($request->charge)
            ),
            PaymentMethod::BOLETO => $this->call(
                'generateBoleto',
                fn (): array => $this->acquirer->generateBoleto($request->charge)
            ),
            PaymentMethod::CREDIT_CARD, PaymentMethod::DEBIT_CARD => $this->call(
                'processPayment',
                fn (): array => $this->acquirer->processPayment($request->charge, $request->payload)
            ),
            PaymentMethod::PIX_OUT => $this->payout($request),
        };
    }

    public function payout(ProviderRequest $request): ProviderResponse
    {
        $this->applyTimeouts($request);

        if (!method_exists($this->acquirer, 'sendPixTransfer')) {
            return ProviderResponse::failure(
                $this->acquirerKey,
                "Adquirente '{$this->acquirerKey}' não suporta transferências PIX.",
                FailureReason::NOT_CONFIGURED
            );
        }

        return $this->call(
            'sendPixTransfer',
            fn (): array => $this->acquirer->sendPixTransfer($request->payload)
        );
    }

    public function refund(ProviderRequest $request, ?int $amountCents = null): ProviderResponse
    {
        $this->applyTimeouts($request);

        if (!method_exists($this->acquirer, 'refund')) {
            return ProviderResponse::failure(
                $this->acquirerKey,
                "Adquirente '{$this->acquirerKey}' não suporta estorno.",
                FailureReason::NOT_CONFIGURED
            );
        }

        // The legacy signature takes a float in the major unit.
        $amount = ($amountCents ?? $request->amountCents()) / 100;

        return $this->call(
            'refund',
            fn (): array => $this->acquirer->refund($request->charge, $amount)
        );
    }

    public function fetchStatus(string $providerTransactionId): ProviderResponse
    {
        // The lookup method is named differently across the legacy classes.
        foreach (['getTransaction', 'getPayment', 'getPixInTransaction'] as $method) {
            if (!method_exists($this->acquirer, $method)) {
                continue;
            }

            return $this->call($method, fn (): mixed => $this->acquirer->{$method}($providerTransactionId));
        }

        return ProviderResponse::failure(
            $this->acquirerKey,
            "Adquirente '{$this->acquirerKey}' não expõe consulta de transação.",
            FailureReason::NOT_CONFIGURED
        );
    }

    /**
     * Connectivity probe using whatever read-only endpoint the acquirer offers.
     *
     * Ordered from most to least informative. Crucially, none of these create
     * a transaction — the previous health check hit `{base_url}/health`, an
     * endpoint no PSP in this stack actually exposes, so it reported every
     * acquirer as unhealthy.
     */
    public function probe(): ProbeResult
    {
        $startedAt = microtime(true);

        $timeoutMs = (int) config('acquirer_routing.health.timeout_ms', 5000);
        $this->trySetTimeouts($timeoutMs, min(3000, $timeoutMs));

        $probes = [
            // Balance is a cheap authenticated read: proves connectivity AND credentials.
            'getBalance' => fn (): mixed => $this->acquirer->getBalance(),
            // Auth-token warm-up: proves the credentials are accepted.
            'warmAuthToken' => function (): mixed {
                $this->acquirer->warmAuthToken();

                return ['warmed' => method_exists($this->acquirer, 'hasWarmToken') ? $this->acquirer->hasWarmToken() : true];
            },
            'listWebhooks' => fn (): mixed => $this->acquirer->listWebhooks(),
        ];

        foreach ($probes as $method => $callback) {
            if (!method_exists($this->acquirer, $method)) {
                continue;
            }

            try {
                $result = $callback();
                $latency = $this->elapsedMs($startedAt);

                // Legacy methods signal failure through the payload, not exceptions.
                if (is_array($result) && array_key_exists('success', $result) && $result['success'] === false) {
                    return ProbeResult::unhealthy(
                        $latency,
                        (string) ($result['error'] ?? 'Falha na verificação de conectividade'),
                        ['probe' => $method]
                    );
                }

                if (is_array($result) && ($result['warmed'] ?? true) === false) {
                    return ProbeResult::unhealthy($latency, 'Não foi possível autenticar na adquirente', ['probe' => $method]);
                }

                return ProbeResult::healthy($latency, ['probe' => $method]);
            } catch (\Throwable $e) {
                return ProbeResult::unhealthy($this->elapsedMs($startedAt), $e->getMessage(), ['probe' => $method]);
            }
        }

        return ProbeResult::skipped(
            "Adquirente '{$this->acquirerKey}' não expõe endpoint de verificação sem efeito colateral."
        );
    }

    /**
     * Funds held at this acquirer, normalised to cents.
     *
     * The field name and the unit differ per PSP (Enki reports cents as an int,
     * BluPay and Witetec report reais as a float), so the mapping lives in
     * config/acquirer_routing.php → balances rather than in a `match` here.
     * That `match` previously had `default => 0`, which silently reported every
     * unmapped acquirer as having no funds — indistinguishable from being broke.
     */
    public function balance(): AcquirerBalance
    {
        if (!method_exists($this->acquirer, 'getBalance')) {
            return AcquirerBalance::notSupported($this->acquirerKey);
        }

        try {
            $payload = $this->acquirer->getBalance();
        } catch (\Throwable $e) {
            return AcquirerBalance::unavailable($this->acquirerKey, $e->getMessage());
        }

        if (!is_array($payload) || ($payload['success'] ?? false) !== true) {
            return AcquirerBalance::unavailable(
                $this->acquirerKey,
                (string) ($payload['error'] ?? 'Consulta de saldo não retornou sucesso.')
            );
        }

        $config = (array) config('acquirer_routing.balances', []);
        $mapping = $config[$this->acquirerKey] ?? null;

        if (is_array($mapping)) {
            $value = Arr::get($payload, (string) $mapping['field']);

            if (!is_numeric($value)) {
                return AcquirerBalance::unavailable(
                    $this->acquirerKey,
                    sprintf("Campo de saldo '%s' ausente na resposta.", $mapping['field'])
                );
            }

            $blocked = isset($mapping['blocked']) ? Arr::get($payload, (string) $mapping['blocked']) : null;

            return AcquirerBalance::of(
                acquirerKey: $this->acquirerKey,
                availableCents: $this->toCents($value, (string) ($mapping['unit'] ?? 'reais')),
                blockedCents: is_numeric($blocked) ? $this->toCents($blocked, (string) ($mapping['unit'] ?? 'reais')) : 0,
                raw: $payload,
            );
        }

        foreach ((array) ($config['fallback_fields'] ?? []) as $field) {
            $value = Arr::get($payload, (string) $field);

            if (is_numeric($value)) {
                return AcquirerBalance::of(
                    acquirerKey: $this->acquirerKey,
                    availableCents: $this->toCents($value, (string) ($config['fallback_unit'] ?? 'reais')),
                    raw: $payload,
                );
            }
        }

        return AcquirerBalance::unavailable(
            $this->acquirerKey,
            'Formato de saldo não reconhecido. Mapeie a adquirente em config/acquirer_routing.php → balances.'
        );
    }

    private function toCents(mixed $value, string $unit): int
    {
        return $unit === 'cents'
            ? (int) round((float) $value)
            : (int) round(((float) $value) * 100);
    }

    /**
     * Run a legacy call and normalise whatever it returns or throws.
     *
     * @param callable(): mixed $callback
     */
    private function call(string $operation, callable $callback): ProviderResponse
    {
        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $reason = FailureReason::fromThrowable($e);

            Log::warning('Chamada à adquirente lançou exceção', [
                'acquirer' => $this->acquirerKey,
                'operation' => $operation,
                'reason' => $reason->value,
                'error' => $e->getMessage(),
            ]);

            return ProviderResponse::failure($this->acquirerKey, $e->getMessage(), $reason);
        }

        if ($result === null) {
            return ProviderResponse::failure(
                $this->acquirerKey,
                "Adquirente '{$this->acquirerKey}' não retornou resposta em {$operation}.",
                FailureReason::INVALID_RESPONSE
            );
        }

        if (!is_array($result)) {
            return ProviderResponse::success($this->acquirerKey, ['result' => $result], raw: $result);
        }

        if (($result['success'] ?? false) === true) {
            return $this->normaliseSuccess($result, $operation);
        }

        $error = (string) ($result['error'] ?? $result['message'] ?? 'Erro desconhecido na adquirente');

        return ProviderResponse::failure(
            $this->acquirerKey,
            $error,
            FailureReason::fromMessage($error),
            data: $result,
            raw: $result['raw_response'] ?? $result['data'] ?? null,
        );
    }

    /**
     * A "successful" PIX response with no QR code is not a success.
     *
     * The legacy code discovered this the hard way and patched it inline in
     * PaymentService; enforcing it here means every caller gets the check.
     *
     * @param array<string, mixed> $result
     */
    private function normaliseSuccess(array $result, string $operation): ProviderResponse
    {
        if ($operation === 'generatePixQrCode') {
            $qr = $result['pix_qr_code'] ?? null;
            $copyPaste = $result['pix_copy_paste'] ?? null;

            if (empty($qr) && empty($copyPaste)) {
                return ProviderResponse::failure(
                    $this->acquirerKey,
                    'Adquirente retornou sucesso sem dados do PIX (QR code ausente).',
                    FailureReason::INVALID_RESPONSE,
                    data: $result,
                    raw: $result,
                );
            }
        }

        if ($operation === 'generateBoleto' && empty($result['boleto_url']) && empty($result['barcode']) && empty($result['boleto_barcode'])) {
            return ProviderResponse::failure(
                $this->acquirerKey,
                'Adquirente retornou sucesso sem dados do boleto.',
                FailureReason::INVALID_RESPONSE,
                data: $result,
                raw: $result,
            );
        }

        return ProviderResponse::success(
            acquirerKey: $this->acquirerKey,
            data: $result,
            transactionId: $this->extractTransactionId($result),
            status: isset($result['status']) ? (string) $result['status'] : null,
            raw: $result['raw_response'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractTransactionId(array $result): ?string
    {
        foreach (['transaction_id', 'external_id', 'payment_id', 'id', 'transfer_id'] as $field) {
            if (!empty($result[$field]) && is_scalar($result[$field])) {
                return (string) $result[$field];
            }
        }

        return null;
    }

    private function applyTimeouts(ProviderRequest $request): void
    {
        $this->trySetTimeouts($request->timeoutMs, $request->connectTimeoutMs);
    }

    /**
     * Acquirers using the ConfigurableTimeouts trait accept per-call budgets;
     * the rest keep their built-in defaults.
     */
    private function trySetTimeouts(int $timeoutMs, int $connectTimeoutMs): void
    {
        if (method_exists($this->acquirer, 'setTimeouts')) {
            $this->acquirer->setTimeouts($timeoutMs, $connectTimeoutMs);
        }
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
