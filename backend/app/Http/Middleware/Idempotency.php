<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class Idempotency
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only apply to POST, PUT, PATCH, DELETE
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return $next($request);
        }

        $idempotencyKey = trim((string) ($request->header('X-Idempotency-Key') ?? $request->header('Idempotency-Key') ?? ''));

        if (!$idempotencyKey) {
            return $next($request);
        }

        // Validate key length/format to prevent cache key injection
        if (strlen($idempotencyKey) > 128 || preg_match('/[\x00-\x1F\x7F]/', $idempotencyKey)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'invalid_idempotency_key', 'message' => 'Idempotency-Key inválida.'],
            ], 400);
        }

        $accountId = $request->user()?->account_id ?? 'anon';
        $cacheKey  = 'idempotency:' . hash('sha256', "{$accountId}:{$idempotencyKey}");
        $lockKey   = 'idempotency_lock:' . hash('sha256', "{$accountId}:{$idempotencyKey}");
        $queryParams = $request->query();
        if (is_array($queryParams)) {
            ksort($queryParams);
        }

        $requestHash = hash(
            'sha256',
            $request->method() . '|' . $request->path() . '|' . json_encode($queryParams) . '|' . $request->getContent()
        );

        // Return cached response if request was already processed
        if (Cache::has($cacheKey)) {
            $cachedResponse = Cache::get($cacheKey);

            if (($cachedResponse['request_hash'] ?? null) !== $requestHash) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'idempotency_key_reused',
                        'message' => 'A Idempotency-Key já foi usada com payload diferente.',
                    ],
                ], 409);
            }

            return response()->json($cachedResponse['data'], $cachedResponse['status'])
                ->header('X-Idempotency-Key', $idempotencyKey)
                ->header('X-Idempotency-Replay', 'true');
        }

        // Atomic lock prevents concurrent duplicate requests from both executing
        $lock = Cache::lock($lockKey, 30);
        if (!$lock->get()) {
            // Another request with the same key is in-flight; wait briefly and return cached result
            usleep(500_000);
            if (Cache::has($cacheKey)) {
                $cachedResponse = Cache::get($cacheKey);

                if (($cachedResponse['request_hash'] ?? null) !== $requestHash) {
                    return response()->json([
                        'success' => false,
                        'error' => [
                            'code' => 'idempotency_key_reused',
                            'message' => 'A Idempotency-Key já foi usada com payload diferente.',
                        ],
                    ], 409);
                }

                return response()->json($cachedResponse['data'], $cachedResponse['status'])
                    ->header('X-Idempotency-Key', $idempotencyKey)
                    ->header('X-Idempotency-Replay', 'true');
            }
            return response()->json([
                'success' => false,
                'error' => ['code' => 'conflict', 'message' => 'Requisição com esta Idempotency-Key já está sendo processada.'],
            ], 409);
        }

        try {
            // Double-check inside the lock
            if (Cache::has($cacheKey)) {
                $cachedResponse = Cache::get($cacheKey);

                if (($cachedResponse['request_hash'] ?? null) !== $requestHash) {
                    return response()->json([
                        'success' => false,
                        'error' => [
                            'code' => 'idempotency_key_reused',
                            'message' => 'A Idempotency-Key já foi usada com payload diferente.',
                        ],
                    ], 409);
                }

                return response()->json($cachedResponse['data'], $cachedResponse['status'])
                    ->header('X-Idempotency-Key', $idempotencyKey)
                    ->header('X-Idempotency-Replay', 'true');
            }

            $response = $next($request);

            // Cache successful responses (2xx)
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                Cache::put($cacheKey, [
                    'data'   => json_decode($response->getContent(), true),
                    'status' => $response->getStatusCode(),
                    'request_hash' => $requestHash,
                ], now()->addHours(24));
            }

            return $response->header('X-Idempotency-Key', $idempotencyKey);
        } finally {
            $lock->release();
        }
    }
}
