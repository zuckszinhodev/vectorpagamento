<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AcquirerConfig;
use App\Models\Transaction;
use App\Services\AcquirerRotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Acquirer Management — extracted from AdminController
 */
class AcquirerAdminController extends Controller
{
    /**
     * List all acquirers with statistics
     */
    public function index(): JsonResponse
    {
        try {
            $thirtyDaysAgo = now()->subDays(30);

            $transactionStats = Transaction::where('created_at', '>=', $thirtyDaysAgo)
                ->select('acquirer_id')
                ->selectRaw('COUNT(*) as total_transactions')
                ->selectRaw("SUM(CASE WHEN status = 'paid' OR status = 'captured' THEN 1 ELSE 0 END) as successful_transactions")
                ->groupBy('acquirer_id')
                ->get()
                ->keyBy('acquirer_id');

            $acquirerConfigs = AcquirerConfig::orderBy('priority')->get();

            $primaryId = $acquirerConfigs->where('priority', 0)->first()?->id;

            $acquirers = $acquirerConfigs->map(function ($config) use ($transactionStats, $primaryId) {
                $stats = $transactionStats->get($config->acquirer_key);
                $totalTx = $stats?->total_transactions ?? 0;
                $successTx = $stats?->successful_transactions ?? 0;
                $successRate = $totalTx > 0 ? round(($successTx / $totalTx) * 100, 1) : 0;

                $hasCredentials = false;
                try {
                    $hasCredentials = $config->hasValidCredentials();
                } catch (\Throwable $e) {
                    // Decryption or config error — treat as no credentials
                }

                $supportedMethods = $config->supported_methods ?? [];

                return [
                    'id' => $config->id,
                    'name' => $config->acquirer_key,
                    'slug' => $config->acquirer_key,
                    'display_name' => $config->display_name,
                    'logo_url' => $config->settings['logo_url'] ?? null,
                    'is_active' => $config->enabled,
                    'is_primary' => $config->id === $primaryId,
                    'priority' => $config->priority,
                    'supports_pix' => in_array('pix', $supportedMethods),
                    'supports_pix_out' => in_array('pix_out', $supportedMethods),
                    'supports_credit_card' => in_array('credit_card', $supportedMethods) || in_array('card', $supportedMethods),
                    'supports_boleto' => in_array('boleto', $supportedMethods),
                    'fee_pix' => (float) $config->gateway_fee_percentage,
                    'fee_credit_card' => (float) $config->gateway_fee_percentage,
                    'fee_boleto' => (float) $config->gateway_fee_percentage,
                    'acquirer_fee_percentage' => (float) ($config->acquirer_fee_percentage ?? 0),
                    'acquirer_fee_fixed' => (float) ($config->acquirer_fee_fixed ?? 0),
                    'acquirer_fee_pix_out_percentage' => (float) ($config->acquirer_fee_pix_out_percentage ?? 0),
                    'acquirer_fee_pix_out_fixed' => (float) ($config->acquirer_fee_pix_out_fixed ?? 0),
                    'gateway_fee_percentage' => (float) ($config->gateway_fee_percentage ?? 0),
                    'gateway_fee_fixed' => (float) ($config->gateway_fee_fixed ?? 0),
                    'gateway_fee_pix_out_percentage' => (float) ($config->gateway_fee_pix_out_percentage ?? 0),
                    'gateway_fee_pix_out_fixed' => (float) ($config->gateway_fee_pix_out_fixed ?? 0),
                    'nominal_name' => $config->nominal_name ?? null,
                    'sandbox_mode' => (bool) ($config->settings['sandbox'] ?? false),
                    'health_status' => $config->settings['health_status'] ?? null,
                    'last_health_check' => $config->settings['last_health_check'] ?? null,
                    'total_transactions' => $totalTx,
                    'success_rate' => $successRate,
                    'has_credentials' => $hasCredentials,
                ];
            });

            return response()->json(['success' => true, 'data' => $acquirers]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AcquirerAdminController::index error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao listar adquirentes'], 500);
        }
    }

    /**
     * Get global acquirer rotation settings and preview order.
     */
    public function getRoutingSettings(): JsonResponse
    {
        try {
            /** @var AcquirerRotationService $rotation */
            $rotation = app(AcquirerRotationService::class);
            $settings = $rotation->getSettings();

            $preview = [];
            foreach (['pix', 'credit_card', 'boleto', 'pix_out'] as $method) {
                $preview[$method] = $rotation->buildPreview($method, $this->resolveCandidatesForMethod($method));
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'settings' => $settings,
                    'preview' => $preview,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AcquirerAdminController::getRoutingSettings error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao carregar configuracoes de rotacao'], 500);
        }
    }

    /**
     * Update global acquirer rotation settings.
     */
    public function updateRoutingSettings(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'enabled' => ['sometimes', 'boolean'],
                'failover_enabled' => ['sometimes', 'boolean'],
                'strict_health_check' => ['sometimes', 'boolean'],
                'use_cooldown' => ['sometimes', 'boolean'],
                'cooldown_seconds' => ['sometimes', 'integer', 'min:5', 'max:3600'],
                'methods' => ['sometimes', 'array'],
                'methods.pix.strategy' => ['sometimes', 'in:priority,round_robin,weighted'],
                'methods.credit_card.strategy' => ['sometimes', 'in:priority,round_robin,weighted'],
                'methods.boleto.strategy' => ['sometimes', 'in:priority,round_robin,weighted'],
                'methods.pix_out.strategy' => ['sometimes', 'in:priority,round_robin,weighted'],
                'methods.*.weights' => ['sometimes', 'array'],
                'methods.*.weights.*' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            ]);

            /** @var AcquirerRotationService $rotation */
            $rotation = app(AcquirerRotationService::class);
            $settings = $rotation->updateSettings($validated);

            $preview = [];
            foreach (['pix', 'credit_card', 'boleto', 'pix_out'] as $method) {
                $preview[$method] = $rotation->buildPreview($method, $this->resolveCandidatesForMethod($method));
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'settings' => $settings,
                    'preview' => $preview,
                ],
                'message' => 'Configuracoes de rotacao atualizadas com sucesso',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AcquirerAdminController::updateRoutingSettings error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao atualizar configuracoes de rotacao'], 500);
        }
    }

    /**
     * Preview routing order for all or a specific payment method.
     */
    public function previewRouting(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'payment_method' => ['nullable', 'in:pix,credit_card,boleto,pix_out'],
            ]);

            /** @var AcquirerRotationService $rotation */
            $rotation = app(AcquirerRotationService::class);
            $methods = isset($validated['payment_method'])
                ? [$validated['payment_method']]
                : ['pix', 'credit_card', 'boleto', 'pix_out'];

            $preview = [];
            foreach ($methods as $method) {
                $preview[$method] = $rotation->buildPreview($method, $this->resolveCandidatesForMethod($method));
            }

            return response()->json([
                'success' => true,
                'data' => $preview,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AcquirerAdminController::previewRouting error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao gerar preview de rotacao'], 500);
        }
    }

    /**
     * Reorder acquirers in bulk by priority.
     */
    public function reorder(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'acquirers' => ['required', 'array', 'min:1'],
                'acquirers.*.id' => ['required', 'uuid'],
                'acquirers.*.priority' => ['required', 'integer', 'min:0'],
            ]);

            DB::transaction(function () use ($validated) {
                foreach ($validated['acquirers'] as $item) {
                    AcquirerConfig::where('id', $item['id'])->update([
                        'priority' => (int) $item['priority'],
                        'updated_at' => now(),
                    ]);
                }
            });

            AcquirerConfig::clearCache();
            \App\Services\AcquirerFactory::reset();

            return response()->json([
                'success' => true,
                'data' => AcquirerConfig::orderBy('priority')->get(['id', 'acquirer_key', 'display_name', 'priority']),
                'message' => 'Prioridades atualizadas com sucesso',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AcquirerAdminController::reorder error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao reordenar adquirentes'], 500);
        }
    }

    /**
     * Get single acquirer details
     */
    public function show(string $acquirerId): JsonResponse
    {
        try {
            $config = AcquirerConfig::find($acquirerId);
            if (!$config) {
                return response()->json(['success' => false, 'message' => 'Adquirente não encontrado'], 404);
            }

            $hasCredentials = false;
            try {
                $hasCredentials = $config->hasValidCredentials();
            } catch (\Throwable $e) {
                // Decryption or config error
            }

            $credentialFields = [];
            try {
                $credentialFields = AcquirerConfig::getCredentialFields($config->acquirer_key);
            } catch (\Throwable $e) {
                // Method may not exist or config missing
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $config->id,
                    'key' => $config->acquirer_key,
                    'name' => $config->display_name,
                    'type' => $config->type,
                    'enabled' => $config->enabled,
                    'priority' => $config->priority,
                    'acquirer_fee_percentage' => (float) $config->acquirer_fee_percentage,
                    'acquirer_fee_fixed' => (float) $config->acquirer_fee_fixed,
                    'gateway_fee_percentage' => (float) $config->gateway_fee_percentage,
                    'gateway_fee_fixed' => (float) $config->gateway_fee_fixed,
                    'supported_methods' => $config->supported_methods ?? [],
                    'has_credentials' => $hasCredentials,
                    'credential_fields' => $credentialFields,
                    'settings' => $config->settings ?? [],
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AcquirerAdminController::show error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao carregar adquirente'], 500);
        }
    }

    /**
     * Update acquirer configuration
     */
    public function update(Request $request, string $acquirerId): JsonResponse
    {
        try {
        $config = AcquirerConfig::find($acquirerId);
        if (!$config) {
            return response()->json(['success' => false, 'message' => 'Adquirente não encontrado'], 404);
        }

        $validated = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'fee_pix' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'fee_credit_card' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'fee_boleto' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'supports_pix' => ['sometimes', 'boolean'],
            'supports_pix_out' => ['sometimes', 'boolean'],
            'supports_credit_card' => ['sometimes', 'boolean'],
            'supports_boleto' => ['sometimes', 'boolean'],
            'sandbox_mode' => ['sometimes', 'boolean'],
            'api_key' => ['sometimes', 'nullable', 'string'],
            'api_secret' => ['sometimes', 'nullable', 'string'],
            'webhook_secret' => ['sometimes', 'nullable', 'string'],
            'transfer_webhook_secret' => ['sometimes', 'nullable', 'string'],
            'merchant_id' => ['sometimes', 'nullable', 'string'],
            'credentials' => ['sometimes', 'array'],
            'acquirer_fee_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'acquirer_fee_fixed' => ['sometimes', 'numeric', 'min:0'],
            'acquirer_fee_pix_out_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'acquirer_fee_pix_out_fixed' => ['sometimes', 'numeric', 'min:0'],
            'gateway_fee_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'gateway_fee_fixed' => ['sometimes', 'numeric', 'min:0'],
            'gateway_fee_pix_out_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'gateway_fee_pix_out_fixed' => ['sometimes', 'numeric', 'min:0'],
        ]);

        // Handle display_name
        if (isset($validated['display_name'])) {
            $config->display_name = $validated['display_name'];
        }

        // Handle is_active (frontend sends this) or enabled (legacy)
        if (isset($validated['is_active'])) {
            $config->enabled = $validated['is_active'];
        } elseif (isset($validated['enabled'])) {
            $config->enabled = $validated['enabled'];
        }

        // Handle priority
        if (isset($validated['priority'])) {
            $config->priority = $validated['priority'];
        }

        // Handle fees - use fee_pix/fee_credit_card as gateway_fee_percentage
        if (isset($validated['fee_pix']) || isset($validated['fee_credit_card'])) {
            $config->gateway_fee_percentage = $validated['fee_credit_card'] ?? $validated['fee_pix'] ?? $config->gateway_fee_percentage;
        }

        // Handle supported methods
        $supportedMethods = $config->supported_methods ?? [];
        if (isset($validated['supports_pix'])) {
            if ($validated['supports_pix'] && !in_array('pix', $supportedMethods)) {
                $supportedMethods[] = 'pix';
            } elseif (!$validated['supports_pix']) {
                $supportedMethods = array_filter($supportedMethods, fn($m) => $m !== 'pix');
            }
        }
        if (isset($validated['supports_pix_out'])) {
            if ($validated['supports_pix_out'] && !in_array('pix_out', $supportedMethods)) {
                $supportedMethods[] = 'pix_out';
            } elseif (!$validated['supports_pix_out']) {
                $supportedMethods = array_filter($supportedMethods, fn($m) => $m !== 'pix_out');
            }
        }
        if (isset($validated['supports_credit_card'])) {
            if ($validated['supports_credit_card'] && !in_array('credit_card', $supportedMethods)) {
                $supportedMethods[] = 'credit_card';
            } elseif (!$validated['supports_credit_card']) {
                $supportedMethods = array_filter($supportedMethods, fn($m) => $m !== 'credit_card' && $m !== 'card');
            }
        }
        if (isset($validated['supports_boleto'])) {
            if ($validated['supports_boleto'] && !in_array('boleto', $supportedMethods)) {
                $supportedMethods[] = 'boleto';
            } elseif (!$validated['supports_boleto']) {
                $supportedMethods = array_filter($supportedMethods, fn($m) => $m !== 'boleto');
            }
        }
        $config->supported_methods = array_values(array_unique($supportedMethods));

        // Handle sandbox mode in settings
        $settings = $config->settings ?? [];
        if (isset($validated['sandbox_mode'])) {
            $settings['sandbox'] = $validated['sandbox_mode'];
        }
        $config->settings = $settings;

        // Handle credentials
        $credentials = $config->credentials ?? [];
        if (isset($validated['api_key']) && $validated['api_key']) {
            $credentials['api_key'] = $validated['api_key'];
        }
        if (isset($validated['api_secret']) && $validated['api_secret']) {
            $credentials['api_secret'] = $validated['api_secret'];
            $credentials['secret_key'] = $validated['api_secret']; // Stripe compatibility
        }
        if (isset($validated['webhook_secret']) && $validated['webhook_secret']) {
            $credentials['webhook_secret'] = $validated['webhook_secret'];
        }
        if (isset($validated['transfer_webhook_secret']) && $validated['transfer_webhook_secret']) {
            $credentials['transfer_webhook_secret'] = $validated['transfer_webhook_secret'];
        }
        if (isset($validated['merchant_id']) && $validated['merchant_id']) {
            $credentials['merchant_id'] = $validated['merchant_id'];
        }
        if (isset($validated['credentials'])) {
            $credentials = array_merge($credentials, $validated['credentials']);
        }
        if (!empty($credentials)) {
            $config->credentials = $credentials;
        }

        // Handle other direct updatable fields
        if (isset($validated['acquirer_fee_percentage'])) {
            $config->acquirer_fee_percentage = $validated['acquirer_fee_percentage'];
        }
        if (isset($validated['acquirer_fee_fixed'])) {
            $config->acquirer_fee_fixed = $validated['acquirer_fee_fixed'];
        }
        if (isset($validated['acquirer_fee_pix_out_percentage'])) {
            $config->acquirer_fee_pix_out_percentage = $validated['acquirer_fee_pix_out_percentage'];
        }
        if (isset($validated['acquirer_fee_pix_out_fixed'])) {
            $config->acquirer_fee_pix_out_fixed = $validated['acquirer_fee_pix_out_fixed'];
        }
        if (isset($validated['gateway_fee_percentage'])) {
            $config->gateway_fee_percentage = $validated['gateway_fee_percentage'];
        }
        if (isset($validated['gateway_fee_fixed'])) {
            $config->gateway_fee_fixed = $validated['gateway_fee_fixed'];
        }
        if (isset($validated['gateway_fee_pix_out_percentage'])) {
            $config->gateway_fee_pix_out_percentage = $validated['gateway_fee_pix_out_percentage'];
        }
        if (isset($validated['gateway_fee_pix_out_fixed'])) {
            $config->gateway_fee_pix_out_fixed = $validated['gateway_fee_pix_out_fixed'];
        }
        $config->save();

        // Reset the AcquirerFactory singleton so the next request picks up the new config
        \App\Services\AcquirerFactory::reset();

        $hasCredentials = false;
        try {
            $hasCredentials = $config->hasValidCredentials();
        } catch (\Throwable $e) {
            // Decryption or config error
        }

        $supportedMethods = $config->supported_methods ?? [];
        $settings = $config->settings ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $config->id,
                'key' => $config->acquirer_key,
                'name' => $config->display_name ?? $config->acquirer_key,
                'display_name' => $config->display_name ?? $config->acquirer_key,
                'slug' => $config->acquirer_key,
                'is_active' => $config->enabled,
                'is_primary' => $config->priority === 0,
                'priority' => $config->priority,
                'supports_pix' => in_array('pix', $supportedMethods),
                'supports_pix_out' => in_array('pix_out', $supportedMethods),
                'supports_credit_card' => in_array('credit_card', $supportedMethods) || in_array('card', $supportedMethods),
                'supports_boleto' => in_array('boleto', $supportedMethods),
                'fee_pix' => $config->gateway_fee_percentage ?? 0,
                'fee_credit_card' => $config->gateway_fee_percentage ?? 0,
                'sandbox_mode' => $settings['sandbox'] ?? false,
                'has_credentials' => $hasCredentials,
            ],
            'message' => 'Adquirente atualizado com sucesso',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AcquirerAdminController::update error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao atualizar adquirente'], 500);
        }
    }

    /**
     * Set acquirer as primary (priority 0) and bump others
     */
    public function setPrimary(string $acquirerId): JsonResponse
    {
        try {
            $config = AcquirerConfig::find($acquirerId);
            if (!$config) {
                return response()->json(['success' => false, 'message' => 'Adquirente não encontrado'], 404);
            }

            DB::transaction(function () use ($config) {
                AcquirerConfig::where('id', '!=', $config->id)
                    ->where('priority', '>=', 0)
                    ->increment('priority');

                $config->update(['priority' => 0, 'enabled' => true]);
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $config->id,
                    'key' => $config->acquirer_key,
                    'name' => $config->display_name,
                    'priority' => 0,
                ],
                'message' => $config->display_name . ' definido como adquirente principal',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AcquirerAdminController::setPrimary error', ['error' => $e->getMessage(), 'acquirer_id' => $acquirerId]);
            return response()->json(['success' => false, 'message' => 'Erro ao definir adquirente principal'], 500);
        }
    }

    /**
     * Toggle acquirer enabled/disabled
     * Uses raw DB query to bypass Eloquent events/casts that can fail
     */
    public function toggle(string $acquirerId): JsonResponse
    {
        try {
            \Illuminate\Support\Facades\Log::info('AcquirerAdminController::toggle START', ['acquirer_id' => $acquirerId]);

            // Use raw DB query to avoid Eloquent model events / cache issues
            $config = DB::table('acquirer_configs')->where('id', $acquirerId)->first();

            if (!$config) {
                \Illuminate\Support\Facades\Log::warning('AcquirerAdminController::toggle - acquirer not found', ['acquirer_id' => $acquirerId]);
                return response()->json(['success' => false, 'message' => 'Adquirente não encontrado'], 404);
            }

            $newEnabled = (bool) !$config->enabled;

            DB::table('acquirer_configs')
                ->where('id', $acquirerId)
                ->update(['enabled' => (bool) $newEnabled, 'updated_at' => now()]);

            \Illuminate\Support\Facades\Log::info('AcquirerAdminController::toggle DB updated', [
                'acquirer_id' => $acquirerId,
                'enabled' => $newEnabled,
            ]);

            // Clear cache safely (non-critical)
            try {
                AcquirerConfig::clearCache();
            } catch (\Throwable $cacheErr) {
                \Illuminate\Support\Facades\Log::warning('Toggle cache clear failed', ['error' => $cacheErr->getMessage()]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $config->id,
                    'key' => $config->acquirer_key,
                    'enabled' => $newEnabled,
                ],
                'message' => $config->display_name . ($newEnabled ? ' ativado' : ' desativado') . ' com sucesso',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AcquirerAdminController::toggle error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'acquirer_id' => $acquirerId,
            ]);
            return response()->json(['success' => false, 'message' => 'Erro ao alterar status do adquirente'], 500);
        }
    }

    /**
     * Refresh the displayed nominal name by generating a fresh PIX charge
     * against the acquirer and extracting the merchant name from the payload.
     */
    public function refreshNominal(string $acquirerId): JsonResponse
    {
        try {
            $config = AcquirerConfig::find($acquirerId);
            if (!$config) {
                return response()->json(['success' => false, 'message' => 'Adquirente nao encontrado'], 404);
            }

            $result = app(\App\Services\PixNominalNameService::class)
                ->refreshAcquirerNominal($config->acquirer_key, request()->user()?->account_id);

            if (!($result['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Falha ao gerar PIX para verificar nominal: ' . ($result['message'] ?? 'adquirente não configurada'),
                ], 422);
            }

            $config->refresh();
            AcquirerConfig::clearCache();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $config->id,
                    'key' => $config->acquirer_key,
                    'nominal_name' => $config->nominal_name,
                ],
                'message' => 'Nominal atualizada para ' . $config->nominal_name,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AcquirerAdminController::refreshNominal error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'acquirer_id' => $acquirerId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar nominal: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Refresh nominal names for ALL active acquirers by generating a test PIX on each.
     */
    public function refreshAllNominals(): JsonResponse
    {
        try {
            $results = app(\App\Services\PixNominalNameService::class)
                ->refreshAllAcquirerNominals(request()->user()?->account_id);

            $successCount = count(array_filter($results, fn ($r) => $r['success']));

            return response()->json([
                'success' => true,
                'data' => $results,
                'message' => "{$successCount}/" . count($results) . " nominais atualizadas",
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AcquirerAdminController::refreshAllNominals error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Erro ao atualizar nominais'], 500);
        }
    }

    /**
     * Perform health check on acquirer API
     */
    public function healthCheck(string $acquirerId): JsonResponse
    {
        try {
            $config = AcquirerConfig::find($acquirerId);
            if (!$config) {
                return response()->json(['success' => false, 'message' => 'Adquirente não encontrado'], 404);
            }

            $status = 'healthy';
            $responseTime = null;
            $errorMessage = null;

            // Try to perform a simple API check
            try {
                $start = microtime(true);
                
                // Different health check logic per acquirer
                $healthy = match ($config->acquirer_key) {
                    'payfort' => $this->checkPayfortHealth($config),
                    'blupay' => $this->checkBlupayHealth($config),
                    'enki' => $this->checkEnkiHealth(),
                    'stripe' => $this->checkStripeHealth($config),
                    'pagarme' => $this->checkPagarmeHealth($config),
                    default => true, // Assume healthy if no specific check
                };

                $responseTime = round((microtime(true) - $start) * 1000); // ms
                
                if (!$healthy) {
                    $status = 'degraded';
                }
            } catch (\Throwable $e) {
                $status = 'down';
                $errorMessage = $e->getMessage();
                \Illuminate\Support\Facades\Log::warning('Acquirer health check failed', [
                    'acquirer' => $config->acquirer_key,
                    'error' => $e->getMessage(),
                ]);
            }

            // Update last health check timestamp
            $config->update([
                'settings' => array_merge($config->settings ?? [], [
                    'last_health_check' => now()->toIso8601String(),
                    'health_status' => $status,
                ]),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $config->id,
                    'acquirer' => $config->acquirer_key,
                    'status' => $status,
                    'response_time_ms' => $responseTime,
                    'error' => $errorMessage,
                    'checked_at' => now()->toIso8601String(),
                ],
                'message' => 'Health check executado',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AcquirerAdminController::healthCheck error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao executar health check'], 500);
        }
    }

    /**
     * Soft delete an acquirer (will no longer be used for payments)
     */
    public function destroy(string $acquirerId): JsonResponse
    {
        try {
            $config = AcquirerConfig::find($acquirerId);
            if (!$config) {
                return response()->json(['success' => false, 'message' => 'Adquirente não encontrado'], 404);
            }

            if ($config->priority === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível excluir o gateway primário. Defina outro como primário primeiro.',
                ], 422);
            }

            $config->enabled = false;
            $config->save();
            $config->delete(); // soft delete

            AcquirerConfig::clearCache();

            return response()->json([
                'success' => true,
                'message' => 'Adquirente excluído com sucesso',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AcquirerAdminController::destroy error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao excluir adquirente'], 500);
        }
    }

    /**
     * Check PayFort API health
     */
    private function checkPayfortHealth(AcquirerConfig $config): bool
    {
        // Simple check - return true if credentials exist
        return $config->hasValidCredentials();
    }

    /**
     * Check BluPay API health
     */
    private function checkBlupayHealth(AcquirerConfig $config): bool
    {
        return $config->hasValidCredentials();
    }

    /**
     * Check Enki API health
     */
    private function checkEnkiHealth(): bool
    {
        $acquirer = \App\Services\AcquirerFactory::instance()->enki();

        if (!$acquirer) {
            return false;
        }

        $balance = $acquirer->getBalance();

        return (bool) ($balance['success'] ?? false);
    }

    /**
     * Check Stripe API health
     */
    private function checkStripeHealth(AcquirerConfig $config): bool
    {
        if (!$config->hasValidCredentials()) {
            return false;
        }

        try {
            $credentials = $config->credentials;
            $secretKey = $credentials['secret_key'] ?? null;
            
            if (!$secretKey) {
                return false;
            }

            \Stripe\Stripe::setApiKey($secretKey);
            \Stripe\Balance::retrieve();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check Pagar.me API health
     */
    private function checkPagarmeHealth(AcquirerConfig $config): bool
    {
        return $config->hasValidCredentials();
    }

    /**
     * @return array<int, string>
     */
    private function resolveCandidatesForMethod(string $method): array
    {
        $lookupMethods = [$method];

        $query = AcquirerConfig::where('enabled', true)
            ->where(function ($builder) use ($lookupMethods) {
                foreach ($lookupMethods as $index => $supportedMethod) {
                    if ($index === 0) {
                        $builder->where(function ($nested) use ($supportedMethod) {
                            $nested->whereJsonContains('supported_methods', strtolower($supportedMethod))
                                ->orWhereJsonContains('supported_methods', strtoupper($supportedMethod));
                        });
                        continue;
                    }

                    $builder->orWhere(function ($nested) use ($supportedMethod) {
                        $nested->whereJsonContains('supported_methods', strtolower($supportedMethod))
                            ->orWhereJsonContains('supported_methods', strtoupper($supportedMethod));
                    });
                }
            })
            ->orderBy('priority', 'asc');

        $configs = $query->pluck('acquirer_key')->toArray();

        // Backward compatibility with older records that only had PIX support.
        if (empty($configs) && $method === 'pix_out') {
            $configs = AcquirerConfig::where('enabled', true)
                ->where(function ($builder) {
                    $builder->whereJsonContains('supported_methods', 'pix')
                        ->orWhereJsonContains('supported_methods', 'PIX');
                })
                ->orderBy('priority', 'asc')
                ->pluck('acquirer_key')
                ->toArray();
        }

        if (empty($configs)) {
            $configs = AcquirerConfig::where('enabled', true)
                ->orderBy('priority', 'asc')
                ->pluck('acquirer_key')
                ->toArray();
        }

        $normalized = array_map(static fn ($key): string => strtolower((string) $key), $configs);

        return array_values(array_unique(array_filter($normalized, static fn ($key): bool => $key !== '')));
    }

}
