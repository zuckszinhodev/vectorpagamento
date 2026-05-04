<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Automatic audit logging for state-changing requests.
 * Logs to audit_logs table.
 */
class AuditLog
{
    /**
     * Methods that mutate data.
     */
    private const AUDITABLE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Routes to skip (e.g., auth, health).
     */
    private array $skipPatterns = [
        '#^api/v1/auth/(login|register|refresh|logout)#',
        '#^api/v1/auth/mfa/verify#',
        '#^health#',
        '#^api/v1/webhook#',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (!in_array($request->method(), self::AUDITABLE_METHODS)) {
            return $next($request);
        }

        $enteredAt = now();
        $startTime = microtime(true);

        // Skip certain routes
        $path = trim($request->path(), '/');
        foreach ($this->skipPatterns as $pattern) {
            if (preg_match($pattern, $path)) {
                return $next($request);
            }
        }

        $response = $next($request);

        // Only log successful mutations (2xx, 3xx)
        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        try {
            $this->logAction($request, $response, $enteredAt, $startTime);
        } catch (\Throwable $e) {
            Log::error('AuditLog middleware failed', ['error' => $e->getMessage()]);
        }

        return $response;
    }

    private function logAction(Request $request, Response $response, $enteredAt, float $startTime): void
    {
        $user = $request->user();
        $userId = null;
        $accountId = null;
        $role = null;

        if ($user) {
            $userId = $user->id ?? null;
            $accountId = $user->account_id ?? null;
            $role = method_exists($user, 'getRoleName') ? $user->getRoleName() : ($user->type ?? null);
        }

        $path = trim($request->path(), '/');
        $action = $this->resolveAction($request);
        [$entityType, $entityId] = $this->resolveEntity($path);

        // Sanitize input (strip sensitive fields)
        $input = $request->except([
            'password', 'password_confirmation', 'mfa_secret',
            'secret', 'token', 'api_key', 'credit_card',
        ]);

        // Parse response body for new values
        $responseData = [];
        $content = $response->getContent();
        if ($content) {
            $decoded = json_decode($content, true);
            if (isset($decoded['data']) && is_array($decoded['data'])) {
                $responseData = $decoded['data'];
            }
        }

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        $exitedAt = now();

        DB::table('audit_logs')->insert([
            'id' => Str::uuid()->toString(),
            'user_id' => $userId,
            'account_id' => $accountId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => null, // Would need model events for true old/new diff
            'new_values' => !empty($input) ? json_encode($input) : null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => json_encode([
                'method' => $request->method(),
                'path' => $path,
                'status_code' => $response->getStatusCode(),
                'role' => $role,
                'correlation_id' => $request->header('X-Correlation-Id'),
                'entered_at' => $enteredAt->toIso8601String(),
                'exited_at' => $exitedAt->toIso8601String(),
                'duration_ms' => $durationMs,
            ]),
            'created_at' => now(),
        ]);
    }

    private function resolveAction(Request $request): string
    {
        return match ($request->method()) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => strtolower($request->method()),
        };
    }

    /**
     * Extract entity type and ID from path.
     * e.g., api/v1/products/abc-123 → ['product', 'abc-123']
     */
    private function resolveEntity(string $path): array
    {
        // Remove api/v1 prefix
        $path = preg_replace('#^api/v1/#', '', $path);
        $segments = explode('/', $path);

        $entityType = null;
        $entityId = null;

        // Walk segments: look for resource/uuid patterns
        for ($i = 0; $i < count($segments); $i++) {
            $seg = $segments[$i];
            // UUID pattern
            if (preg_match('#^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#i', $seg)) {
                $entityId = $seg;
            } else {
                // Singularize resource name
                $entityType = rtrim($seg, 's');
            }
        }

        return [$entityType, $entityId];
    }
}
