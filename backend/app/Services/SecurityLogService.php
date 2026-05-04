<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Security Logging Service
 * 
 * Centralized service for logging security events, detecting attacks,
 * and managing automatic IP blocking.
 */
class SecurityLogService
{
    /**
     * Event types
     */
    public const EVENT_FAILED_LOGIN = 'failed_login';
    public const EVENT_RATE_LIMITED = 'rate_limited';
    public const EVENT_IP_BLOCKED = 'ip_blocked';
    public const EVENT_IP_UNBLOCKED = 'ip_unblocked';
    public const EVENT_BLOCKED_REQUEST = 'blocked_request';
    public const EVENT_SQL_INJECTION = 'sql_injection_attempt';
    public const EVENT_XSS_ATTEMPT = 'xss_attempt';
    public const EVENT_SUSPICIOUS_ACTIVITY = 'suspicious_activity';
    public const EVENT_BRUTE_FORCE = 'brute_force_detected';
    public const EVENT_UNAUTHORIZED_ACCESS = 'unauthorized_access';
    public const EVENT_ADMIN_ACCESS = 'admin_access';

    /**
     * Severity levels
     */
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_INFO = 'info';

    /**
     * Auto-block thresholds
     */
    private const FAILED_LOGIN_THRESHOLD = 10;  // Block after 10 failed logins in window
    private const RATE_LIMIT_THRESHOLD = 20;    // Block after 20 rate limit hits
    private const SUSPICIOUS_THRESHOLD = 5;     // Block after 5 suspicious events
    private const THRESHOLD_WINDOW_MINUTES = 15;
    private const AUTO_BLOCK_DURATION_HOURS = 24;

    /**
     * Log a security event
     */
    public function log(
        string $eventType,
        string $severity,
        ?Request $request = null,
        ?string $userId = null,
        array $metadata = [],
        ?string $description = null
    ): string {
        $id = Str::uuid()->toString();
        $ip = $request?->ip() ?? 'unknown';

        try {
            // Only log if table exists
            if (!Schema::hasTable('security_events')) {
                Log::info('Security event (table not ready)', [
                    'event_type' => $eventType,
                    'severity' => $severity,
                    'ip' => $ip,
                ]);
                return $id;
            }

            DB::table('security_events')->insert([
                'id' => $id,
                'event_type' => $eventType,
                'severity' => $severity,
                'ip_address' => $ip,
                'user_id' => $userId ?? $request?->user()?->id,
                'user_agent' => $request ? mb_substr($request->userAgent() ?? '', 0, 500) : null,
                'endpoint' => $request?->path(),
                'method' => $request?->method(),
                'description' => $description ?? $this->generateDescription($eventType, $metadata),
                'metadata' => json_encode($metadata),
                'country_code' => $this->getCountryCode($ip),
                'acknowledged' => false,
                'created_at' => now(),
            ]);

            // Check if we should auto-block this IP
            if ($request && $this->shouldAutoBlock($ip, $eventType)) {
                $this->autoBlockIp($ip, $eventType);
            }

        } catch (\Exception $e) {
            Log::error('Failed to log security event', [
                'error' => $e->getMessage(),
                'event_type' => $eventType,
                'ip' => $ip,
            ]);
        }

        return $id;
    }

    /**
     * Log a failed login attempt
     */
    public function logFailedLogin(Request $request, string $email): string
    {
        return $this->log(
            self::EVENT_FAILED_LOGIN,
            self::SEVERITY_MEDIUM,
            $request,
            null,
            ['email' => $email],
            "Tentativa de login falha para: {$email}"
        );
    }

    /**
     * Log a rate limit hit
     */
    public function logRateLimited(Request $request, string $endpoint): string
    {
        return $this->log(
            self::EVENT_RATE_LIMITED,
            self::SEVERITY_MEDIUM,
            $request,
            null,
            ['endpoint' => $endpoint],
            "Rate limit excedido no endpoint: {$endpoint}"
        );
    }

