<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PlatformSettingsAdminController extends Controller
{
    /**
     * Get all platform settings organized by category
     */
    public function index(): JsonResponse
    {
        try {
            // Fetch all system settings
            $settings = SystemSetting::all()->pluck('value', 'key')->toArray();

            // Organize settings into categories
            $organized = [
                'general' => [
                    'platform_name' => $settings['platform_name'] ?? 'GatePro',
                    'support_email' => $settings['support_email'] ?? '',
                    'support_phone' => $settings['support_phone'] ?? '',
                    'default_currency' => $settings['default_currency'] ?? 'BRL',
                    'timezone' => $settings['timezone'] ?? 'America/Sao_Paulo',
                    'maintenance_mode' => filter_var($settings['maintenance_mode'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ],
                'branding' => [
                    'logo_url' => $settings['logo_url'] ?? '',
                    'favicon_url' => $settings['favicon_url'] ?? '',
                    'primary_color' => $settings['primary_color'] ?? '#7C3AED',
                    'secondary_color' => $settings['secondary_color'] ?? '#4F46E5',
                    'custom_css' => $settings['custom_css'] ?? '',
                ],
                'email' => [
                    'smtp_host' => $settings['smtp_host'] ?? '',
                    'smtp_port' => (int) ($settings['smtp_port'] ?? 587),
                    'smtp_username' => $settings['smtp_username'] ?? '',
                    'smtp_password' => $settings['smtp_password'] ?? '',
                    'smtp_encryption' => $settings['smtp_encryption'] ?? 'tls',
                    'from_name' => $settings['mail_from_name'] ?? 'GatePro',
                    'from_email' => $settings['mail_from_address'] ?? '',
                ],
                'payments' => [
                    'pix_enabled' => filter_var($settings['pix_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'credit_card_enabled' => filter_var($settings['credit_card_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'boleto_enabled' => filter_var($settings['boleto_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'max_installments' => (int) ($settings['max_installments'] ?? 12),
                    'min_installment_value' => (float) ($settings['min_installment_value'] ?? 10),
                    'default_gateway_fee' => (float) ($settings['default_gateway_fee'] ?? 4.99),
                    'pix_expiration_minutes' => (int) ($settings['pix_expiration_minutes'] ?? 30),
                ],
                'security' => [
                    'require_mfa' => filter_var($settings['require_mfa'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'session_timeout_minutes' => (int) ($settings['session_timeout_minutes'] ?? 60),
                    'max_login_attempts' => (int) ($settings['max_login_attempts'] ?? 5),
                    'password_min_length' => (int) ($settings['password_min_length'] ?? 8),
                    'require_kyc' => filter_var($settings['require_kyc'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'ip_whitelist_enabled' => filter_var($settings['ip_whitelist_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'ip_whitelist' => $settings['ip_whitelist'] ?? '',
                ],
                'features' => [
                    'affiliates_enabled' => filter_var($settings['affiliates_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'marketplace_enabled' => filter_var($settings['marketplace_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'member_area_enabled' => filter_var($settings['member_area_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'custom_domains_enabled' => filter_var($settings['custom_domains_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'webhooks_enabled' => filter_var($settings['webhooks_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'api_access_enabled' => filter_var($settings['api_access_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $organized,
            ]);
        } catch (\Throwable $e) {
            Log::error('PlatformSettingsAdminController::index error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar configurações',
            ], 500);
        }
    }

    /**
     * Update platform settings
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            // Flatten nested data and update each setting
            foreach ($data as $category => $values) {
                if (is_array($values)) {
                    foreach ($values as $key => $value) {
                        $this->setSetting($key, $value);
                    }
                } else {
                    // Direct key-value pair
                    $this->setSetting($category, $values);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Configurações atualizadas com sucesso',
            ]);
        } catch (\Throwable $e) {
            Log::error('PlatformSettingsAdminController::update error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar configurações',
            ], 500);
        }
    }

    /**
     * Get all admin API keys
     */
    public function apiKeys(): JsonResponse
    {
        try {
            // Get platform-level API keys (account_id = null or admin keys)
            $keys = ApiKey::whereNull('account_id')
                ->orWhere('type', 'admin')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($key) {
                    return [
                        'id' => $key->id,
                        'name' => $key->name,
                        'key' => $key->key_preview ?? substr($key->key, 0, 12) . '...',
                        'type' => $key->type ?? 'live',
                        'permissions' => $key->permissions ?? ['all'],
                        'last_used_at' => $key->last_used_at,
                        'created_at' => $key->created_at,
                        'expires_at' => $key->expires_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $keys,
            ]);
        } catch (\Throwable $e) {
            Log::error('PlatformSettingsAdminController::apiKeys error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar API keys',
            ], 500);
        }
    }

    /**
     * Create a new admin API key
     */
    public function createApiKey(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'type' => ['sometimes', 'string', 'in:live,test'],
                'permissions' => ['sometimes', 'array'],
                'expires_at' => ['sometimes', 'nullable', 'date'],
            ]);

            $prefix = ($validated['type'] ?? 'live') === 'live' ? 'pk_live_' : 'pk_test_';
            $rawKey = $prefix . Str::random(48);

            $apiKey = ApiKey::create([
                'id' => Str::uuid()->toString(),
                'account_id' => null, // Platform-level key
                'name' => $validated['name'],
                'key' => hash('sha256', $rawKey), // Store hashed
                'key_preview' => substr($rawKey, 0, 12) . '...',
                'type' => $validated['type'] ?? 'live',
                'permissions' => $validated['permissions'] ?? ['all'],
                'expires_at' => $validated['expires_at'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $apiKey->id,
                    'name' => $apiKey->name,
                    'key' => $rawKey, // Return raw key only once
                    'type' => $apiKey->type,
                    'created_at' => $apiKey->created_at,
                ],
                'message' => 'API key criada com sucesso. Salve a chave, ela não será exibida novamente.',
            ]);
        } catch (\Throwable $e) {
            Log::error('PlatformSettingsAdminController::createApiKey error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar API key',
            ], 500);
        }
    }

    /**
     * Delete an admin API key
     */
    public function deleteApiKey(string $keyId): JsonResponse
    {
        try {
            $apiKey = ApiKey::find($keyId);

            if (!$apiKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'API key não encontrada',
                ], 404);
            }

            $apiKey->delete();

            return response()->json([
                'success' => true,
                'message' => 'API key removida com sucesso',
            ]);
        } catch (\Throwable $e) {
            Log::error('PlatformSettingsAdminController::deleteApiKey error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erro ao remover API key',
            ], 500);
        }
    }

    /**
     * Set a single system setting
     */
    private function setSetting(string $key, mixed $value): void
    {
        // Convert booleans to string
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        SystemSetting::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value]
        );
    }
}
