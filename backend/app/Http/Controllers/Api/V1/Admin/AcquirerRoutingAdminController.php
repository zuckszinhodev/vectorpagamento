<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Application\Routing\AcquirerHealthMonitor;
use App\Domain\Routing\Contracts\AcquirerCatalogInterface;
use App\Domain\Routing\Contracts\AcquirerCredentialRepositoryInterface;
use App\Domain\Routing\Contracts\CircuitBreakerInterface;
use App\Domain\Routing\Contracts\HealthStateRepositoryInterface;
use App\Domain\Routing\Contracts\MetricsRepositoryInterface;
use App\Domain\Routing\Contracts\ProviderRegistryInterface;
use App\Domain\Routing\Contracts\RoutingPolicyRepositoryInterface;
use App\Domain\Routing\Enums\HealthState;
use App\Domain\Routing\Enums\PaymentMethod;
use App\Domain\Routing\Services\SmartAcquirerRouter;
use App\Domain\Routing\Strategies\StrategyRegistry;
use App\Domain\Routing\ValueObjects\RoutingContext;
use App\Http\Controllers\Controller;
use App\Models\AcquirerConfig;
use App\Models\AcquirerEvent;
use App\Models\AcquirerRoutingLog;
use App\Models\AcquirerRoutingProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Admin API for acquirer registration, routing configuration and monitoring.
 *
 * Credential values are write-only: they go in as plaintext from the form,
 * get encrypted immediately, and only ever come back masked. There is no
 * endpoint on this controller that can return a decrypted secret.
 */
class AcquirerRoutingAdminController extends Controller
{
    public function __construct(
        private readonly AcquirerCredentialRepositoryInterface $credentials,
        private readonly HealthStateRepositoryInterface $health,
        private readonly MetricsRepositoryInterface $metrics,
        private readonly RoutingPolicyRepositoryInterface $policies,
        private readonly ProviderRegistryInterface $providers,
        private readonly CircuitBreakerInterface $circuitBreaker,
        private readonly AcquirerHealthMonitor $monitor,
        private readonly AcquirerCatalogInterface $catalog,
        private readonly StrategyRegistry $strategies,
        private readonly SmartAcquirerRouter $router,
    ) {
    }