    /**
     * Log a SQL injection attempt
     */
    public function logSqlInjectionAttempt(Request $request, string $parameter, string $value): string
    {
        return $this->log(
            self::EVENT_SQL_INJECTION,
            self::SEVERITY_CRITICAL,
            $request,
            null,
            [
                'parameter' => $parameter,
                'value' => mb_substr($value, 0, 200),
            ],
            "Tentativa de SQL Injection detectada no parâmetro: {$parameter}"
        );
    }

    /**
     * Log an XSS attempt
     */
    public function logXssAttempt(Request $request, string $parameter, string $value): string
    {
        return $this->log(
            self::EVENT_XSS_ATTEMPT,
            self::SEVERITY_HIGH,
            $request,
            null,
            [
                'parameter' => $parameter,
                'value' => mb_substr($value, 0, 200),
            ],
            "Tentativa de XSS detectada no parâmetro: {$parameter}"
        );
    }

    /**
     * Log unauthorized access attempt
     */
    public function logUnauthorizedAccess(Request $request, string $resource): string
    {
        return $this->log(
            self::EVENT_UNAUTHORIZED_ACCESS,
            self::SEVERITY_HIGH,
            $request,
            null,
            ['resource' => $resource],
            "Tentativa de acesso não autorizado a: {$resource}"
        );
    }

    /**
     * Log brute force detection
     */
    public function logBruteForceDetected(Request $request, string $target): string
    {
        return $this->log(
            self::EVENT_BRUTE_FORCE,
            self::SEVERITY_CRITICAL,
            $request,
            null,
            ['target' => $target],
            "Ataque de força bruta detectado contra: {$target}"
        );
    }

    /**
     * Log suspicious activity
     */
    public function logSuspiciousActivity(
        Request $request,
        string $description,
        array $metadata = []
    ): string {
        return $this->log(
            self::EVENT_SUSPICIOUS_ACTIVITY,
            self::SEVERITY_HIGH,
            $request,
            null,
            $metadata,
            $description
        );
    }

    /**
     * Check if an IP should be auto-blocked
     */
    protected function shouldAutoBlock(string $ip, string $eventType): bool
    {
        // Skip if table doesn't exist yet
        if (!Schema::hasTable('security_events')) {
            return false;
        }

        $windowStart = now()->subMinutes(self::THRESHOLD_WINDOW_MINUTES);

        // Count events by type
        $counts = DB::table('security_events')
            ->where('ip_address', $ip)
            ->where('created_at', '>=', $windowStart)
            ->select('event_type', DB::raw('COUNT(*) as count'))
            ->groupBy('event_type')
            ->pluck('count', 'event_type')
            ->toArray();

        // Check thresholds
        if (($counts[self::EVENT_FAILED_LOGIN] ?? 0) >= self::FAILED_LOGIN_THRESHOLD) {
            return true;
        }

        if (($counts[self::EVENT_RATE_LIMITED] ?? 0) >= self::RATE_LIMIT_THRESHOLD) {
            return true;
        }

        if (($counts[self::EVENT_SQL_INJECTION] ?? 0) > 0) {
            return true; // Immediate block for SQL injection
        }

        $suspiciousCount = ($counts[self::EVENT_SUSPICIOUS_ACTIVITY] ?? 0)
            + ($counts[self::EVENT_UNAUTHORIZED_ACCESS] ?? 0)
            + ($counts[self::EVENT_XSS_ATTEMPT] ?? 0);

        if ($suspiciousCount >= self::SUSPICIOUS_THRESHOLD) {
            return true;
        }

        return false;
    }

