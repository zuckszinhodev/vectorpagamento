<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Middleware that accepts either JWT Bearer token or API Key authentication.
 * 
 * Tries JWT first (Authorization: Bearer {token}), then falls back to
 * API Key (X-API-Key header or api_key query param).
 */
class AuthJwtOrApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Try JWT authentication first
        if ($request->bearerToken()) {
            try {
                $user = JWTAuth::parseToken()->authenticate();
                if ($user) {
                    // Check if user account is blocked/suspended (DB status or cache flag)
                    if (in_array($user->status, ['blocked', 'suspended']) || cache()->get("user_blocked:{$user->id}")) {
                        // Re-read from DB to get fresh status and block_reason
                        $user->refresh();
                        try { JWTAuth::invalidate(JWTAuth::getToken()); } catch (\Exception $e) {}
                        return response()->json([
                            'success' => false,
                            'error' => [
                                'code' => 'account_blocked',
                                'message' => 'Sua conta foi bloqueada pela administração.',
                                'block_reason' => $user->block_reason,
                            ],
                        ], 403);
                    }
                    return $next($request);
                }
            } catch (\Exception $e) {
                // JWT failed, try API Key below
            }
        }

        // 2. Try API Key authentication (X-API-Key header ONLY — never accept via query params or Bearer fallback)
        $apiKeyHeader = $request->header('X-API-Key');

        if ($apiKeyHeader) {
            $hashedKey = hash('sha256', $apiKeyHeader);

            $key = ApiKey::where('key_hash', $hashedKey)
                ->whereRaw('"is_active" = true')
                ->first();

            if (!$key) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'invalid_api_key',
                        'message' => 'API key inválida',
                    ],
                ], 401);
            }

            if ($key->expires_at && now()->gt($key->expires_at)) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'api_key_expired',
                        'message' => 'API key expirada',
                    ],
                ], 401);
            }

            // Update last used
            $key->update(['last_used_at' => now()]);

            // Get account
            $account = Account::find($key->account_id);

            // Create a mock user object for compatibility
            $mockUser = new \stdClass();
            $mockUser->id = $key->account_id;
            $mockUser->account_id = $key->account_id;
            $mockUser->type = 'api_key';
            $mockUser->key_type = $key->type ?? 'public'; // 'public' or 'secret'
            $mockUser->api_key = $key;
            $permissions = $key->permissions ?? ['*'];
            $mockUser->permissions = !empty($permissions) ? $permissions : ['*'];
            $mockUser->name = $key->name ?? 'API Key';
            $mockUser->email = null;
            // Reflect real account KYC status instead of hardcoding 'approved'
            $mockUser->kyc_status = $account?->kyc_status ?? 'pending';
            $mockUser->status = $account?->status ?? 'active';
            $mockUser->block_reason = $account?->block_reason ?? null;
            $mockUser->role = $account?->role ?? 'user';

            // Attach to request
            $request->merge([
                'account_id' => $key->account_id,
                'api_key_id' => $key->id,
            ]);
            $request->setUserResolver(function () use ($mockUser) {
                return $mockUser;
            });

            return $next($request);
        }

        // 3. No valid auth provided
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'unauthenticated',
                'message' => 'Autenticação necessária. Use Authorization: Bearer {token/api_key} ou X-API-Key.',
            ],
        ], 401);
    }
}
