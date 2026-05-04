<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limiting by IP / API Key with per-route tracking.
 * Uses the rate_limit_logs table for persistent tracking.
 */
class RateLimitApi
{
    /**
     * Default limits per window (1 minute).
     */
    private const DEFAULT_LIMIT = 60;
    private const WINDOW_SECONDS = 60;

    /**
     * Route-specific limits (override default).
     */
    private array $routeLimits = [
        'auth/register' => 5,
        'auth/login' => 10,
        'auth/forgot-password' => 3,
        'checkout/*/process' => 30,
        'external/charges' => 100,
        'external/payments' => 100,
    ];

    public function handle(Request $request, Closure $next, ?int $maxRequests = null): Response
    {
        $identifier = $this->resolveIdentifier($request);
        $identifierType = $this->resolveIdentifierType($request);
        $endpoint = $this->normalizeEndpoint($request);
        $limit = $maxRequests ?? $this->resolveLimit($endpoint);

        $windowStart = now()->subSeconds(self::WINDOW_SECONDS);

        // Count requests in current window
        $record = DB::table('rate_limit_logs')
            ->where('identifier', $identifier)
            ->where('endpoint', $endpoint)
            ->where('window_start', '>=', $windowStart)
            ->first();

        $currentCount = $record ? $record->requests_count : 0;

        if ($currentCount >= $limit) {
            // Log the block
            if ($record) {
                DB::table('rate_limit_logs')
                    ->where('id', $record->id)
                    ->update(['blocked' => true, 'updated_at' => now()]);
            }

            Log::warning('Rate limit exceeded', [
                'identifier' => $identifier,
                'identifier_type' => $identifierType,
                'endpoint' => $endpoint,
                'count' => $currentCount,
                'limit' => $limit,
            ]);

            $retryAfter = self::WINDOW_SECONDS;

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'rate_limit_exceeded',
                    'message' => 'Limite de requisições excedido. Tente novamente em alguns segundos.',
                    'retry_after' => $retryAfter,
                ],
            ], 429)->withHeaders([
                'X-RateLimit-Limit' => $limit,
                'X-RateLimit-Remaining' => 0,
                'Retry-After' => $retryAfter,
            ]);
        }

        // Upsert request count
        if ($record) {
            DB::table('rate_limit_logs')
                ->where('id', $record->id)
                ->update([
                    'requests_count' => $currentCount + 1,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('rate_limit_logs')->insert([
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'identifier' => $identifier,
                'identifier_type' => $identifierType,
                'endpoint' => $endpoint,
                'requests_count' => 1,
                'window_start' => now(),
                'blocked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $next($request);

        // Add rate limit headers
        $remaining = max(0, $limit - ($currentCount + 1));

        return $response->withHeaders([
            'X-RateLimit-Limit' => $limit,
            'X-RateLimit-Remaining' => $remaining,
        ]);
    }

    private function resolveIdentifier(Request $request): string
    {
        // Prefer API key, then user ID, then IP
        $apiKey = $request->header('X-API-Key');
        if ($apiKey) {
            return 'apikey:' . substr(hash('sha256', $apiKey), 0, 16);
        }

        try {
            $user = $request->user();
        } catch (\Throwable $e) {
            $user = null;
        }
        if ($user && isset($user->id)) {
            return 'user:' . $user->id;
        }

        return 'ip:' . ($request->header('X-Forwarded-For')
            ? explode(',', $request->header('X-Forwarded-For'))[0]
            : $request->ip());
    }

    private function resolveIdentifierType(Request $request): string
    {
        if ($request->header('X-API-Key')) {
            return 'api_key';
        }
        try {
            if ($request->user()) {
                return 'user';
            }
        } catch (\Throwable $e) {
            // JWT guard may throw
        }
        return 'ip';
    }

    private function normalizeEndpoint(Request $request): string
    {
        $path = trim($request->path(), '/');
        // Remove api/v1 prefix
        $path = preg_replace('#^api/v1/#', '', $path);
        // Replace UUIDs with *
        $path = preg_replace('#[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}#i', '*', $path);
        return $request->method() . ':' . $path;
    }

    private function resolveLimit(string $endpoint): int
    {
        foreach ($this->routeLimits as $pattern => $limit) {
            $regex = str_replace(['/', '*'], ['\/', '.*'], $pattern);
            if (preg_match("#$regex#i", $endpoint)) {
                return $limit;
            }
        }
        return self::DEFAULT_LIMIT;
    }
}
