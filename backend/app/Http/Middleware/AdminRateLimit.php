<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class AdminRateLimit
{
    private const WINDOW_SECONDS = 60;

    public function handle(Request $request, Closure $next, ?int $limit = null): Response
    {
        $user = $request->user();

        if (!$user || !isset($user->id)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'Acesso negado.',
                ],
            ], 401);
        }

        $requestLimit = $limit ?? $this->resolveLimit($request);
        $bucket = now()->timestamp - (now()->timestamp % self::WINDOW_SECONDS);
        $cacheKey = sprintf('admin_rate_limit:%s:%s:%d', $user->id, $this->normalizePath($request), $bucket);

        $currentCount = (int) Cache::get($cacheKey, 0);

        if ($currentCount >= $requestLimit) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'admin_rate_limit_exceeded',
                    'message' => 'Limite de operacoes administrativas atingido. Tente novamente em instantes.',
                    'retry_after' => self::WINDOW_SECONDS,
                ],
            ], 429)->withHeaders([
                'X-RateLimit-Limit' => (string) $requestLimit,
                'X-RateLimit-Remaining' => '0',
                'Retry-After' => (string) self::WINDOW_SECONDS,
            ]);
        }

        Cache::put($cacheKey, $currentCount + 1, now()->addSeconds(self::WINDOW_SECONDS));

        $response = $next($request);

        return $response->withHeaders([
            'X-RateLimit-Limit' => (string) $requestLimit,
            'X-RateLimit-Remaining' => (string) max(0, $requestLimit - ($currentCount + 1)),
        ]);
    }

    private function resolveLimit(Request $request): int
    {
        $path = $this->normalizePath($request);

        if (str_contains($path, 'admin/withdrawals/process-all')) {
            return 5;
        }

        if (str_contains($path, 'admin/customers/') && str_contains($path, '/impersonate')) {
            return 10;
        }

        if (str_contains($path, 'admin/security/block-ip') || str_contains($path, 'admin/security/unblock-ip')) {
            return 20;
        }

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return 40;
        }

        return 120;
    }

    private function normalizePath(Request $request): string
    {
        return preg_replace('#^api/v1/#', '', trim($request->path(), '/'));
    }
}
