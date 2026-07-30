<?php

declare(strict_types=1);

namespace App\Application\Routing;

use App\Domain\Routing\ValueObjects\AcquirerCandidate;
use App\Domain\Routing\ValueObjects\RoutingContext;
use App\Domain\Routing\ValueObjects\RoutingDecision;
use App\Domain\Routing\ValueObjects\RoutingResult;
use App\Models\AcquirerEvent;
use App\Models\AcquirerRoutingLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Structured logging for every routing decision.
 *
 * Two destinations, on purpose:
 *   - the application log, for real-time tailing and alerting;
 *   - `acquirer_routing_logs`, one queryable row per transaction with the full
 *     attempt chain, so "why did this charge go to acquirer X?" is answerable
 *     months later without grepping.
 *
 * Every line carries the correlation_id and a timestamp, so the application
 * log and the database row can be joined.
 */
final class RoutingLogger
{
    public function decision(RoutingContext $context, RoutingDecision $decision): void
    {
        Log::info('Roteamento: adquirente selecionada', array_merge($context->toLogArray(), [
            'strategy' => $decision->strategy->value,
            'selected' => $decision->primary()?->key,
            'selection_reason' => $decision->primary()?->scoreReason,
            'chain' => $decision->keys(),
            'excluded' => $decision->excluded,
            'at' => now()->toIso8601String(),
        ]));

        // An acquirer removed for being unhealthy is worth surfacing on its own.
        foreach ($decision->excluded as $exclusion) {
            if (in_array($exclusion['reason'] ?? '', ['circuit_open', 'offline'], true)) {
                Log::warning('Roteamento: adquirente excluída por indisponibilidade', array_merge($exclusion, [
                    'correlation_id' => $context->correlationId,
                    'at' => now()->toIso8601String(),
                ]));
            }
        }
    }

    public function success(RoutingContext $context, RoutingDecision $decision, RoutingResult $result): void
    {
        $level = $result->usedFallback() ? 'warning' : 'info';

        Log::log($level, $result->usedFallback()
            ? 'Roteamento: sucesso via fallback'
            : 'Roteamento: sucesso na adquirente primária', array_merge($context->toLogArray(), [
                'selected' => $result->selectedAcquirer(),
                'primary' => $decision->primary()?->key,
                'attempts' => $result->attemptsAsArray(),
                'attempt_count' => $result->attemptCount(),
                'total_duration_ms' => $result->totalDurationMs,
                'at' => now()->toIso8601String(),
            ]));

        if ($result->usedFallback()) {
            $this->event(
                $result->selectedAcquirer() ?? 'unknown',
                AcquirerEvent::TYPE_FALLBACK,
                'warning',
                sprintf(
                    'Fallback: %s assumiu após %d tentativa(s)',
                    $result->selectedAcquirer(),
                    $result->attemptCount() - 1
                ),
                [
                    'primary' => $decision->primary()?->key,
                    'attempts' => $result->attemptsAsArray(),
                ],
                $context->correlationId,
            );
        }
    }

    public function declined(RoutingContext $context, RoutingDecision $decision, RoutingResult $result): void
    {
        Log::info('Roteamento: transação recusada pela adquirente (sem fallback)', array_merge($context->toLogArray(), [
            'acquirer' => $result->response?->acquirerKey,
            'error' => $result->error,
            'attempts' => $result->attemptsAsArray(),
            'at' => now()->toIso8601String(),
        ]));
    }

    public function exhausted(RoutingContext $context, RoutingDecision $decision, RoutingResult $result): void
    {
        Log::error('Roteamento: todas as adquirentes falharam', array_merge($context->toLogArray(), [
            'strategy' => $decision->strategy->value,
            'chain' => $decision->keys(),
            'attempts' => $result->attemptsAsArray(),
            'excluded' => $decision->excluded,
            'total_duration_ms' => $result->totalDurationMs,
            'at' => now()->toIso8601String(),
        ]));
    }

    /**
     * Persist the decision. Never throws: an observability failure must not
     * turn a successful payment into an error.
     */
    public function persist(
        RoutingContext $context,
        ?RoutingDecision $decision,
        RoutingResult $result,
        string $outcome,
    ): void {
        try {
            AcquirerRoutingLog::query()->create([
                'id' => (string) Str::uuid(),
                'correlation_id' => $context->correlationId,
                'charge_id' => $context->chargeId,
                'account_id' => $context->accountId,
                'payment_method' => $context->method->value,
                'amount_cents' => $context->amountCents,
                'strategy' => $decision?->strategy->value,
                'selected_acquirer' => $result->selectedAcquirer(),
                'selection_reason' => $decision?->primary()?->scoreReason,
                'outcome' => $outcome,
                'attempt_count' => $result->attemptCount(),
                'total_duration_ms' => $result->totalDurationMs,
                'attempts' => $result->attemptsAsArray(),
                'candidates' => $decision === null ? null : [
                    'chain' => array_map(
                        static fn (AcquirerCandidate $c): array => $c->toLogArray(),
                        $decision->chain
                    ),
                    'excluded' => $decision->excluded,
                ],
                'error' => $result->error,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Falha ao persistir log de roteamento', [
                'correlation_id' => $context->correlationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(
        string $acquirerKey,
        string $type,
        string $severity,
        string $message,
        array $payload = [],
        ?string $correlationId = null,
    ): void {
        try {
            AcquirerEvent::query()->create([
                'id' => (string) Str::uuid(),
                'acquirer_key' => $acquirerKey,
                'type' => $type,
                'severity' => $severity,
                'message' => $message,
                'context' => $payload,
                'correlation_id' => $correlationId,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Falha ao registrar evento de roteamento', ['error' => $e->getMessage()]);
        }
    }
}
