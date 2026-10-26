<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GlobalWebhook;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * System Configuration Management — extracted from AdminController
 *
 * Covers: branding/appearance, API settings, withdrawal config, global webhooks.
 */
class SystemConfigAdminController extends Controller
{
    /**
     * Get public branding/appearance settings (no auth required)
     */
    public function getPublicBranding(): JsonResponse
    {
        try {
            try {
                $appearance = SystemSetting::getByGroup('appearance');
            } catch (\Throwable) {
                $appearance = [];
            }

            $name = $this->normalizePlatformName($appearance['platform_name'] ?? config('app.name', 'Vector Pagamentos'));

            return response()->json([
                'success' => true,
                'data' => [
                    'platform_name' => $name,
                    'platform_description' => $appearance['platform_description'] ?? 'Gateway de pagamentos',
                    'primary_color' => $appearance['primary_color'] ?? '#9333ea',
                    'theme_variant' => $appearance['theme_variant'] ?? 'classic',
                    'logo_url' => $appearance['logo_url'] ?? '',
                    'support_email' => $appearance['support_email'] ?? '',
                    'seo_title' => $appearance['seo_title'] ?? $name,
                    'seo_description' => $appearance['seo_description'] ?? ($appearance['platform_description'] ?? ''),
                    'seo_keywords' => $appearance['seo_keywords'] ?? '',
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SystemConfigAdminController::getPublicBranding error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Get system configuration
     */
    public function getSystemConfig(): JsonResponse
    {
        try {
            $appearance = SystemSetting::getByGroup('appearance');
            $api = SystemSetting::getByGroup('api');

            return response()->json([
                'success' => true,
                'data' => [
                    'appearance' => [
                        'platform_name' => $this->normalizePlatformName($appearance['platform_name'] ?? config('app.name', 'Vector Pagamentos')),
                        'platform_description' => $appearance['platform_description'] ?? 'Gateway de pagamentos',
                        'primary_color' => $appearance['primary_color'] ?? '#9333ea',
                        'theme_variant' => $appearance['theme_variant'] ?? 'classic',
                        'logo_url' => $appearance['logo_url'] ?? '',
                        'support_email' => $appearance['support_email'] ?? '',
                        'seo_title' => $appearance['seo_title'] ?? '',
                        'seo_description' => $appearance['seo_description'] ?? '',
                        'seo_keywords' => $appearance['seo_keywords'] ?? '',
                    ],
                    'api' => [
                        'rate_limit_per_minute' => $api['rate_limit_per_minute'] ?? 1000,
                        'webhook_timeout' => $api['webhook_timeout'] ?? 30,
                        'cors_enabled' => $api['cors_enabled'] ?? true,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SystemConfigAdminController::getSystemConfig error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Update system configuration
     */
    public function updateSystemConfig(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'platform_name' => ['sometimes', 'string', 'max:100'],
                'platform_description' => ['sometimes', 'nullable', 'string', 'max:255'],
                'primary_color' => ['sometimes', 'string', 'max:7'],
                'theme_variant' => ['sometimes', 'string', 'in:classic,modern'],
                'logo_url' => ['sometimes', 'nullable', 'string'],
                'support_email' => ['sometimes', 'nullable', 'string', 'max:120'],
                'seo_title' => ['sometimes', 'nullable', 'string', 'max:120'],
                'seo_description' => ['sometimes', 'nullable', 'string', 'max:255'],
                'seo_keywords' => ['sometimes', 'nullable', 'string', 'max:255'],
                'rate_limit_per_minute' => ['sometimes', 'integer', 'min:10', 'max:10000'],
                'webhook_timeout' => ['sometimes', 'integer', 'min:5', 'max:120'],
                'cors_enabled' => ['sometimes', 'boolean'],
            ]);

            // key => group mapping so getByGroup() reads them back correctly.
            $groups = [
                'platform_name' => 'appearance', 'platform_description' => 'appearance',
                'primary_color' => 'appearance', 'theme_variant' => 'appearance', 'logo_url' => 'appearance',
                'support_email' => 'appearance', 'seo_title' => 'appearance',
                'seo_description' => 'appearance', 'seo_keywords' => 'appearance',
                'rate_limit_per_minute' => 'api', 'webhook_timeout' => 'api', 'cors_enabled' => 'api',
            ];

            foreach ($validated as $key => $value) {
                $type = is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : 'string');
                SystemSetting::updateOrCreate(
                    ['key' => $key],
                    [
                        'value' => is_bool($value) ? ($value ? '1' : '0') : (string) ($value ?? ''),
                        'group' => $groups[$key] ?? 'general',
                        'type' => $type,
                    ]
                );
                \Illuminate\Support\Facades\Cache::forget("system_setting_{$key}");
            }

            return response()->json([
                'success' => true,
                'data' => $validated,
                'message' => 'Configurações atualizadas com sucesso',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SystemConfigAdminController::updateSystemConfig error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Upload a logo image and set it as the platform logo (logo_url).
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'logo' => ['required', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp,svg,gif'],
            ]);

            $file = $request->file('logo');
            $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
            $name = 'logo-' . time() . '-' . Str::random(6) . '.' . $ext;
            $file->storeAs('branding', $name, 'public');

            $url = '/storage/branding/' . $name;

            SystemSetting::updateOrCreate(
                ['key' => 'logo_url'],
                ['value' => $url, 'group' => 'appearance', 'type' => 'string']
            );
            \Illuminate\Support\Facades\Cache::forget('system_setting_logo_url');

            return response()->json([
                'success' => true,
                'data' => ['logo_url' => $url],
                'message' => 'Logo enviada com sucesso',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Arquivo inválido. Envie PNG, JPG, WEBP ou SVG até 2MB.'], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SystemConfigAdminController::uploadLogo error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao enviar logo'], 500);
        }
    }

    // ── Withdrawal Config ────────────────────────────────────────

    public function getWithdrawalConfig(): JsonResponse
    {
        try {
            $settings = SystemSetting::getByGroup('withdrawals');

            return response()->json([
                'success' => true,
                'data' => [
                    // Taxas padrão
                    'fee_percentage' => (float) ($settings['fee_percentage'] ?? 0),
                    'fee_fixed' => (int) ($settings['fee_fixed'] ?? 0),
                    'standard_withdrawal_fee_pix' => (int) ($settings['standard_withdrawal_fee_pix'] ?? 0),
                    'standard_withdrawal_fee_bank' => (int) ($settings['standard_withdrawal_fee_bank'] ?? 200),
                    // Saque instantâneo
                    'instant_withdrawal_enabled' => $settings['instant_withdrawal_enabled'] ?? false,
                    'instant_withdrawal_fee_type' => $settings['instant_withdrawal_fee_type'] ?? 'percentage',
                    'instant_withdrawal_fee_value' => (float) ($settings['instant_withdrawal_fee_value'] ?? 2.5),
                    // Limites
                    'withdrawal_min_amount' => (int) ($settings['withdrawal_min_amount'] ?? 1000),
                    'withdrawal_max_amount' => (int) ($settings['withdrawal_max_amount'] ?? 0),
                    'auto_approve_limit' => (int) ($settings['auto_approve_limit'] ?? 0),
                    // Controles operacionais (fintech)
                    'withdrawals_enabled' => (bool) ($settings['withdrawals_enabled'] ?? true),
                    'withdrawal_processing_enabled' => (bool) ($settings['withdrawal_processing_enabled'] ?? true),
                    'payments_enabled' => (bool) SystemSetting::get('payments_enabled', true),
                    'daily_withdrawal_limit' => (int) ($settings['daily_withdrawal_limit'] ?? 5000000),
                    'large_withdrawal_threshold' => (int) ($settings['large_withdrawal_threshold'] ?? 1000000),
                    'withdrawal_cooldown_seconds' => (int) ($settings['withdrawal_cooldown_seconds'] ?? 60),
                    'max_pending_withdrawals_per_account' => (int) ($settings['max_pending_withdrawals_per_account'] ?? 10),
                    // Liquidação
                    'settlement_days_pix' => (int) ($settings['settlement_days_pix'] ?? 1),
                    'settlement_days_ted' => (int) ($settings['settlement_days_ted'] ?? 3),
                    'processing_days' => (int) ($settings['processing_days'] ?? 1),
                    // Saque automático
                    'auto_withdrawal_enabled' => (bool) ($settings['auto_withdrawal_enabled'] ?? false),
                    'auto_withdrawal_schedule' => $settings['auto_withdrawal_schedule'] ?? 'manual',
                    'auto_withdrawal_min_amount' => (int) ($settings['auto_withdrawal_min_amount'] ?? 5000),
                    // Holdback
                    'holdback_enabled' => (bool) ($settings['holdback_enabled'] ?? false),
                    'holdback_days' => (int) ($settings['holdback_days'] ?? 14),
                    'holdback_percentage' => (float) ($settings['holdback_percentage'] ?? 0),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SystemConfigAdminController::getWithdrawalConfig error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    public function updateWithdrawalConfig(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'fee_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
                'fee_fixed' => ['sometimes', 'integer', 'min:0'],
                'standard_withdrawal_fee_pix' => ['sometimes', 'integer', 'min:0'],
                'standard_withdrawal_fee_bank' => ['sometimes', 'integer', 'min:0'],
                'instant_withdrawal_enabled' => ['sometimes', 'boolean'],
                'instant_withdrawal_fee_type' => ['sometimes', 'in:percentage,fixed'],
                'instant_withdrawal_fee_value' => ['sometimes', 'numeric', 'min:0'],
                'withdrawal_min_amount' => ['sometimes', 'integer', 'min:0'],
                'withdrawal_max_amount' => ['sometimes', 'integer', 'min:0'],
                'auto_approve_limit' => ['sometimes', 'integer', 'min:0'],
                'withdrawals_enabled' => ['sometimes', 'boolean'],
                'withdrawal_processing_enabled' => ['sometimes', 'boolean'],
                'payments_enabled' => ['sometimes', 'boolean'],
                'daily_withdrawal_limit' => ['sometimes', 'integer', 'min:0'],
                'large_withdrawal_threshold' => ['sometimes', 'integer', 'min:0'],
                'withdrawal_cooldown_seconds' => ['sometimes', 'integer', 'min:0', 'max:3600'],
                'max_pending_withdrawals_per_account' => ['sometimes', 'integer', 'min:0', 'max:500'],
                'settlement_days_pix' => ['sometimes', 'integer', 'min:0', 'max:30'],
                'settlement_days_ted' => ['sometimes', 'integer', 'min:0', 'max:30'],
                'processing_days' => ['sometimes', 'integer', 'min:0', 'max:30'],
                'auto_withdrawal_enabled' => ['sometimes', 'boolean'],
                'auto_withdrawal_schedule' => ['sometimes', 'in:manual,daily,weekly,biweekly,monthly'],
                'auto_withdrawal_min_amount' => ['sometimes', 'integer', 'min:0'],
                'holdback_enabled' => ['sometimes', 'boolean'],
                'holdback_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
                'holdback_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            ]);

            foreach ($validated as $key => $value) {
                $existing = SystemSetting::where('key', $key)->first();
                if ($existing) {
                    SystemSetting::set($key, $value);
                } else {
                    $type = match ($key) {
                        'instant_withdrawal_enabled',
                        'auto_withdrawal_enabled',
                        'holdback_enabled',
                        'withdrawals_enabled',
                        'withdrawal_processing_enabled',
                        'payments_enabled' => 'boolean',
                        'fee_percentage', 'instant_withdrawal_fee_value', 'holdback_percentage' => 'string',
                        'instant_withdrawal_fee_type', 'auto_withdrawal_schedule' => 'string',
                        default => 'integer',
                    };
                    SystemSetting::create([
                        'key' => $key,
                        'value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                        'type' => $type,
                        'group' => 'withdrawals',
                        'description' => $key,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $validated,
                'message' => 'Configurações de saque atualizadas com sucesso',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SystemConfigAdminController::updateWithdrawalConfig error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    // ── Global Webhooks ──────────────────────────────────────────

    public function listGlobalWebhooks(): JsonResponse
    {
        try {
            $webhooks = GlobalWebhook::orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $webhooks,
                'available_events' => GlobalWebhook::getAvailableEvents(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SystemConfigAdminController::listGlobalWebhooks error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    public function createGlobalWebhook(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'url' => ['required', 'url', 'max:500'],
                'events' => ['required', 'array', 'min:1'],
                'events.*' => ['string'],
                'description' => ['nullable', 'string', 'max:255'],
                'active' => ['sometimes', 'boolean'],
            ]);

            $validated['secret'] = Str::random(32);
            $validated['active'] = $validated['active'] ?? true;

            $webhook = GlobalWebhook::create($validated);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $webhook->id,
                    'url' => $webhook->url,
                    'events' => $webhook->events,
                    'active' => $webhook->active,
                    'secret' => $validated['secret'],
                    'description' => $webhook->description,
                ],
                'message' => 'Webhook criado com sucesso',
            ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SystemConfigAdminController::createGlobalWebhook error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    public function updateGlobalWebhook(Request $request, string $webhookId): JsonResponse
    {
        try {
            $webhook = GlobalWebhook::find($webhookId);
            if (!$webhook) {
                return response()->json(['success' => false, 'message' => 'Webhook não encontrado'], 404);
            }

            $validated = $request->validate([
                'url' => ['sometimes', 'url', 'max:500'],
                'events' => ['sometimes', 'array', 'min:1'],
                'events.*' => ['string'],
                'description' => ['nullable', 'string', 'max:255'],
                'active' => ['sometimes', 'boolean'],
            ]);

            $webhook->update($validated);

            return response()->json([
                'success' => true,
                'data' => $webhook->fresh(),
                'message' => 'Webhook atualizado com sucesso',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SystemConfigAdminController::updateGlobalWebhook error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    public function deleteGlobalWebhook(string $webhookId): JsonResponse
    {
        try {
            $webhook = GlobalWebhook::find($webhookId);
            if (!$webhook) {
                return response()->json(['success' => false, 'message' => 'Webhook não encontrado'], 404);
            }

            $webhook->delete();
            return response()->json(['success' => true, 'message' => 'Webhook removido com sucesso']);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SystemConfigAdminController::deleteGlobalWebhook error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    public function regenerateWebhookSecret(string $webhookId): JsonResponse
    {
        try {
            $webhook = GlobalWebhook::find($webhookId);
            if (!$webhook) {
                return response()->json(['success' => false, 'message' => 'Webhook não encontrado'], 404);
            }

            $newSecret = Str::random(32);
            $webhook->update(['secret' => $newSecret]);

            return response()->json([
                'success' => true,
                'data' => ['secret' => $newSecret],
                'message' => 'Secret regenerado com sucesso',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SystemConfigAdminController::regenerateWebhookSecret error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    private function normalizePlatformName(mixed $name): string
    {
        $value = trim((string) $name);

        return $value === '' ? 'Vector Pagamentos' : $value;
    }
}