    /**
     * Automatically block an IP
     */
    protected function autoBlockIp(string $ip, string $triggerEvent): void
    {
        // Skip if table doesn't exist yet
        if (!Schema::hasTable('blocked_ips')) {
            return;
        }

        // Check if already blocked
        $existing = DB::table('blocked_ips')
            ->where('ip_address', $ip)
            ->where('status', 'active')
            ->exists();

        if ($existing) {
            return;
        }

        $reason = "Bloqueio automático: múltiplos eventos de '{$triggerEvent}' detectados";
        $expiresAt = now()->addHours(self::AUTO_BLOCK_DURATION_HOURS);

        try {
            DB::table('blocked_ips')->insert([
                'id' => Str::uuid()->toString(),
                'ip_address' => $ip,
                'reason' => $reason,
                'block_type' => 'automatic',
                'blocked_by' => null,
                'expires_at' => $expiresAt,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Add to cache
            Cache::put("blocked_ip:{$ip}", true, $expiresAt);

            // Log the auto-block
            $this->log(
                self::EVENT_IP_BLOCKED,
                self::SEVERITY_HIGH,
                null,
                null,
                [
                    'blocked_ip' => $ip,
                    'trigger_event' => $triggerEvent,
                    'duration_hours' => self::AUTO_BLOCK_DURATION_HOURS,
                ],
                "IP {$ip} bloqueado automaticamente: {$reason}"
            );

            Log::warning('IP auto-blocked', [
                'ip' => $ip,
                'trigger' => $triggerEvent,
                'expires_at' => $expiresAt->toIso8601String(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to auto-block IP', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get country code from IP (simplified - would use GeoIP in production)
     */
    protected function getCountryCode(string $ip): ?string
    {
        // In production, use a GeoIP service like MaxMind
        // For now, return null
        return null;
    }

    /**
     * Generate description for event type
     */
    protected function generateDescription(string $eventType, array $metadata): string
    {
        return match ($eventType) {
            self::EVENT_FAILED_LOGIN => "Tentativa de login falha" . (isset($metadata['email']) ? " para {$metadata['email']}" : ''),
            self::EVENT_RATE_LIMITED => "Rate limit excedido" . (isset($metadata['endpoint']) ? " em {$metadata['endpoint']}" : ''),
            self::EVENT_IP_BLOCKED => "IP bloqueado" . (isset($metadata['reason']) ? ": {$metadata['reason']}" : ''),
            self::EVENT_IP_UNBLOCKED => "IP desbloqueado",
            self::EVENT_SQL_INJECTION => "Tentativa de SQL Injection detectada",
            self::EVENT_XSS_ATTEMPT => "Tentativa de XSS detectada",
            self::EVENT_BRUTE_FORCE => "Ataque de força bruta detectado",
            self::EVENT_UNAUTHORIZED_ACCESS => "Tentativa de acesso não autorizado",
            self::EVENT_SUSPICIOUS_ACTIVITY => "Atividade suspeita detectada",
            default => $eventType,
        };
    }

    /**
     * Get security stats for dashboard
     */
    public function getStats(string $timeframe = '24h'): array
    {
        $since = match ($timeframe) {
            '1h' => now()->subHour(),
            '6h' => now()->subHours(6),
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            default => now()->subDay(),
        };

        return [
            'total_events' => DB::table('security_events')
                ->where('created_at', '>=', $since)
                ->count(),
            
            'critical_events' => DB::table('security_events')
                ->where('created_at', '>=', $since)
                ->where('severity', 'critical')
                ->count(),
            
            'blocked_ips' => DB::table('blocked_ips')
                ->where('status', 'active')
                ->count(),
            
            'failed_logins' => DB::table('security_events')
                ->where('created_at', '>=', $since)
                ->where('event_type', self::EVENT_FAILED_LOGIN)
                ->count(),
            
            'rate_limited' => DB::table('security_events')
                ->where('created_at', '>=', $since)
                ->where('event_type', self::EVENT_RATE_LIMITED)
                ->count(),
            
            'unacknowledged_alerts' => DB::table('security_events')
                ->whereIn('severity', ['critical', 'high'])
                ->where('acknowledged', false)
                ->count(),
        ];
    }
}
