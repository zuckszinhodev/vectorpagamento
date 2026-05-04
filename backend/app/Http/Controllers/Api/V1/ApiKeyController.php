<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class ApiKeyController extends Controller
{
    /**
     * List API keys for authenticated account
     */
    public function index(Request $request): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $apiKeys = ApiKey::where('account_id', $accountId)
            ->orderBy('created_at', 'desc')
            ->get();

        // Mask the key values
        $data = $apiKeys->map(function ($key) {
            return [
                'id' => $key->id,
                'name' => $key->name,
                'type' => $key->type ?? 'public',
                'key_prefix' => $key->key_prefix ?? substr($key->key ?? '', 0, 12) . '...',
                'permissions' => $key->permissions,
                'environment' => $key->environment ?? 'production',
                'last_used_at' => $key->last_used_at,
                'expires_at' => $key->expires_at,
                'created_at' => $key->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ApiKeyController::index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Default permissions for each key type
     */
    private const PUBLIC_KEY_PERMISSIONS = [
        'charges:read',
        'payments:read',
        'products:read',
        'checkouts:read',
        'customers:read',
    ];

    private const SECRET_KEY_DEFAULT_PERMISSIONS = ['*'];

    /**
     * Create a new API key.
     *
     * Type determines the prefix and default permissions:
     *   - 'public'  → pk_live_ / pk_test_  (read-only by default)
     *   - 'secret'  → sk_live_ / sk_test_  (full access by default)
     *
     * Use secret keys (sk_live_) for server-side operations like creating
     * charges, managing checkouts, processing payments, etc.
     * Use public keys (pk_live_) only for client-side / read-only operations.
     */
    public function store(Request $request): JsonResponse
    {
        try {
        $user = $request->user();

        // Require MFA to be enabled
        if (empty($user->mfa_secret)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'mfa_required',
                    'message' => 'Você precisa ativar a autenticação de dois fatores antes de criar chaves API.',
                ],
            ], 403);
        }

        // Verify MFA code
        $request->validate([
            'mfa_code' => ['required', 'string', 'size:6'],
        ]);

        $google2fa = new Google2FA();
        if (!$google2fa->verifyKey($user->mfa_secret, $request->input('mfa_code'), 1)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'invalid_mfa_code',
                    'message' => 'Código de autenticação inválido.',
                ],
            ], 422);
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['sometimes', 'in:public,secret'],
            'permissions' => ['nullable', 'array'],
            'environment' => ['nullable', 'in:production,sandbox'],
            'expires_at' => ['nullable', 'date'],
            'ip_whitelist' => ['nullable', 'array'],
        ]);

        $accountId = $user->account_id;
        $keyType = $request->input('type', 'secret');
        $environment = $request->input('environment', 'production');

        // Determine prefix based on type + environment
        if ($keyType === 'secret') {
            $prefix = $environment === 'production' ? 'sk_live_' : 'sk_test_';
            $defaultPermissions = self::SECRET_KEY_DEFAULT_PERMISSIONS;
        } else {
            $prefix = $environment === 'production' ? 'pk_live_' : 'pk_test_';
            $defaultPermissions = self::PUBLIC_KEY_PERMISSIONS;
        }

        $plainKey = $prefix . Str::random(32);
        $keyHash = hash('sha256', $plainKey);
        $secretHash = hash('sha256', Str::random(32));

        // Public keys can only have read permissions
        $requestedPermissions = $request->input('permissions');
        if ($keyType === 'public' && $requestedPermissions) {
            $requestedPermissions = array_filter($requestedPermissions, function ($p) {
                return str_ends_with($p, ':read') || $p === '*';
            });
            // Public keys cannot have wildcard
            $requestedPermissions = array_filter($requestedPermissions, fn($p) => $p !== '*');
            $requestedPermissions = array_values($requestedPermissions);
        }

        // Secret keys with empty or null permissions default to full access
        if ($keyType === 'secret' && (empty($requestedPermissions) || $requestedPermissions === null)) {
            $requestedPermissions = self::SECRET_KEY_DEFAULT_PERMISSIONS;
        }

        $apiKey = ApiKey::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $accountId,
            'name' => $request->input('name'),
            'type' => $keyType,
            'key_hash' => $keyHash,
            'secret_hash' => $secretHash,
            'key_prefix' => substr($plainKey, 0, 12),
            'permissions' => $requestedPermissions ?? $defaultPermissions,
            'environment' => $environment,
            'expires_at' => $request->input('expires_at'),
            'ip_whitelist' => $request->input('ip_whitelist', []),
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $apiKey->id,
                'name' => $apiKey->name,
                'type' => $apiKey->type,
                'key' => $plainKey, // Only returned on creation
                'key_prefix' => substr($plainKey, 0, 12),
                'permissions' => $apiKey->permissions,
                'environment' => $apiKey->environment,
                'expires_at' => $apiKey->expires_at,
                'created_at' => $apiKey->created_at,
            ],
            'message' => $keyType === 'secret'
                ? 'Chave secreta criada. Use esta chave no header X-API-Key para operações via API (criar cobranças, gerenciar checkouts, etc).'
                : 'Chave pública criada. Esta chave tem acesso somente leitura.',
            'warning' => 'Salve esta chave de API em um local seguro. Ela não será exibida novamente.',
        ], 201);        } catch (ValidationException $e) {
            throw $e;        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ApiKeyController::store error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Update an API key
     */
    public function update(Request $request, string $apiKeyId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $apiKey = ApiKey::where('id', $apiKeyId)
            ->where('account_id', $accountId)
            ->first();

        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'api_key_not_found',
                    'message' => 'Chave de API não encontrada',
                ],
            ], 404);
        }

        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'expires_at' => ['nullable', 'date'],
            'ip_whitelist' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);

        $updateData = $request->only(['name', 'permissions', 'expires_at', 'ip_whitelist', 'is_active']);
        $apiKey->update(array_filter($updateData, fn($v) => $v !== null));

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $apiKey->id,
                'name' => $apiKey->name,
                'prefix' => ($apiKey->key_prefix ?? '') . '...',
                'permissions' => $apiKey->permissions,
                'environment' => $apiKey->environment,
                'is_active' => $apiKey->is_active,
                'expires_at' => $apiKey->expires_at,
                'updated_at' => $apiKey->updated_at,
            ],
        ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ApiKeyController::update error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Revoke (delete) an API key
     */
    public function destroy(Request $request, string $apiKeyId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $apiKey = ApiKey::where('id', $apiKeyId)
            ->where('account_id', $accountId)
            ->first();

        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'api_key_not_found',
                    'message' => 'Chave de API não encontrada',
                ],
            ], 404);
        }

        $apiKey->delete();

        return response()->json([
            'success' => true,
            'data' => ['message' => 'Chave de API revogada com sucesso'],
        ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ApiKeyController::destroy error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Rotate an API key (generate new key)
     */
    public function rotate(Request $request, string $apiKeyId): JsonResponse
    {
        try {
        $user = $request->user();

        // Require MFA to be enabled
        if (empty($user->mfa_secret)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'mfa_required',
                    'message' => 'Você precisa ativar a autenticação de dois fatores para rotacionar chaves API.',
                ],
            ], 403);
        }

        // Verify MFA code
        $request->validate([
            'mfa_code' => ['required', 'string', 'size:6'],
        ]);

        $google2fa = new Google2FA();
        if (!$google2fa->verifyKey($user->mfa_secret, $request->input('mfa_code'), 1)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'invalid_mfa_code',
                    'message' => 'Código de autenticação inválido.',
                ],
            ], 422);
        }

        $accountId = $user->account_id;

        $apiKey = ApiKey::where('id', $apiKeyId)
            ->where('account_id', $accountId)
            ->first();

        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'api_key_not_found',
                    'message' => 'Chave de API não encontrada',
                ],
            ], 404);
        }

        // Generate new key preserving its type
        if ($apiKey->type === 'secret') {
            $prefix = $apiKey->environment === 'production' ? 'sk_live_' : 'sk_test_';
        } else {
            $prefix = $apiKey->environment === 'production' ? 'pk_live_' : 'pk_test_';
        }
        $plainKey = $prefix . Str::random(32);
        $hashedKey = hash('sha256', $plainKey);

        $apiKey->update([
            'key_hash' => $hashedKey,
            'key_prefix' => substr($plainKey, 0, 12),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $apiKey->id,
                'name' => $apiKey->name,
                'key' => $plainKey, // Only returned on rotation
                'key_prefix' => substr($plainKey, 0, 12),
                'permissions' => $apiKey->permissions,
                'environment' => $apiKey->environment,
                'updated_at' => $apiKey->fresh()->updated_at,
            ],
            'warning' => 'Salve esta nova chave de API em um local seguro. Ela não será exibida novamente.',
        ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ApiKeyController::rotate error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }
}
