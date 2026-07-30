<?php

declare(strict_types=1);

namespace App\Application\Routing;

use App\Domain\Routing\Contracts\CircuitBreakerInterface;
use App\Domain\Routing\Contracts\HealthStateRepositoryInterface;
use App\Domain\Routing\Contracts\MetricsRepositoryInterface;
use App\Domain\Routing\Contracts\PaymentProviderInterface;
use App\Domain\Routing\Contracts\ProviderRegistryInterface;
use App\Domain\Routing\Contracts\RateLimiterInterface;
use App\Domain\Routing\Enums\FailureReason;
use App\Domain\Routing\Enums\PaymentMethod;
use App\Domain\Routing\Services\SmartAcquirerRouter;
use App\Domain\Routing\ValueObjects\AcquirerCandidate;
use App\Domain\Routing\ValueObjects\ProviderRequest;
use App\Domain\Routing\ValueObjects\ProviderResponse;
use App\Domain\Routing\ValueObjects\RoutingAttempt;
use App\Domain\Routing\ValueObjects\RoutingContext;
use App\Domain\Routing\ValueObjects\RoutingDecision;
use App\Domain\Routing\ValueObjects\RoutingResult;
use App\Models\Charge;
use Illuminate\Support\Facades\Log;

/**
 * Executes a transaction across the acquirer chain the router produced.
 *
 * Responsibilities, in order:
 *   1. ask {@see SmartAcquirerRouter} for the ordered chain;
 *   2. walk it, honouring the TPS limit and the total time budget;
 *   3. retry the same acquirer with exponential backoff for transient errors;
 *   4. fail over to the next acquirer for everything else that is not a
 *      definitive answer (a declined card ends the chain);
 *   5. feed the circuit breaker, health state, metrics and the routing log.
 *
 * The caller receives only the final outcome — intermediate attempts are
 * invisible to the customer, which is the whole point of the fallback chain.
 */
final class PaymentRoutingService
{
    public function __construct(
        private readonly SmartAcquirerRouter $router,
        private readonly ProviderRegistryInterface $providers,
        private readonly CircuitBreakerInterface $circuitBreaker,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly MetricsRepositoryInterface $metrics,
        private readonly HealthStateRepositoryInterface $health,
        private readonly RoutingLogger $logger,
    ) {
    }

    /**
     * Create a charge (PIX, boleto or card) with automatic fallback.
     *
     * The Charge is optional: providers implementing PaymentProviderInterface
     * natively work off the RoutingContext, and only the legacy adapter needs
     * the model to hand to the original acquirer classes.
     *
     * @param array<string, mixed> $payload card data, when applicable
     */
    public function charge(?Charge $charge, RoutingContext $context, array $payload = []): RoutingResult
    {
        return $this->execute(
            $context,
            fn (PaymentProviderInterface $provider, ProviderRequest $request): ProviderResponse => $provider->charge($request),
            $charge,
            $payload,
        );
    }

    /**
     * Send money out (PIX transfer / withdrawal) with automatic fallback.
     *
     * @param array<string, mixed> $transferData
     */
    public function payout(RoutingContext $context, array $transferData): RoutingResult
    {
        return $this->execute(
            $context->method === PaymentMethod::PIX_OUT ? $context : $context,
            fn (PaymentProviderInterface $provider, ProviderRequest $request): ProviderResponse => $provider->payout($request),
            null,
            $transferData,
        );
    }

    /**
     * Refund through the acquirer that originally processed the charge.
     * Refunds are never failed over: only the original acquirer holds the funds.
     */
    public function refund(Charge $charge, RoutingContext $context, ?int $amountCents = null): RoutingResult
    {
        $acquirerKey = (string) ($charge->acquirer ?? '');

        if ($acquirerKey === '') {
            return RoutingResult::failure(
                $context->correlationId,
                'Cobrança sem adquirente registrada; estorno não pode ser roteado.',
                [],
                0,
            );
        }

        return $this->execute(
            $context->forcedTo($acquirerKey),
            fn (PaymentProviderInterface $provider, ProviderRequest $request): ProviderResponse => $provider->refund($request, $amountCents),
            $charge,
            [],
        );
    }

