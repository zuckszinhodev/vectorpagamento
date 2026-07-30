<?php

declare(strict_types=1);

namespace App\Domain\Routing\Services;

use App\Domain\Routing\Contracts\AcquirerCatalogInterface;
use App\Domain\Routing\Contracts\CircuitBreakerInterface;
use App\Domain\Routing\Contracts\RoutingPolicyRepositoryInterface;
use App\Domain\Routing\Enums\RoutingStrategy;
use App\Domain\Routing\Strategies\StrategyRegistry;
use App\Domain\Routing\ValueObjects\AcquirerCandidate;
use App\Domain\Routing\ValueObjects\RoutingContext;
use App\Domain\Routing\ValueObjects\RoutingDecision;

/**
 * Chooses the acquirer chain for a transaction.
 *
 * Two stages, deliberately separated:
 *
 *   1. ELIGIBILITY — hard filters. An acquirer that fails any of them is
 *      removed and recorded with a reason. This stage never "relaxes" itself:
 *      if everything is filtered out the transaction fails rather than being
 *      sent to an acquirer already known to be unavailable.
 *
 *   2. RANKING — the configured strategy orders whatever survived. Strategies
 *      only rank; they never resurrect an ineligible acquirer.
 *
 * The result is an ordered chain: element 0 is the primary, the rest are the
 * fallback sequence, already free of unavailable acquirers.
 */
final class SmartAcquirerRouter
{
    public function __construct(
        private readonly AcquirerCatalogInterface $catalog,
        private readonly RoutingPolicyRepositoryInterface $policies,
        private readonly StrategyRegistry $strategies,
        private readonly CircuitBreakerInterface $circuitBreaker,
    ) {
    }

    public function route(RoutingContext $context): RoutingDecision
    {
        $policy = $this->policies->resolve($context->method, $context->accountId);
        $strategy = $policy->strategyEnum();

        $candidates = $this->catalog->candidatesFor($context);
        $excluded = [];

        if ($candidates === []) {
            return new RoutingDecision(
                chain: [],
                strategy: $strategy,
                reason: 'Nenhuma adquirente habilitada suporta ' . $context->method->label(),
                excluded: $excluded,
            );
        }

        // A forced acquirer bypasses ranking but NOT eligibility: forcing a
        // dead acquirer must fail loudly, not route into a black hole.
        if ($context->forcedAcquirer !== null) {
            return $this->routeForced($context, $candidates, $strategy, $policy->strict_health_check);
        }

        $eligible = $this->filterEligible($candidates, $context, (bool) $policy->strict_health_check, $excluded);

        if ($eligible === []) {
            return new RoutingDecision(
                chain: [],
                strategy: $strategy,
                reason: 'Todas as adquirentes foram filtradas por indisponibilidade',
                excluded: $excluded,
            );
        }

        $ordered = $this->strategies->get($strategy)->order($eligible, $context);

        // An establishment locked to specific acquirers keeps its own order as
        // the tie-breaker, so its configured preference is honoured.
        if ($context->isRestricted()) {
            $ordered = $this->applyRestrictionOrder($ordered, $context->restrictToAcquirers);
        }

        // Exclusive accounts never fail over.
        if ($context->exclusive) {
            $ordered = array_slice($ordered, 0, 1);
        }

        $decision = new RoutingDecision(
            chain: $ordered,
            strategy: $strategy,
            reason: $this->describe($strategy, $ordered, $context),
            excluded: $excluded,
        );

        if (!$policy->failover_enabled) {
            return $decision->limitedTo(1);
        }

        return $decision->limitedTo(max(1, (int) $policy->max_attempts));
    }

    /**
     * Hard availability filters, in the order that costs least first.
     *
     * @param array<int, AcquirerCandidate> $candidates
     * @param array<int, array<string, mixed>> $excluded
     * @return array<int, AcquirerCandidate>
     */
    private function filterEligible(
        array $candidates,
        RoutingContext $context,
        bool $strictHealthCheck,
        array &$excluded,
    ): array {
        $eligible = [];

        foreach ($candidates as $candidate) {
            if (!$candidate->acceptsAmount($context->amountCents)) {
                $excluded[] = $this->exclusion($candidate, 'amount_out_of_range', sprintf(
                    'Valor %d fora da faixa aceita (%s a %s)',
                    $context->amountCents,
                    $candidate->minAmountCents === null ? '—' : (string) $candidate->minAmountCents,
                    $candidate->maxAmountCents === null ? '—' : (string) $candidate->maxAmountCents,
                ));
                continue;
            }

            if (!$candidate->health->isRoutable() && $strictHealthCheck) {
                $excluded[] = $this->exclusion($candidate, 'offline', 'Adquirente marcada como offline pelo health check');
                continue;
            }

            // Asking the breaker (rather than reading the cached candidate
            // state) lets an OPEN circuit whose cooldown just elapsed move to
            // HALF_OPEN and take a probe on this very transaction.
            if (!$this->circuitBreaker->allows($candidate->key)) {
                $excluded[] = $this->exclusion($candidate, 'circuit_open', 'Circuit breaker aberto');
                continue;
            }

            if (!$this->matchesRules($candidate, $context)) {
                $excluded[] = $this->exclusion($candidate, 'rule_mismatch', 'Regras específicas da adquirente não atendidas');
                continue;
            }

            $eligible[] = $candidate;
        }

        return $eligible;
    }

