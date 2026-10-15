<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Account;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthApiKey
{
    /**
     * Handle an incoming request.
     *
     * Accepts API key via X-API-Key header or Authorization: Bearer.
     * Never via query string (would expose key in server logs).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKeyHeader = $request->header('X-API-Key')
            ?? $request->bearerToken();

        if (!$apiKeyHeader) {
            return response()->json([
                'success' => false,
                'message' => 'API key is required. Use X-API-Key header or Authorization: Bearer {key}.'
            ], 401);
        }

        // Hash the key to compare
        $hashedKey = hash('sha256', $apiKeyHeader);

        $key = ApiKey::where('key_hash', $hashedKey)
            ->where('is_active', true)
            ->first();

        if (!$key) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid API key'
            ], 401);
        }

        if ($key->expires_at && now()->gt($key->expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'API key has expired'
            ], 401);
        }

        // Update last used
        $key->update(['last_used_at' => now()]);

        // Get account
        $account = Account::find($key->account_id);

        // Create a mock user object for compatibility
        $mockUser = new \stdClass();
        $mockUser->account_id = $key->account_id;
        $mockUser->type = 'api_key';
        $mockUser->key_type = $key->type ?? 'public';
        $mockUser->api_key = $key;
        $mockUser->permissions = $key->permissions ?? ['*'];

        // Attach to request
        $request->merge(['account_id' => $key->account_id]);
        $request->setUserResolver(function () use ($mockUser) {
            return $mockUser;
        });

        return $next($request);
    }
}