    /**
     * @param callable(PaymentProviderInterface, ProviderRequest): ProviderResponse $operation
     * @param array<string, mixed> $payload
     */
    private function execute(
        RoutingContext $context,
        callable $operation,
        ?Charge $charge,
        array $payload,
    ): RoutingResult {
        $startedAt = microtime(true);
        $decision = $this->router->route($context);

        $this->logger->decision($context, $decision);

        if ($decision->isEmpty()) {
            $result = RoutingResult::failure(
                $context->correlationId,
                $decision->reason,
                [],
                $this->elapsedMs($startedAt),
                $decision,
            );

            $this->logger->persist($context, $decision, $result, 'no_acquirer');

            return $result;
        }

        $budgetMs = $this->budgetFor($context);
        $attempts = [];
        $lastResponse = null;
        $position = 0;

        foreach ($decision->chain as $candidate) {
            $position++;

            $remainingMs = $budgetMs - $this->elapsedMs($startedAt);

            // Stop rather than start a call we cannot afford to finish.
            if ($remainingMs < $this->minimumViableBudgetMs($candidate)) {
                Log::warning('Orçamento de tempo esgotado; interrompendo cadeia de fallback', [
                    'correlation_id' => $context->correlationId,
                    'acquirer' => $candidate->key,
                    'remaining_ms' => $remainingMs,
                ]);

                $attempts[] = RoutingAttempt::failure(
                    $candidate->key,
                    $position,
                    0,
                    FailureReason::TIMEOUT,
                    'Orçamento total de tempo esgotado antes da tentativa',
                    selectionReason: $candidate->scoreReason,
                );
                break;
            }

            $outcome = $this->attemptCandidate(
                $candidate,
                $context,
                $operation,
                $charge,
                $payload,
                $position,
                min($candidate->timeoutMs, $remainingMs),
            );

            $attempts[] = $outcome['attempt'];
            $lastResponse = $outcome['response'] ?? $lastResponse;

            if ($outcome['attempt']->succeeded) {
                $result = RoutingResult::success(
                    $context->correlationId,
                    $outcome['response'],
                    $attempts,
                    $this->elapsedMs($startedAt),
                    $decision,
                );

                $this->logger->success($context, $decision, $result);
                $this->logger->persist($context, $decision, $result, 'success');

                return $result;
            }

            // A definitive answer from a healthy acquirer ends the chain:
            // retrying a declined card elsewhere risks a double charge.
            if (!($outcome['allows_failover'] ?? true)) {
                $result = RoutingResult::failure(
                    $context->correlationId,
                    $outcome['response']?->errorMessage ?? 'Transação recusada.',
                    $attempts,
                    $this->elapsedMs($startedAt),
                    $decision,
                    $outcome['response'],
                );

                $this->logger->declined($context, $decision, $result);
                $this->logger->persist($context, $decision, $result, 'declined');

                return $result;
            }

            $this->metrics->recordFallbackOut($candidate->key, $context->method);
        }

        $result = RoutingResult::failure(
            $context->correlationId,
            $this->exhaustedMessage($attempts),
            $attempts,
            $this->elapsedMs($startedAt),
            $decision,
            $lastResponse,
        );

        $this->logger->exhausted($context, $decision, $result);
        $this->logger->persist($context, $decision, $result, 'failed');

        return $result;
    }