    /**
     * Route to a single acquirer pinned by configuration (ACQUIRER_FORCE_ACQUIRER)
     * or by an exclusive-acquirer account setting.
     *
     * Eligibility still applies: pinning to a dead acquirer must produce a
     * clear "unavailable" failure rather than silently routing elsewhere,
     * which would violate the operator's explicit instruction.
     *
     * @param array<int, AcquirerCandidate> $candidates
     */
    private function routeForced(
        RoutingContext $context,
        array $candidates,
        RoutingStrategy $strategy,
        bool $strictHealthCheck,
    ): RoutingDecision {
        $forced = $context->forcedAcquirer;

        $match = null;
        foreach ($candidates as $candidate) {
            if ($candidate->key === $forced) {
                $match = $candidate;
                break;
            }
        }

        if ($match === null) {
            return new RoutingDecision(
                chain: [],
                strategy: $strategy,
                reason: "Adquirente forçada '{$forced}' não está habilitada para " . $context->method->label(),
                excluded: [[
                    'acquirer' => $forced,
                    'reason' => 'forced_not_available',
                    'detail' => 'Adquirente forçada não consta entre as candidatas',
                ]],
            );
        }

        $excluded = [];
        $eligible = $this->filterEligible([$match], $context, $strictHealthCheck, $excluded);

        if ($eligible === []) {
            return new RoutingDecision(
                chain: [],
                strategy: $strategy,
                reason: "Adquirente forçada '{$forced}' está indisponível",
                excluded: $excluded,
            );
        }

        return new RoutingDecision(
            chain: [$eligible[0]->withScore(1.0, "adquirente forçada por configuração")],
            strategy: $strategy,
            reason: "Roteamento fixado em '{$forced}' por configuração (sem fallback)",
            excluded: $excluded,
        );
    }

    /**
     * Re-order so that the establishment's configured preference wins ties.
     *
     * @param array<int, AcquirerCandidate> $ordered
     * @param array<int, string> $preference
     * @return array<int, AcquirerCandidate>
     */
    private function applyRestrictionOrder(array $ordered, array $preference): array
    {
        $rank = array_flip($preference);

        usort($ordered, static function (AcquirerCandidate $a, AcquirerCandidate $b) use ($rank): int {
            $rankA = $rank[$a->key] ?? PHP_INT_MAX;
            $rankB = $rank[$b->key] ?? PHP_INT_MAX;

            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            // Same preference rank: keep the strategy's ordering.
            return $b->score <=> $a->score;
        });

        return array_values($ordered);
    }

    /**
     * Per-acquirer rules configured in the panel (allowed currencies,
     * installment ceiling, operating hours).
     */
    private function matchesRules(AcquirerCandidate $candidate, RoutingContext $context): bool
    {
        $rules = $candidate->rules;

        if ($rules === []) {
            return true;
        }

        $currencies = $rules['allowed_currencies'] ?? null;
        if (is_array($currencies) && $currencies !== []) {
            $normalized = array_map(static fn ($c): string => strtoupper(trim((string) $c)), $currencies);

            if (!in_array(strtoupper($context->currency), $normalized, true)) {
                return false;
            }
        }

        $maxInstallments = $rules['max_installments'] ?? null;
        if (is_numeric($maxInstallments) && $context->installments > (int) $maxInstallments) {
            return false;
        }

        $blockedAccounts = $rules['blocked_accounts'] ?? null;
        if (is_array($blockedAccounts) && $context->accountId !== null && in_array($context->accountId, $blockedAccounts, true)) {
            return false;
        }

        $allowedAccounts = $rules['allowed_accounts'] ?? null;
        if (is_array($allowedAccounts) && $allowedAccounts !== []) {
            if ($context->accountId === null || !in_array($context->accountId, $allowedAccounts, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function exclusion(AcquirerCandidate $candidate, string $reason, string $detail): array
    {
        return [
            'acquirer' => $candidate->key,
            'reason' => $reason,
            'detail' => $detail,
            'health' => $candidate->health->value,
            'circuit' => $candidate->circuit->value,
        ];
    }

    /**
     * @param array<int, AcquirerCandidate> $ordered
     */
    private function describe(RoutingStrategy $strategy, array $ordered, RoutingContext $context): string
    {
        $primary = $ordered[0] ?? null;

        if ($primary === null) {
            return 'Nenhuma adquirente elegível';
        }

        return sprintf(
            '%s selecionou %s: %s (fallbacks: %s)',
            $strategy->label(),
            $primary->key,
            $primary->scoreReason,
            count($ordered) > 1
                ? implode(' → ', array_map(static fn (AcquirerCandidate $c): string => $c->key, array_slice($ordered, 1)))
                : 'nenhum'
        );
    }
}
