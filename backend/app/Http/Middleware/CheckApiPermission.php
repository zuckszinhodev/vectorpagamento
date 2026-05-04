<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that checks API key permissions for the current request.
 *
 * Usage in routes:
 *   ->middleware('api.permission:charges:write')
 *   ->middleware('api.permission:checkouts:write')
 *
 * JWT-authenticated users (dashboard) bypass permission checks.
 * API key users must have the required permission in their key's permissions array.
 *
 * Public keys (pk_live_/pk_test_) are restricted to read-only permissions.
 * Secret keys (sk_live_/sk_test_) can have any permissions (default: ['*']).
 */
class CheckApiPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        // JWT users (dashboard) have full access — no restrictions
        if ($user && !isset($user->type)) {
            return $next($request);
        }

        // For API key users, check if the key was marked as an API key auth
        if ($user && ($user->type ?? null) === 'api_key') {
            $apiKey = $user->api_key ?? null;

            if (!$apiKey) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'insufficient_permissions',
                        'message' => 'Permissões insuficientes. Esta chave API não tem acesso a este recurso.',
                    ],
                ], 403);
            }

            $permissions = $user->permissions ?? [];

            // Wildcard grants all permissions (secret keys default)
            if (in_array('*', $permissions)) {
                return $next($request);
            }

            // Check for category wildcard (e.g., 'charges:*' grants 'charges:write')
            $category = explode(':', $permission)[0] ?? '';
            if ($category && in_array($category . ':*', $permissions)) {
                return $next($request);
            }

            // Check specific permission
            if (!in_array($permission, $permissions)) {
                $isWriteOp = str_ends_with($permission, ':write');
                $keyType = $apiKey->type ?? 'public';

                $message = $isWriteOp && $keyType === 'public'
                    ? "Permissões insuficientes. Chaves públicas (pk_live_/pk_test_) não podem realizar operações de escrita. Use uma chave secreta (sk_live_/sk_test_) gerada em Configurações > API Keys."
                    : "Permissões insuficientes. Esta chave API não tem a permissão '{$permission}'.";

                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'insufficient_permissions',
                        'message' => $message,
                        'required_permission' => $permission,
                        'key_type' => $keyType,
                        'hint' => $isWriteOp
                            ? 'Para operações de escrita (criar cobranças, checkouts, etc), gere uma chave secreta (sk_live_) em https://app.wazerpay.com.br/settings na aba API Keys.'
                            : null,
                    ],
                ], 403);
            }
        }

        return $next($request);
    }
}