    /**
     * One acquirer, including its retries.
     *
     * @param callable(PaymentProviderInterface, ProviderRequest): ProviderResponse $operation
     * @param array<string, mixed> $payload
     * @return array{attempt: RoutingAttempt, response: ProviderResponse|null, allows_failover: bool}
     */
    private function attemptCandidate(
        AcquirerCandidate $candidate,
        RoutingContext $context,
        callable $operation,
        ?Charge $charge,
        array $payload,
        int $position,
        int $timeoutMs,
    ): array {
        // TPS/concurrency ceiling: a saturated acquirer is skipped so the next
        // one absorbs the load, rather than the request queueing behind it.
        if (!$this->rateLimiter->tryAcquire($candidate->key, $candidate->maxTps, $candidate->maxConcurrent)) {
            $this->metrics->recordRejection($candidate->key, $context->method);

            return [
                'attempt' => RoutingAttempt::failure(
                    $candidate->key,
                    $position,
                    0,
                    FailureReason::TPS_EXCEEDED,
                    sprintf('Limite de TPS/concorrência atingido (max_tps=%s)', $candidate->maxTps ?? '—'),
                    selectionReason: $candidate->scoreReason,
                ),
                'response' => null,
                'allows_failover' => true,
            ];
        }

        try {
            $provider = $this->providers->resolve($candidate->key, $context->accountId);

            if ($provider === null) {
                $this->metrics->recordRejection($candidate->key, $context->method);

                return [
                    'attempt' => RoutingAttempt::failure(
                        $candidate->key,
                        $position,
                        0,
                        FailureReason::NOT_CONFIGURED,
                        'Módulo da adquirente indisponível ou credenciais inválidas',
                        selectionReason: $candidate->scoreReason,
                    ),
                    'response' => null,
                    'allows_failover' => true,
                ];
            }

            if (!$provider->supportsMethod($context->method)) {
                return [
                    'attempt' => RoutingAttempt::failure(
                        $candidate->key,
                        $position,
                        0,
                        FailureReason::NOT_CONFIGURED,
                        'Adquirente não suporta ' . $context->method->label(),
                        selectionReason: $candidate->scoreReason,
                    ),
                    'response' => null,
                    'allows_failover' => true,
                ];
            }

            return $this->callWithRetries(
                $provider,
                $candidate,
                $context,
                $operation,
                $charge,
                $payload,
                $position,
                $timeoutMs,
            );
        } finally {
            $this->rateLimiter->release($candidate->key);
        }
    }

    /**
     * Retry loop for one acquirer.
     *
     * Only transient failures are retried (timeout, connection refused, 502/503,
     * rate limited). A provider error that is a real answer is not retried —
     * hammering it would just multiply the latency before failing over.
     *
     * @param callable(PaymentProviderInterface, ProviderRequest): ProviderResponse $operation
     * @param array<string, mixed> $payload
     * @return array{attempt: RoutingAttempt, response: ProviderResponse|null, allows_failover: bool}
     */
    private function callWithRetries(
        PaymentProviderInterface $provider,
        AcquirerCandidate $candidate,
        RoutingContext $context,
        callable $operation,
        ?Charge $charge,
        array $payload,
        int $position,
        int $timeoutMs,
    ): array {
        $profile = $this->profileFor($candidate);
        $maxRetries = max(0, $candidate->maxRetries);
        $retries = 0;
        $response = null;
        $totalDurationMs = 0;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $request = new ProviderRequest(
                method: $context->method,
                context: $context,
                charge: $charge,
                payload: $payload,
                timeoutMs: $timeoutMs,
                connectTimeoutMs: $candidate->connectTimeoutMs,
            );

            $startedAt = microtime(true);

            try {
                $response = $operation($provider, $request);
            } catch (\Throwable $e) {
                $response = ProviderResponse::failure(
                    $candidate->key,
                    $e->getMessage(),
                    FailureReason::fromThrowable($e),
                );
            }

            $durationMs = $this->elapsedMs($startedAt);
            $totalDurationMs += $durationMs;

            if ($response->successful) {
                $this->onSuccess($candidate, $context, $durationMs, $retries > 0, $position);

                return [
                    'attempt' => RoutingAttempt::success(
                        $candidate->key,
                        $position,
                        $totalDurationMs,
                        $retries,
                        $candidate->scoreReason,
                        $response->transactionId,
                    ),
                    'response' => $response,
                    'allows_failover' => true,
                ];
            }

            $reason = $response->failureReason ?? FailureReason::UNKNOWN;

            // Retry the same acquirer only while it looks like a blip.
            if ($attempt < $maxRetries && $reason->isRetryable()) {
                $retries++;
                $delayMs = $profile->backoffDelayMs($attempt + 1);

                Log::info('Reenviando para a mesma adquirente após falha transitória', [
                    'correlation_id' => $context->correlationId,
                    'acquirer' => $candidate->key,
                    'retry' => $retries,
                    'reason' => $reason->value,
                    'backoff_ms' => $delayMs,
                ]);

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                    $totalDurationMs += $delayMs;
                }

                continue;
            }

