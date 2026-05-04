<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Security Monitoring Controller
 * 
 * Provides comprehensive security monitoring, attack detection,
 * IP management, and security event logging for administrators.
 */
class SecurityMonitorController extends Controller
{
    /**
     * Check if security tables are ready
     */
    protected function securityTablesReady(): bool
    {
        return Schema::hasTable('security_events') && Schema::hasTable('blocked_ips');
    }

    /**
     * Get security dashboard overview
     */
    public function dashboard(Request $request): JsonResponse
    {
        // Check if tables exist
        if (!$this->securityTablesReady()) {
            return response()->json([
                'success' => false,
                'message' => 'Tabelas de segurança ainda não foram criadas. Execute: php artisan migrate',
                'migration_required' => true,
            ], 503);
        }

        try {
            $timeframe = $request->input('timeframe', '24h');
            $since = $this->getTimeframeSince($timeframe);

            // Security metrics
            $metrics = [
                'blocked_requests' => $this->getBlockedRequestsCount($since),
                'failed_logins' => $this->getFailedLoginsCount($since),
                'rate_limited' => $this->getRateLimitedCount($since),
                'suspicious_activities' => $this->getSuspiciousActivitiesCount($since),
                'blocked_ips' => $this->getBlockedIpsCount(),
                'admin_accesses' => $this->getAdminAccessCount($since),
            ];

            // Threat level calculation
            $threatLevelData = $this->calculateThreatLevel($metrics);

            // Recent security events
            $recentEvents = $this->getRecentSecurityEvents(10);

            // Top attacking IPs
            $topAttackingIps = $this->getTopAttackingIps($since, 10);

            // Geographic distribution of attacks
            $geoDistribution = $this->getAttackGeoDistribution($since);

            // Attack timeline
            $timeline = $this->getAttackTimeline($since);

            return response()->json([
                'success' => true,
                'data' => [
                    'threat_level' => $threatLevelData['level'],
                    'threat_score' => $threatLevelData['score'],
                    'metrics' => $metrics,
                    'recent_events' => $recentEvents,
                    'top_attacking_ips' => $topAttackingIps,
                    'geo_distribution' => $geoDistribution,
                    'timeline' => $timeline,
                    'timeframe' => $timeframe,
                    'generated_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Security dashboard error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar dashboard de segurança. Verifique se as migrations foram executadas: php artisan migrate',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get detailed security logs with filtering
     */
    public function logs(Request $request): JsonResponse
    {
        if (!$this->securityTablesReady()) {
            return response()->json([
                'success' => false,
                'message' => 'Tabelas de segurança não disponíveis',
                'migration_required' => true,
            ], 503);
        }

        try {
            $query = DB::table('security_events');

        // Filters
        if ($request->filled('type')) {
            $query->where('event_type', $request->input('type'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        if ($request->filled('ip')) {
            $query->where('ip_address', $request->input('ip'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }

        if ($request->filled('search')) {
            $search = addcslashes($request->input('search'), '%_\\');
            $query->where(function ($q) use ($search) {
                $q->where('ip_address', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('user_agent', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 50), 100);
        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
        } catch (\Throwable $e) {
            Log::error('SecurityMonitorController::logs error', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0]]);
        }
    }

    /**
     * Get admin access logs
     */
    public function adminLogs(Request $request): JsonResponse
    {
        if (!Schema::hasTable('admin_access_logs')) {
            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0],
            ]);
        }

        $query = DB::table('admin_access_logs')
            ->leftJoin('users', 'admin_access_logs.user_id', '=', 'users.id')
            ->select([
                'admin_access_logs.*',
                'users.name as user_name',
                'users.email as user_email',
            ]);

        // Filters
        if ($request->filled('user_id')) {
            $query->where('admin_access_logs.user_id', $request->input('user_id'));
        }

        if ($request->filled('endpoint')) {
            $query->where('endpoint', 'like', '%' . $request->input('endpoint') . '%');
        }

        if ($request->filled('ip')) {
            $query->where('ip_address', $request->input('ip'));
        }

        if ($request->filled('status')) {
            $query->where('response_status', $request->input('status'));
        }

        if ($request->filled('from')) {
            $query->where('accessed_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('accessed_at', '<=', $request->input('to'));
        }

        $perPage = min((int) $request->input('per_page', 50), 100);
        $logs = $query->orderBy('accessed_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Get blocked IPs list
     */
    public function blockedIps(Request $request): JsonResponse
    {
        if (!Schema::hasTable('blocked_ips')) {
            return response()->json([
                'success' => false,
                'message' => 'Tabela de IPs bloqueados não disponível',
                'migration_required' => true,
            ], 503);
        }

        $query = DB::table('blocked_ips');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $query->where('ip_address', 'like', '%' . $request->input('search') . '%');
        }

        $perPage = min((int) $request->input('per_page', 50), 100);
        $ips = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $ips->items(),
            'meta' => [
                'current_page' => $ips->currentPage(),
                'last_page' => $ips->lastPage(),
                'per_page' => $ips->perPage(),
                'total' => $ips->total(),
            ],
        ]);
    }

    /**
     * Block an IP address
     */
    public function blockIp(Request $request): JsonResponse
    {
        if (!Schema::hasTable('blocked_ips')) {
            return response()->json([
                'success' => false,
                'message' => 'Tabela de IPs bloqueados não disponível',
                'migration_required' => true,
            ], 503);
        }

        $request->validate([
            'ip_address' => ['required', 'ip'],
            'reason' => ['required', 'string', 'max:500'],
            'duration' => ['nullable', 'integer', 'min:1'], // minutes, null = permanent
            'block_type' => ['nullable', 'in:manual,automatic'],
        ]);

        $ip = $request->input('ip_address');
        $reason = $request->input('reason');
        $duration = $request->input('duration');
        $blockType = $request->input('block_type', 'manual');

        // Check if already blocked
        $existing = DB::table('blocked_ips')
            ->where('ip_address', $ip)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'already_blocked',
                    'message' => 'Este IP já está bloqueado.',
                ],
            ], 422);
        }

        $expiresAt = $duration ? now()->addMinutes($duration) : null;

        DB::table('blocked_ips')->insert([
            'id' => Str::uuid()->toString(),
            'ip_address' => $ip,
            'reason' => $reason,
            'block_type' => $blockType,
            'blocked_by' => $request->user()->id,
            'expires_at' => $expiresAt,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add to cache for fast lookup
        Cache::put("blocked_ip:{$ip}", true, $expiresAt ?? now()->addYears(10));

        // Log the action
        $this->logSecurityEvent('ip_blocked', 'warning', [
            'blocked_ip' => $ip,
            'reason' => $reason,
            'duration' => $duration,
            'blocked_by' => $request->user()->id,
        ], $request);

        Log::warning('IP blocked manually', [
            'ip' => $ip,
            'reason' => $reason,
            'blocked_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'message' => "IP {$ip} bloqueado com sucesso.",
                'expires_at' => $expiresAt?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Unblock an IP address
     */
    public function unblockIp(Request $request, string $ipOrId): JsonResponse
    {
        if (!Schema::hasTable('blocked_ips')) {
            return response()->json([
                'success' => false,
                'message' => 'Tabela de IPs bloqueados não disponível',
                'migration_required' => true,
            ], 503);
        }

        // Find by ID or IP
        $blocked = DB::table('blocked_ips')
            ->where(function ($q) use ($ipOrId) {
                $q->where('id', $ipOrId)
                  ->orWhere('ip_address', $ipOrId);
            })
            ->where('status', 'active')
            ->first();

        if (!$blocked) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'not_found',
                    'message' => 'IP bloqueado não encontrado.',
                ],
            ], 404);
        }

        DB::table('blocked_ips')
            ->where('id', $blocked->id)
            ->update([
                'status' => 'unblocked',
                'unblocked_by' => $request->user()->id,
                'unblocked_at' => now(),
                'updated_at' => now(),
            ]);

        // Remove from cache
        Cache::forget("blocked_ip:{$blocked->ip_address}");

        // Log the action
        $this->logSecurityEvent('ip_unblocked', 'info', [
            'unblocked_ip' => $blocked->ip_address,
            'unblocked_by' => $request->user()->id,
        ], $request);

        return response()->json([
            'success' => true,
            'data' => [
                'message' => "IP {$blocked->ip_address} desbloqueado com sucesso.",
            ],
        ]);
    }

    /**
     * Get rate limit status for an IP
     */
    public function rateLimitStatus(Request $request): JsonResponse
    {
        $ip = $request->input('ip', $request->ip());

        $rateLimits = Schema::hasTable('rate_limit_logs')
            ? DB::table('rate_limit_logs')
                ->where('identifier', $ip)
                ->where('window_start', '>=', now()->subHour())
                ->get()
            : collect([]);

        $blocked = Cache::get("blocked_ip:{$ip}", false);

        return response()->json([
            'success' => true,
            'data' => [
                'ip' => $ip,
                'is_blocked' => $blocked,
                'rate_limits' => $rateLimits,
            ],
        ]);
    }

    /**
     * Get failed login attempts
     */
    public function failedLogins(Request $request): JsonResponse
    {
        if (!Schema::hasTable('security_events')) {
            return response()->json([
                'success' => true,
                'data' => ['attempts' => [], 'by_ip' => []],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0],
            ]);
        }

        $since = $this->getTimeframeSince($request->input('timeframe', '24h'));

        $query = DB::table('security_events')
            ->where('event_type', 'failed_login')
            ->where('created_at', '>=', $since);

        if ($request->filled('ip')) {
            $query->where('ip_address', $request->input('ip'));
        }

        if ($request->filled('email')) {
            $query->where('metadata->email', $request->input('email'));
        }

        $perPage = min((int) $request->input('per_page', 50), 100);
        $attempts = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Aggregate by IP
        $byIp = DB::table('security_events')
            ->where('event_type', 'failed_login')
            ->where('created_at', '>=', $since)
            ->select('ip_address', DB::raw('COUNT(*) as attempts'))
            ->groupBy('ip_address')
            ->orderByDesc('attempts')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'attempts' => $attempts->items(),
                'by_ip' => $byIp,
            ],
            'meta' => [
                'current_page' => $attempts->currentPage(),
                'last_page' => $attempts->lastPage(),
                'per_page' => $attempts->perPage(),
                'total' => $attempts->total(),
            ],
        ]);
    }

    /**
     * Get live activity stream (for real-time monitoring)
     */
    public function liveActivity(Request $request): JsonResponse
    {
        try {
            $since = $request->input('since', now()->subMinutes(5)->toIso8601String());

            // Recent security events
            $events = Schema::hasTable('security_events')
                ? DB::table('security_events')
                    ->where('created_at', '>=', $since)
                    ->orderBy('created_at', 'desc')
                    ->limit(50)
                    ->get()
                : collect([]);

            // Recent admin accesses
            $adminAccesses = Schema::hasTable('admin_access_logs')
                ? DB::table('admin_access_logs')
                    ->where('accessed_at', '>=', $since)
                    ->orderBy('accessed_at', 'desc')
                    ->limit(20)
                    ->get()
                : collect([]);

            // Current rate limit blocks
            $rateLimitBlocks = collect([]);
            if (Schema::hasTable('rate_limit_logs') && Schema::hasColumn('rate_limit_logs', 'blocked') && Schema::hasColumn('rate_limit_logs', 'updated_at')) {
                $rateLimitBlocks = DB::table('rate_limit_logs')
                    ->where('blocked', true)
                    ->where('updated_at', '>=', $since)
                    ->orderBy('updated_at', 'desc')
                    ->limit(20)
                    ->get();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'security_events' => $events,
                    'admin_accesses' => $adminAccesses,
                    'rate_limit_blocks' => $rateLimitBlocks,
                    'server_time' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SecurityMonitorController::liveActivity error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => true,
                'data' => [
                    'security_events' => [],
                    'admin_accesses' => [],
                    'rate_limit_blocks' => [],
                    'server_time' => now()->toIso8601String(),
                ],
            ]);
        }
    }

