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
 * SECURITY: Admin access audit logging middleware.
 * 
 * Logs all access to admin endpoints for security auditing and 
 * compliance purposes. Detects suspicious patterns and triggers alerts.
 */
class AdminAccessLog
{
    /**
     * Thresholds for suspicious activity detection.
     */
    private const ALERT_THRESHOLD_REQUESTS = 50;     // Requests per 5 min window
    private const ALERT_THRESHOLD_UNIQUE_IPS = 5;    // Different IPs in 5 min
    private const WINDOW_MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $user = $request->user();
        
        // Execute the request
        $response = $next($request);
        
        // Log the admin access
        $this->logAccess($request, $user, $response, $startTime);
        
        // Check for suspicious patterns
        $this->detectSuspiciousActivity($request, $user);
        
        return $response;
    }

    /**
     * Log admin access to database.
     */
    protected function logAccess(Request $request, $user, Response $response, float $startTime): void
    {
        try {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            DB::table('admin_access_logs')->insert([
                'id' => Str::uuid()->toString(),
                'user_id' => $user?->id,
                'account_id' => $user?->account_id,
                'actor_role' => method_exists($user, 'getRoleName') ? $user->getRoleName() : null,
                'endpoint' => $request->path(),
                'method' => $request->method(),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr($request->userAgent() ?? '', 0, 500),
                'request_body' => $this->sanitizeRequestBody($request),
                'correlation_id' => $this->resolveCorrelationId($request),
                'security_flags' => json_encode($this->resolveSecurityFlags($request, $response, $duration)),
                'response_status' => $response->getStatusCode(),
                'response_time_ms' => $duration,
                'accessed_at' => now(),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Don't break the request if logging fails, but log the error
            Log::error('Failed to log admin access', [
                'error' => $e->getMessage(),
                'path' => $request->path(),
            ]);
        }
    }

    /**
     * Sanitize request body - remove sensitive data before logging.
     */
    protected function sanitizeRequestBody(Request $request): ?string
    {
        $sensitiveFields = [
            'password', 'password_confirmation', 'current_password',
            'secret', 'api_key', 'token', 'access_token', 'refresh_token',
            'credit_card', 'card_number', 'cvv', 'cvc', 'expiry',
            'mfa_code', 'otp', 'pin',
        ];
        
        $data = $request->except($sensitiveFields);
        
        // Redact any remaining sensitive-looking fields
        array_walk_recursive($data, function (&$value, $key) use ($sensitiveFields) {
            if (is_string($key)) {
                foreach ($sensitiveFields as $sensitive) {
                    if (stripos($key, $sensitive) !== false) {
                        $value = '[REDACTED]';
                        return;
                    }
                }
            }
        });
        
        $json = json_encode($data);
        
        // Limit size to prevent large payloads
        if (strlen($json) > 2000) {
            return mb_substr($json, 0, 2000) . '...[TRUNCATED]';
        }
        
        return $json;
    }

    /**
     * Detect suspicious activity patterns and trigger alerts.
     */
    protected function detectSuspiciousActivity(Request $request, $user): void
    {
        if (!$user) {
            return;
        }
        
        $windowStart = now()->subMinutes(self::WINDOW_MINUTES);
        
        try {
            // Count requests from this user in the window
            $recentAccess = DB::table('admin_access_logs')
                ->where('user_id', $user->id)
                ->where('accessed_at', '>=', $windowStart)
                ->selectRaw('COUNT(*) as request_count, COUNT(DISTINCT ip_address) as unique_ips')
                ->first();
            
            $alerts = [];
            
            // Check for high request volume
            if ($recentAccess && $recentAccess->request_count > self::ALERT_THRESHOLD_REQUESTS) {
                $alerts[] = 'high_request_volume';
            }
            
            // Check for multiple IPs (potential session hijacking)
            if ($recentAccess && $recentAccess->unique_ips > self::ALERT_THRESHOLD_UNIQUE_IPS) {
                $alerts[] = 'multiple_source_ips';
            }
            
            // Log security alerts
            if (!empty($alerts)) {
                Log::warning('Suspicious admin activity detected', [
                    'user_id' => $user->id,
                    'alerts' => $alerts,
                    'request_count' => $recentAccess->request_count ?? 0,
                    'unique_ips' => $recentAccess->unique_ips ?? 0,
                    'current_ip' => $request->ip(),
                    'endpoint' => $request->path(),
                    'window_minutes' => self::WINDOW_MINUTES,
                ]);
                
                // TODO: Implement actual alerting (email, Slack, etc.)
                // $this->sendSecurityAlert($user, $alerts, $recentAccess);
            }
        } catch (\Exception $e) {
            Log::error('Failed to check suspicious activity', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveCorrelationId(Request $request): string
    {
        return (string) ($request->header('X-Correlation-Id') ?: Str::uuid()->toString());
    }

    private function resolveSecurityFlags(Request $request, Response $response, float $duration): array
    {
        $flags = [];

        if ($response->getStatusCode() >= 400) {
            $flags[] = 'http_error_response';
        }

        if ($duration > 1500) {
            $flags[] = 'slow_admin_request';
        }

        if ($request->method() !== 'GET') {
            $flags[] = 'state_mutation';
        }

        return $flags;
    }
}