            break;
        }

        $reason = $response?->failureReason ?? FailureReason::UNKNOWN;
        $this->onFailure($candidate, $context, $reason, $response?->errorMessage, $totalDurationMs);

        return [
            'attempt' => RoutingAttempt::failure(
                $candidate->key,
                $position,
                $totalDurationMs,
                $reason,
                $response?->errorMessage,
                $retries,
                $candidate->scoreReason,
            ),
            'response' => $response,
            'allows_failover' => $reason->allowsFailover(),
        ];
    }

    private function onSuccess(
        AcquirerCandidate $candidate,
        RoutingContext $context,
        int $durationMs,
        bool $afterRetry,
        int $position,
    ): void {
        $this->circuitBreaker->recordSuccess($candidate->key, $durationMs);
        $this->health->recordTraffic($candidate->key, true, $durationMs);
        $this->metrics->recordAttempt(
            acquirerKey: $candidate->key,
            method: $context->method,
            succeeded: true,
            latencyMs: $durationMs,
            amountCents: $context->amountCents,
            isFallback: $position > 1,
        );

        if ($afterRetry) {
            Log::info('Adquirente respondeu após retry', [
                'correlation_id' => $context->correlationId,
                'acquirer' => $candidate->key,
            ]);
        }
    }

    private function onFailure(
        AcquirerCandidate $candidate,
        RoutingContext $context,
        FailureReason $reason,
        ?string $error,
        int $durationMs,
    ): void {
        $this->circuitBreaker->recordFailure($candidate->key, $reason, $error);

        // A declined card says nothing about the acquirer's health.
        if ($reason->countsAgainstCircuit()) {
            $this->health->recordTraffic($candidate->key, false, $durationMs, $error);
        }

        $this->metrics->recordAttempt(
            acquirerKey: $candidate->key,
            method: $context->method,
            succeeded: false,
            latencyMs: $durationMs,
            isTimeout: $reason->isTimeout(),
        );
    }

    private function profileFor(AcquirerCandidate $candidate): \App\Models\AcquirerRoutingProfile
    {
        try {
            $profile = \App\Models\AcquirerRoutingProfile::query()
                ->where('acquirer_key', $candidate->key)
                ->first();

            if ($profile !== null) {
                return $profile;
            }
        } catch (\Throwable) {
            // Fall through to defaults.
        }

        return new \App\Models\AcquirerRoutingProfile(array_merge(
            (array) config('acquirer_routing.profile_defaults', []),
            ['acquirer_key' => $candidate->key]
        ));
    }

    private function budgetFor(RoutingContext $context): int
    {
        return max(1000, (int) config('acquirer_routing.defaults.total_budget_ms', 25000));
    }

    /**
     * Starting a call with less than the connect timeout left guarantees a
     * timeout — better to stop and report honestly.
     */
    private function minimumViableBudgetMs(AcquirerCandidate $candidate): int
    {
        return max(500, $candidate->connectTimeoutMs);
    }

    /**
     * @param array<int, RoutingAttempt> $attempts
     */
    private function exhaustedMessage(array $attempts): string
    {
        if ($attempts === []) {
            return 'Nenhuma adquirente disponível conseguiu processar o pagamento.';
        }

        return 'Nenhuma adquirente disponível conseguiu processar o pagamento. Tente novamente em instantes.';
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