    /**
     * Get security alerts (high priority events)
     */
    public function alerts(Request $request): JsonResponse
    {
        if (!Schema::hasTable('security_events')) {
            return response()->json([
                'success' => true,
                'data' => ['alerts' => [], 'critical_count' => 0],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0],
            ]);
        }

        try {
            $hasSeverity = Schema::hasColumn('security_events', 'severity');
            $hasAcknowledged = Schema::hasColumn('security_events', 'acknowledged');

            if (!$hasSeverity || !$hasAcknowledged) {
                return response()->json([
                    'success' => true,
                    'data' => ['alerts' => [], 'critical_count' => 0],
                    'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0],
                ]);
            }

            $query = DB::table('security_events')
                ->whereIn('severity', ['critical', 'high'])
                ->where('acknowledged', false);

            if ($request->filled('from')) {
                $query->where('created_at', '>=', $request->input('from'));
            }

            $perPage = min((int) $request->input('per_page', 50), 100);
            $alerts = $query->orderBy('created_at', 'desc')->paginate($perPage);

            $criticalCount = DB::table('security_events')
                ->where('severity', 'critical')
                ->where('acknowledged', false)
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'alerts' => $alerts->items(),
                    'critical_count' => $criticalCount,
                ],
                'meta' => [
                    'current_page' => $alerts->currentPage(),
                    'last_page' => $alerts->lastPage(),
                    'per_page' => $alerts->perPage(),
                    'total' => $alerts->total(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SecurityMonitorController::alerts error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => true,
                'data' => ['alerts' => [], 'critical_count' => 0],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0],
            ]);
        }
    }

    /**
     * Acknowledge a security alert
     */
    public function acknowledgeAlert(Request $request, string $alertId): JsonResponse
    {
        if (!Schema::hasTable('security_events')) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'not_available', 'message' => 'Tabela não disponível'],
            ], 503);
        }

        try {
            $updateData = ['acknowledged' => true];

            if (Schema::hasColumn('security_events', 'acknowledged_by')) {
                $updateData['acknowledged_by'] = $request->user()->id;
            }
            if (Schema::hasColumn('security_events', 'acknowledged_at')) {
                $updateData['acknowledged_at'] = now();
            }

            $updated = DB::table('security_events')
                ->where('id', $alertId)
                ->update($updateData);

            if (!$updated) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'not_found',
                        'message' => 'Alerta não encontrado.',
                    ],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Alerta reconhecido com sucesso.',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SecurityMonitorController::acknowledgeAlert error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao reconhecer alerta'], 500);
        }
    }

    /**
     * Export security logs
     */
    public function exportLogs(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'in:security_events,admin_access,blocked_ips,rate_limits'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'format' => ['nullable', 'in:json,csv'],
        ]);

        $from = $request->input('from');
        $to = $request->input('to');
        $type = $request->input('type');

        $table = match ($type) {
            'security_events' => 'security_events',
            'admin_access' => 'admin_access_logs',
            'blocked_ips' => 'blocked_ips',
            'rate_limits' => 'rate_limit_logs',
        };

        // Check if table exists
        if (!Schema::hasTable($table)) {
            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => [
                    'type' => $type,
                    'from' => $from,
                    'to' => $to,
                    'total_records' => 0,
                    'exported_at' => now()->toIso8601String(),
                    'note' => 'Tabela não disponível',
                ],
            ]);
        }

        $dateColumn = match ($type) {
            'admin_access' => 'accessed_at',
            default => 'created_at',
        };

        $data = DB::table($table)
            ->where($dateColumn, '>=', $from)
            ->where($dateColumn, '<=', $to)
            ->orderBy($dateColumn, 'desc')
            ->limit(10000) // Safety limit
            ->get();

        // Log the export
        $this->logSecurityEvent('logs_exported', 'info', [
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'records' => $data->count(),
            'exported_by' => $request->user()->id,
        ], $request);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'type' => $type,
                'from' => $from,
                'to' => $to,
                'total_records' => $data->count(),
                'exported_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get IP details and history
     */
    public function ipDetails(Request $request, string $ip): JsonResponse
    {
        // Validate IP
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'invalid_ip',
                    'message' => 'IP inválido.',
                ],
            ], 422);
        }

        // Check if blocked
        $blocked = Schema::hasTable('blocked_ips')
            ? DB::table('blocked_ips')
                ->where('ip_address', $ip)
                ->orderBy('created_at', 'desc')
                ->first()
            : null;

        // Security events from this IP
        $events = Schema::hasTable('security_events')
            ? DB::table('security_events')
                ->where('ip_address', $ip)
                ->orderBy('created_at', 'desc')
                ->limit(100)
                ->get()
            : collect([]);

        // Admin accesses from this IP
        $adminAccesses = Schema::hasTable('admin_access_logs')
            ? DB::table('admin_access_logs')
                ->where('ip_address', $ip)
                ->orderBy('accessed_at', 'desc')
                ->limit(50)
                ->get()
            : collect([]);

        // Rate limit history
        $rateLimits = Schema::hasTable('rate_limit_logs')
            ? DB::table('rate_limit_logs')
                ->where('identifier', $ip)
                ->orderBy('created_at', 'desc')
                ->limit(50)
                ->get()
            : collect([]);

        // Stats
        $stats = [
            'total_events' => $events->count(),
            'failed_logins' => $events->where('event_type', 'failed_login')->count(),
            'rate_limited' => $rateLimits->where('blocked', true)->count(),
            'first_seen' => $events->min('created_at'),
            'last_seen' => $events->max('created_at'),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'ip_address' => $ip,
                'is_blocked' => $blocked && $blocked->status === 'active',
                'block_info' => $blocked,
                'stats' => $stats,
                'security_events' => $events,
                'admin_accesses' => $adminAccesses,
                'rate_limits' => $rateLimits,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Helper Methods
    // ─────────────────────────────────────────────────────────────────

    protected function getTimeframeSince(string $timeframe): string
    {
        return match ($timeframe) {
            '1h' => now()->subHour()->toDateTimeString(),
            '6h' => now()->subHours(6)->toDateTimeString(),
            '24h' => now()->subDay()->toDateTimeString(),
            '7d' => now()->subWeek()->toDateTimeString(),
            '30d' => now()->subMonth()->toDateTimeString(),
            default => now()->subDay()->toDateTimeString(),
        };
    }

    protected function getBlockedRequestsCount(string $since): int
    {
        if (!Schema::hasTable('security_events')) {
            return 0;
        }
        return DB::table('security_events')
            ->where('event_type', 'request_blocked')
            ->where('created_at', '>=', $since)
            ->count();
    }

    protected function getFailedLoginsCount(string $since): int
    {
        if (!Schema::hasTable('security_events')) {
            return 0;
        }
        return DB::table('security_events')
            ->where('event_type', 'failed_login')
            ->where('created_at', '>=', $since)
            ->count();
    }

    protected function getRateLimitedCount(string $since): int
    {
        if (!Schema::hasTable('rate_limit_logs') || !Schema::hasColumn('rate_limit_logs', 'blocked')) {
            return 0;
        }
        return DB::table('rate_limit_logs')
            ->where('blocked', true)
            ->where('created_at', '>=', $since)
            ->count();
    }

    protected function getSuspiciousActivitiesCount(string $since): int
    {
        if (!Schema::hasTable('security_events') || !Schema::hasColumn('security_events', 'severity')) {
            return 0;
        }
        return DB::table('security_events')
            ->whereIn('severity', ['high', 'critical'])
            ->where('created_at', '>=', $since)
            ->count();
    }

    protected function getBlockedIpsCount(): int
    {
        if (!Schema::hasTable('blocked_ips')) {
            return 0;
        }
        return DB::table('blocked_ips')
            ->where('status', 'active')
            ->count();
    }

    protected function getAdminAccessCount(string $since): int
    {
        if (!Schema::hasTable('admin_access_logs')) {
            return 0;
        }
        return DB::table('admin_access_logs')
            ->where('accessed_at', '>=', $since)
            ->count();
    }

    protected function calculateThreatLevel(array $metrics): array
    {
        $score = 0;

        // Weight factors
        $score += min($metrics['failed_logins'] * 2, 30);
        $score += min($metrics['rate_limited'] * 3, 30);
        $score += min($metrics['suspicious_activities'] * 10, 40);

        $level = match (true) {
            $score >= 70 => 'critical',
            $score >= 50 => 'high',
            $score >= 30 => 'medium',
            default => 'low',
        };

        return [
            'level' => $level,
            'score' => $score,
            'color' => match ($level) {
                'critical' => '#dc2626',
                'high' => '#ea580c',
                'medium' => '#ca8a04',
                default => '#16a34a',
            },
        ];
    }

    protected function getRecentSecurityEvents(int $limit): array
    {
        if (!Schema::hasTable('security_events')) {
            return [];
        }
        return DB::table('security_events')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    protected function getTopAttackingIps(string $since, int $limit): array
    {
        if (!Schema::hasTable('security_events')) {
            return [];
        }
        return DB::table('security_events')
            ->where('created_at', '>=', $since)
            ->whereIn('event_type', ['failed_login', 'rate_limited', 'request_blocked', 'sql_injection_attempt'])
            ->select('ip_address', DB::raw('COUNT(*) as attack_count'))
            ->groupBy('ip_address')
            ->orderByDesc('attack_count')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    protected function getAttackGeoDistribution(string $since): array
    {
        if (!Schema::hasTable('security_events') || !Schema::hasColumn('security_events', 'country_code')) {
            return [];
        }
        // Simplified - would need GeoIP lookup in production
        return DB::table('security_events')
            ->where('created_at', '>=', $since)
            ->whereNotNull('country_code')
            ->select('country_code', DB::raw('COUNT(*) as count'))
            ->groupBy('country_code')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->toArray();
    }

    protected function getAttackTimeline(string $since): array
    {
        if (!Schema::hasTable('security_events')) {
            return [];
        }

        $driver = config('database.default');
        $connection = config("database.connections.{$driver}.driver", 'mysql');

        if ($connection === 'pgsql') {
            $hourExpr = "DATE_TRUNC('hour', created_at)";
        } else {
            $hourExpr = "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')";
        }

        return DB::table('security_events')
            ->where('created_at', '>=', $since)
            ->select(DB::raw("{$hourExpr} as hour"), DB::raw('COUNT(*) as count'))
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->toArray();
    }

    protected function logSecurityEvent(
        string $eventType,
        string $severity,
        array $metadata,
        Request $request
    ): void {
        if (!Schema::hasTable('security_events')) {
            return;
        }
        try {
            DB::table('security_events')->insert([
                'id' => Str::uuid()->toString(),
                'event_type' => $eventType,
                'severity' => $severity,
                'ip_address' => $request->ip(),
                'user_id' => $request->user()?->id,
                'user_agent' => mb_substr($request->userAgent() ?? '', 0, 500),
                'endpoint' => $request->path(),
                'method' => $request->method(),
                'metadata' => json_encode($metadata),
                'description' => $this->getEventDescription($eventType, $metadata),
                'acknowledged' => false,
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log security event', [
                'error' => $e->getMessage(),
                'event_type' => $eventType,
            ]);
        }
    }

    protected function getEventDescription(string $eventType, array $metadata): string
    {
        return match ($eventType) {
            'ip_blocked' => "IP {$metadata['blocked_ip']} bloqueado: {$metadata['reason']}",
            'ip_unblocked' => "IP {$metadata['unblocked_ip']} desbloqueado",
            'logs_exported' => "Logs exportados: {$metadata['type']} ({$metadata['records']} registros)",
            'failed_login' => "Tentativa de login falha para " . ($metadata['email'] ?? 'unknown'),
            default => $eventType,
        };
    }
}