    /**
     * List every acquirer with its routing profile, health and metrics.
     */
    public function index(Request $request): JsonResponse
    {
        $window = $this->window($request);

        $configs = AcquirerConfig::query()->orderBy('priority')->get();
        $profiles = AcquirerRoutingProfile::query()->get()->keyBy('acquirer_config_id');
        $healthStates = $this->health->all();
        $metrics = $this->metrics->overview($window);

        $data = $configs->map(function (AcquirerConfig $config) use ($profiles, $healthStates, $metrics): array {
            $profile = $profiles->get($config->id) ?? AcquirerRoutingProfile::defaultsFor($config);
            $state = $healthStates->get($config->acquirer_key);

            return [
                'id' => $config->id,
                'acquirer_key' => $config->acquirer_key,
                'display_name' => $config->display_name,
                'nominal_name' => $config->nominal_name,
                'type' => $config->type,
                'enabled' => (bool) $config->enabled,
                'supported_methods' => $config->supported_methods ?? [],
                'module_available' => $this->providers->has($config->acquirer_key),
                'credentials' => $this->credentials->masked($config),
                'credentials_complete' => $this->credentials->hasRequiredFields($config),
                'routing' => $this->profilePayload($profile),
                'health' => $state?->toDashboardArray() ?? $this->emptyHealth($config->acquirer_key),
                'metrics' => ($metrics[$config->acquirer_key] ?? null)?->toArray(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'window_minutes' => $window,
                'strategies' => $this->strategies->available(),
                'payment_methods' => array_map(
                    static fn (PaymentMethod $m): array => ['value' => $m->value, 'label' => $m->label()],
                    PaymentMethod::cases()
                ),
                'available_modules' => $this->providers->registeredKeys(),
            ],
        ]);
    }

    public function show(string $acquirerId): JsonResponse
    {
        $config = AcquirerConfig::query()->find($acquirerId);

        if (!$config) {
            return $this->notFound();
        }

        $profile = AcquirerRoutingProfile::query()->where('acquirer_config_id', $config->id)->first()
            ?? AcquirerRoutingProfile::defaultsFor($config);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $config->id,
                'acquirer_key' => $config->acquirer_key,
                'display_name' => $config->display_name,
                'nominal_name' => $config->nominal_name,
                'type' => $config->type,
                'enabled' => (bool) $config->enabled,
                'supported_methods' => $config->supported_methods ?? [],
                'credential_fields' => AcquirerConfig::getCredentialFields($config->acquirer_key),
                'credentials' => $this->credentials->masked($config),
                'routing' => $this->profilePayload($profile),
                'health' => $this->health->find($config->acquirer_key)?->toDashboardArray()
                    ?? $this->emptyHealth($config->acquirer_key),
                'recent_events' => AcquirerEvent::query()
                    ->where('acquirer_key', $config->acquirer_key)
                    ->orderByDesc('occurred_at')
                    ->limit(25)
                    ->get(['type', 'severity', 'message', 'context', 'occurred_at']),
            ],
        ]);
    }

    /**
     * Register a new acquirer. Credentials are encrypted before the first write.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'acquirer_key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/', 'unique:acquirer_configs,acquirer_key'],
            'display_name' => ['required', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:60'],
            'nominal_name' => ['nullable', 'string', 'max:160'],
            'enabled' => ['nullable', 'boolean'],
            'supported_methods' => ['nullable', 'array'],
            'supported_methods.*' => ['string'],
            'credentials' => ['nullable', 'array'],
            'routing' => ['nullable', 'array'],
        ]);

        if (!$this->providers->has($validated['acquirer_key'])) {
            return response()->json([
                'success' => false,
                'message' => sprintf(
                    "Nenhum módulo implementado para '%s'. Adicione a classe em config/acquirer_routing.php → providers antes de cadastrar.",
                    $validated['acquirer_key']
                ),
                'available_modules' => $this->providers->registeredKeys(),
            ], 422);
        }

        try {
            $config = DB::transaction(function () use ($validated, $request): AcquirerConfig {
                $config = AcquirerConfig::query()->create([
                    'id' => (string) Str::uuid(),
                    'acquirer_key' => $validated['acquirer_key'],
                    'display_name' => $validated['display_name'],
                    'type' => $validated['type'] ?? 'pix',
                    'nominal_name' => $validated['nominal_name'] ?? null,
                    // A brand-new acquirer starts disabled unless explicitly
                    // enabled: it has not been connection-tested yet.
                    'enabled' => (bool) ($validated['enabled'] ?? false),
                    'supported_methods' => $this->normaliseMethods($validated['supported_methods'] ?? ['pix']),
                    'priority' => (int) ($request->input('routing.priority', 100)),
                ]);

                $this->credentials->store($config, (array) ($validated['credentials'] ?? []));
                $this->saveProfile($config, (array) ($validated['routing'] ?? []));
                $this->health->ensure($config->acquirer_key, $config->id);

                return $config;
            });
        } catch (\Throwable $e) {
            Log::error('Falha ao cadastrar adquirente', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Erro ao cadastrar adquirente.'], 500);
        }

        $this->flushCaches($config->acquirer_key);
        $this->audit($config->acquirer_key, 'Adquirente cadastrada', $request);

        return response()->json([
            'success' => true,
            'message' => 'Adquirente cadastrada. Teste a conexão antes de ativar.',
            'data' => ['id' => $config->id, 'acquirer_key' => $config->acquirer_key],
        ], 201);
    }

    public function update(Request $request, string $acquirerId): JsonResponse
    {
        $config = AcquirerConfig::query()->find($acquirerId);

        if (!$config) {
            return $this->notFound();
        }

        $validated = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:120'],
            'nominal_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'type' => ['sometimes', 'string', 'max:60'],
            'enabled' => ['sometimes', 'boolean'],
            'supported_methods' => ['sometimes', 'array'],
            'credentials' => ['sometimes', 'array'],
            'routing' => ['sometimes', 'array'],
        ]);

        try {
            DB::transaction(function () use ($config, $validated): void {
                $attributes = array_filter([
                    'display_name' => $validated['display_name'] ?? null,
                    'type' => $validated['type'] ?? null,
                ], static fn ($v): bool => $v !== null);

                if (array_key_exists('nominal_name', $validated)) {
                    $attributes['nominal_name'] = $validated['nominal_name'];
                }

                if (array_key_exists('enabled', $validated)) {
                    $attributes['enabled'] = (bool) $validated['enabled'];
                }

                if (array_key_exists('supported_methods', $validated)) {
                    $attributes['supported_methods'] = $this->normaliseMethods($validated['supported_methods']);
                }

                if ($attributes !== []) {
                    $config->update($attributes);
                }

                // Masked / empty values are skipped by the repository, so
                // saving the form without retyping a secret keeps it intact.
                if (array_key_exists('credentials', $validated)) {
                    $this->credentials->store($config, (array) $validated['credentials']);
                }

                if (array_key_exists('routing', $validated)) {
                    $this->saveProfile($config, (array) $validated['routing']);
                }
            });
        } catch (\Throwable $e) {
            Log::error('Falha ao atualizar adquirente', ['acquirer' => $config->acquirer_key, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Erro ao atualizar adquirente.'], 500);
        }

        $this->flushCaches($config->acquirer_key);
        $this->audit($config->acquirer_key, 'Adquirente atualizada', $request);

        return response()->json(['success' => true, 'message' => 'Adquirente atualizada.']);
    }

    /**
     * Enable or disable an acquirer.
     *
     * Disabling forces its health state to offline so in-flight routing stops
     * choosing it immediately, without waiting for the catalog cache to expire.
     */
    public function toggle(Request $request, string $acquirerId): JsonResponse
    {
        $config = AcquirerConfig::query()->find($acquirerId);

        if (!$config) {
            return $this->notFound();
        }

        $enabled = (bool) $request->input('enabled', !$config->enabled);

        if ($enabled && !$this->credentials->hasRequiredFields($config)) {
            return response()->json([
                'success' => false,
                'message' => 'Não é possível ativar: credenciais obrigatórias ausentes ou incompletas.',
            ], 422);
        }

        $config->update(['enabled' => $enabled]);

        AcquirerRoutingProfile::query()
            ->where('acquirer_config_id', $config->id)
            ->update(['routing_enabled' => $enabled]);

        if ($enabled) {
            $this->circuitBreaker->reset($config->acquirer_key, 'ativada pelo painel');
            $this->health->setState($config->acquirer_key, HealthState::UNKNOWN, 'ativada pelo painel');
        } else {
            $this->health->setState($config->acquirer_key, HealthState::OFFLINE, 'desativada pelo painel');
        }

        $this->flushCaches($config->acquirer_key);
        $this->audit($config->acquirer_key, $enabled ? 'Adquirente ativada' : 'Adquirente desativada', $request);

        return response()->json([
            'success' => true,
            'message' => $enabled ? 'Adquirente ativada.' : 'Adquirente desativada.',
            'data' => ['enabled' => $enabled],
        ]);
    }

    /**
     * Delete an acquirer and purge its credentials.
     */
    public function destroy(Request $request, string $acquirerId): JsonResponse
    {
        $config = AcquirerConfig::query()->find($acquirerId);

        if (!$config) {
            return $this->notFound();
        }

        // Refuse while it is the only way to process a method it supports.
        $blocking = $this->lastEnabledMethodsFor($config);

        if ($blocking !== [] && $config->enabled) {
            return response()->json([
                'success' => false,
                'message' => sprintf(
                    'Não é possível excluir: é a única adquirente ativa para %s. Ative outra antes.',
                    implode(', ', $blocking)
                ),
            ], 422);
        }

        $key = $config->acquirer_key;

        try {
            DB::transaction(function () use ($config): void {
                $this->credentials->purge($config);
                AcquirerRoutingProfile::query()->where('acquirer_config_id', $config->id)->delete();
                $config->update(['enabled' => false]);
                $config->delete();
            });

            $this->health->forget($key);
        } catch (\Throwable $e) {
            Log::error('Falha ao excluir adquirente', ['acquirer' => $key, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Erro ao excluir adquirente.'], 500);
        }

        $this->flushCaches($key);
        $this->audit($key, 'Adquirente excluída', $request);

        return response()->json(['success' => true, 'message' => 'Adquirente excluída e credenciais removidas.']);
    }

    /**
     * Run a live connectivity test against the acquirer.
     */
    public function testConnection(string $acquirerId): JsonResponse
    {
        $config = AcquirerConfig::query()->find($acquirerId);

        if (!$config) {
            return $this->notFound();
        }

        $result = $this->monitor->check($config->acquirer_key);

        return response()->json([
            'success' => true,
            'data' => array_merge($result->toArray(), [
                'acquirer_key' => $config->acquirer_key,
                'state' => $this->health->find($config->acquirer_key)?->state,
            ]),
            'message' => match (true) {
                $result->skipped => 'Adquirente não expõe endpoint de teste sem efeito colateral.',
                $result->healthy => sprintf('Conexão OK (%d ms).', $result->latencyMs),
                default => 'Falha na conexão: ' . ($result->error ?? 'erro desconhecido'),
            },
        ]);
    }

    /**
     * Force the circuit breaker closed, putting the acquirer back in rotation.
     */
    public function resetCircuit(Request $request, string $acquirerId): JsonResponse
    {
        $config = AcquirerConfig::query()->find($acquirerId);

        if (!$config) {
            return $this->notFound();
        }

        $this->circuitBreaker->reset($config->acquirer_key, 'reset manual pelo painel');
        $this->health->setState($config->acquirer_key, HealthState::UNKNOWN, 'reset manual pelo painel');

        $this->audit($config->acquirer_key, 'Circuit breaker resetado', $request);

        return response()->json(['success' => true, 'message' => 'Circuit breaker resetado.']);
    }

    /**
     * Real-time dashboard: online/offline counts, TPS, latency, approval rate,
     * errors per minute, fallbacks, volume and availability.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $window = $this->window($request);

        $configs = AcquirerConfig::query()->where('enabled', true)->get()->keyBy('acquirer_key');
        $healthStates = $this->health->all();
        $metrics = $this->metrics->overview($window);

        $rows = [];
        $totals = [
            'online' => 0,
            'degraded' => 0,
            'offline' => 0,
            'attempts' => 0,
            'successes' => 0,
            'failures' => 0,
            'fallbacks' => 0,
            'volume_cents' => 0,
            'latency_sum' => 0,
        ];

        foreach ($configs as $key => $config) {
            $state = $healthStates->get($key);
            $metric = $metrics[$key] ?? null;
            $healthState = $state?->healthState() ?? HealthState::UNKNOWN;

            match ($healthState) {
                HealthState::ONLINE, HealthState::UNKNOWN => $totals['online']++,
                HealthState::DEGRADED => $totals['degraded']++,
                HealthState::OFFLINE => $totals['offline']++,
            };

            if ($metric !== null) {
                $totals['attempts'] += $metric->attempts;
                $totals['successes'] += $metric->successes;
                $totals['failures'] += $metric->failures;
                $totals['fallbacks'] += $metric->fallbackIns;
                $totals['volume_cents'] += $metric->volumeCents;
                $totals['latency_sum'] += $metric->latencySumMs;
            }

            $rows[] = [
                'acquirer_key' => $key,
                'display_name' => $config->display_name,
                'health' => $state?->toDashboardArray() ?? $this->emptyHealth($key),
                'metrics' => $metric?->toArray() ?? [],
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'acquirers' => $rows,
                'summary' => [
                    'total' => $configs->count(),
                    'online' => $totals['online'],
                    'degraded' => $totals['degraded'],
                    'offline' => $totals['offline'],
                    'attempts' => $totals['attempts'],
                    'approval_rate' => $totals['attempts'] > 0
                        ? round($totals['successes'] / $totals['attempts'], 4)
                        : null,
                    'errors_per_minute' => $window > 0 ? round($totals['failures'] / $window, 2) : 0,
                    'tps' => round($totals['attempts'] / max(1, $window * 60), 3),
                    'avg_latency_ms' => $totals['attempts'] > 0
                        ? round($totals['latency_sum'] / $totals['attempts'], 1)
                        : null,
                    'fallbacks' => $totals['fallbacks'],
                    'volume_cents' => $totals['volume_cents'],
                ],
                'series' => $this->metrics->timeSeries($request->query('acquirer_key'), $window),
                'window_minutes' => $window,
            ],
        ]);
    }

    /**
     * Recent routing decisions, for auditing why a transaction landed where it did.
     */
    public function routingLogs(Request $request): JsonResponse
    {
        $query = AcquirerRoutingLog::query()->orderByDesc('created_at');

        foreach (['correlation_id', 'charge_id', 'account_id', 'selected_acquirer', 'outcome', 'payment_method'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        if ($request->boolean('only_fallbacks')) {
            $query->where('attempt_count', '>', 1);
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate(min(100, (int) $request->query('per_page', 25))),
        ]);
    }

    /**
     * Status-change / circuit-breaker / health-check audit trail.
     */
    public function events(Request $request): JsonResponse
    {
        $query = AcquirerEvent::query()->orderByDesc('occurred_at');

        if ($request->filled('acquirer_key')) {
            $query->where('acquirer_key', $request->query('acquirer_key'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->query('severity'));
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate(min(100, (int) $request->query('per_page', 50))),
        ]);
    }

    /**
     * Balancing policies, globally or per establishment.
     */
    public function getPolicies(Request $request): JsonResponse
    {
        $accountId = $request->query('account_id');

        return response()->json([
            'success' => true,
            'data' => [
                'global' => array_map($this->policyPayload(...), $this->policies->allGlobal()),
                'account' => $accountId
                    ? array_map($this->policyPayload(...), $this->policies->forAccount((string) $accountId))
                    : [],
                'strategies' => $this->strategies->available(),
            ],
        ]);
    }

    public function savePolicy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'string'],
            'account_id' => ['nullable', 'uuid'],
            'strategy' => ['required', 'string'],
            'failover_enabled' => ['nullable', 'boolean'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'total_budget_ms' => ['nullable', 'integer', 'min:1000', 'max:120000'],
            'strict_health_check' => ['nullable', 'boolean'],
            'settings' => ['nullable', 'array'],
        ]);

        $method = PaymentMethod::normalize($validated['payment_method']);
        $policy = $this->policies->save($method, $validated['account_id'] ?? null, $validated);

        $this->audit('*', sprintf('Política de roteamento atualizada (%s)', $method->value), $request);

        return response()->json([
            'success' => true,
            'message' => 'Política de roteamento salva.',
            'data' => $this->policyPayload($policy),
        ]);
    }

    /**
     * Dry-run: what chain would this transaction get, and why?
     */
    public function previewRouting(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'string'],
            'amount_cents' => ['nullable', 'integer', 'min:0'],
            'account_id' => ['nullable', 'uuid'],
        ]);

        $context = RoutingContext::make(
            method: $validated['payment_method'],
            amountCents: (int) ($validated['amount_cents'] ?? 10000),
            accountId: $validated['account_id'] ?? null,
        );

        $decision = $this->router->route($context);

        return response()->json([
            'success' => true,
            'data' => [
                'strategy' => $decision->strategy->value,
                'strategy_label' => $decision->strategy->label(),
                'reason' => $decision->reason,
                'chain' => array_map(
                    static fn ($c): array => $c->toLogArray(),
                    $decision->chain
                ),
                'excluded' => $decision->excluded,
                // Preview must not advance the round-robin cursor for real
                // traffic; it does, so this is flagged for the operator.
                'note' => 'Estratégias de rotação avançam o cursor ao simular.',
            ],
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    private function saveProfile(AcquirerConfig $config, array $input): void
    {
        $defaults = (array) config('acquirer_routing.profile_defaults', []);

        $profile = AcquirerRoutingProfile::query()->firstOrNew(['acquirer_config_id' => $config->id]);

        $profile->fill([
            'acquirer_config_id' => $config->id,
            'acquirer_key' => $config->acquirer_key,
            'routing_enabled' => (bool) ($input['routing_enabled'] ?? $profile->routing_enabled ?? $config->enabled),
            'priority' => $this->clamp($input['priority'] ?? $profile->priority ?? $defaults['priority'], 0, 10000),
            'weight' => $this->clamp($input['weight'] ?? $profile->weight ?? $defaults['weight'], 1, 100),
            'max_tps' => $this->nullableClamp($input['max_tps'] ?? $profile->max_tps, 1, 100000),
            'max_concurrent' => $this->nullableClamp($input['max_concurrent'] ?? $profile->max_concurrent, 1, 10000),
            'connect_timeout_ms' => $this->clamp($input['connect_timeout_ms'] ?? $profile->connect_timeout_ms ?? $defaults['connect_timeout_ms'], 500, 60000),
            'timeout_ms' => $this->clamp($input['timeout_ms'] ?? $profile->timeout_ms ?? $defaults['timeout_ms'], 1000, 120000),
            'max_retries' => $this->clamp($input['max_retries'] ?? $profile->max_retries ?? $defaults['max_retries'], 0, 5),
            'retry_backoff_ms' => $this->clamp($input['retry_backoff_ms'] ?? $profile->retry_backoff_ms ?? $defaults['retry_backoff_ms'], 0, 10000),
            'retry_backoff_max_ms' => $this->clamp($input['retry_backoff_max_ms'] ?? $profile->retry_backoff_max_ms ?? $defaults['retry_backoff_max_ms'], 0, 60000),
            'cb_failure_threshold' => $this->clamp($input['cb_failure_threshold'] ?? $profile->cb_failure_threshold ?? $defaults['cb_failure_threshold'], 1, 100),
            'cb_cooldown_seconds' => $this->clamp($input['cb_cooldown_seconds'] ?? $profile->cb_cooldown_seconds ?? $defaults['cb_cooldown_seconds'], 5, 3600),
            'cb_half_open_max_calls' => $this->clamp($input['cb_half_open_max_calls'] ?? $profile->cb_half_open_max_calls ?? $defaults['cb_half_open_max_calls'], 1, 20),
            'cb_success_threshold' => $this->clamp($input['cb_success_threshold'] ?? $profile->cb_success_threshold ?? $defaults['cb_success_threshold'], 1, 20),
            'health_check_enabled' => (bool) ($input['health_check_enabled'] ?? $profile->health_check_enabled ?? true),
            'health_check_url' => $input['health_check_url'] ?? $profile->health_check_url,
            'health_check_interval_seconds' => $this->clamp($input['health_check_interval_seconds'] ?? $profile->health_check_interval_seconds ?? $defaults['health_check_interval_seconds'], 15, 3600),
            'min_amount_cents' => $this->nullableClamp($input['min_amount_cents'] ?? $profile->min_amount_cents, 0, PHP_INT_MAX),
            'max_amount_cents' => $this->nullableClamp($input['max_amount_cents'] ?? $profile->max_amount_cents, 0, PHP_INT_MAX),
            'rules' => is_array($input['rules'] ?? null) ? $input['rules'] : ($profile->rules ?? []),
        ]);

        if (!$profile->exists) {
            $profile->id = (string) Str::uuid();
        }

        $profile->save();

        // Keep the legacy priority column in sync so code still reading
        // acquirer_configs.priority stays consistent.
        if ((int) $config->priority !== (int) $profile->priority) {
            $config->update(['priority' => $profile->priority]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayload(AcquirerRoutingProfile $profile): array
    {
        return [
            'routing_enabled' => (bool) $profile->routing_enabled,
            'priority' => (int) $profile->priority,
            'weight' => (int) $profile->weight,
            'max_tps' => $profile->max_tps,
            'max_concurrent' => $profile->max_concurrent,
            'connect_timeout_ms' => (int) $profile->connect_timeout_ms,
            'timeout_ms' => (int) $profile->timeout_ms,
            'max_retries' => (int) $profile->max_retries,
            'retry_backoff_ms' => (int) $profile->retry_backoff_ms,
            'retry_backoff_max_ms' => (int) $profile->retry_backoff_max_ms,
            'cb_failure_threshold' => (int) $profile->cb_failure_threshold,
            'cb_cooldown_seconds' => (int) $profile->cb_cooldown_seconds,
            'cb_half_open_max_calls' => (int) $profile->cb_half_open_max_calls,
            'cb_success_threshold' => (int) $profile->cb_success_threshold,
            'health_check_enabled' => (bool) $profile->health_check_enabled,
            'health_check_url' => $profile->health_check_url,
            'health_check_interval_seconds' => (int) $profile->health_check_interval_seconds,
            'min_amount_cents' => $profile->min_amount_cents,
            'max_amount_cents' => $profile->max_amount_cents,
            'rules' => $profile->rules ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function policyPayload(\App\Models\AcquirerRoutingPolicy $policy): array
    {
        return [
            'scope' => $policy->scope,
            'account_id' => $policy->account_id,
            'payment_method' => $policy->payment_method,
            'payment_method_label' => $policy->methodEnum()->label(),
            'strategy' => $policy->strategy,
            'strategy_label' => $policy->strategyEnum()->label(),
            'failover_enabled' => (bool) $policy->failover_enabled,
            'max_attempts' => (int) $policy->max_attempts,
            'total_budget_ms' => (int) $policy->total_budget_ms,
            'strict_health_check' => (bool) $policy->strict_health_check,
            'settings' => $policy->settings ?? [],
        ];
    }

    /**
     * Methods that would lose their last enabled acquirer if this one went away.
     *
     * @return array<int, string>
     */
    private function lastEnabledMethodsFor(AcquirerConfig $config): array
    {
        $others = AcquirerConfig::query()
            ->where('enabled', true)
            ->where('id', '!=', $config->id)
            ->get();

        $orphaned = [];

        foreach ((array) ($config->supported_methods ?? []) as $rawMethod) {
            $method = PaymentMethod::normalize((string) $rawMethod);

            $covered = $others->contains(function (AcquirerConfig $other) use ($method): bool {
                $supported = array_map('strtolower', (array) ($other->supported_methods ?? []));

                foreach ($method->aliases() as $alias) {
                    if (in_array($alias, $supported, true)) {
                        return true;
                    }
                }

                return false;
            });

            if (!$covered) {
                $orphaned[] = $method->label();
            }
        }

        return array_values(array_unique($orphaned));
    }

    /**
     * @param array<int, string> $methods
     * @return array<int, string>
     */
    private function normaliseMethods(array $methods): array
    {
        $normalised = [];

        foreach ($methods as $method) {
            $value = PaymentMethod::normalize((string) $method)->value;

            if (!in_array($value, $normalised, true)) {
                $normalised[] = $value;
            }
        }

        return $normalised === [] ? ['pix'] : $normalised;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyHealth(string $acquirerKey): array
    {
        return [
            'acquirer_key' => $acquirerKey,
            'state' => HealthState::UNKNOWN->value,
            'state_label' => HealthState::UNKNOWN->label(),
            'circuit_state' => 'closed',
            'circuit_state_label' => 'Fechado',
            'consecutive_failures' => 0,
            'circuit_trip_count' => 0,
            'last_latency_ms' => null,
            'avg_latency_ms' => null,
            'availability_percent' => 100.0,
            'last_error' => null,
            'last_error_at' => null,
            'last_success_at' => null,
            'last_check_at' => null,
        ];
    }

    private function window(Request $request): int
    {
        return max(1, min(1440, (int) $request->query('window_minutes', 60)));
    }

    private function clamp(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    private function nullableClamp(mixed $value, int $min, int $max): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->clamp($value, $min, $max);
    }

    private function flushCaches(string $acquirerKey): void
    {
        $this->catalog->flush();
        $this->providers->flush($acquirerKey);
        AcquirerConfig::clearCache();
    }

    private function audit(string $acquirerKey, string $message, Request $request): void
    {
        try {
            AcquirerEvent::query()->create([
                'id' => (string) Str::uuid(),
                'acquirer_key' => $acquirerKey,
                'type' => AcquirerEvent::TYPE_CONFIG_CHANGED,
                'severity' => 'info',
                'message' => $message,
                'context' => [
                    'admin_id' => $request->user()?->id,
                    'ip' => $request->ip(),
                ],
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Falha ao registrar auditoria de adquirente', ['error' => $e->getMessage()]);
        }
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Adquirente não encontrada.'], 404);
    }
}
