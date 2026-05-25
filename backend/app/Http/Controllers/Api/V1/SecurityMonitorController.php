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
        try {
            $timeframe = $request->input('timeframe', '24h');
            $since = $this->getTimeframeSince($timeframe);

            $totalRequests = $this->getTotalRequestsCount($since);
            $failedLogins = $this->getFailedLoginsCount($since);
            $rateLimitHits = $this->getRateLimitedCount($since);
            $blockedRequests = $this->getBlockedRequestsCount($since) + $rateLimitHits;
            $securityAlerts = $this->getSecurityAlertsCount($since);

            // Security metrics (legacy/extended payload)
            $metrics = [
                'total_requests' => $totalRequests,
                'blocked_requests' => $blockedRequests,
                'failed_logins' => $failedLogins,
                'rate_limited' => $rateLimitHits,
                'suspicious_activities' => $this->getSuspiciousActivitiesCount($since),
                'blocked_ips' => $this->getBlockedIpsCount(),
                'admin_accesses' => $this->getAdminAccessCount($since),
                'security_alerts' => $securityAlerts,
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

            $payload = [
                // Contract used by frontend/src/pages/admin/AdminMonitoring.tsx
                'total_requests_24h' => $totalRequests,
                'blocked_requests_24h' => $blockedRequests,
                'failed_logins_24h' => $failedLogins,
                'active_sessions' => $this->getActiveSessionsCount(),
                'blocked_ips' => $metrics['blocked_ips'],
                'rate_limit_hits' => $rateLimitHits,
                'security_alerts' => $securityAlerts,
                'uptime_percentage' => $this->calculateUptimePercentage($since),

                // Legacy/extended payload
                'threat_level' => $threatLevelData['level'],
                'threat_score' => $threatLevelData['score'],
                'metrics' => $metrics,
                'recent_events' => $recentEvents,
                'top_attacking_ips' => $topAttackingIps,
                'geo_distribution' => $geoDistribution,
                'timeline' => $timeline,
                'timeframe' => $timeframe,
                'generated_at' => now()->toIso8601String(),
            ];

            return response()->json([
                'success' => true,
                'data' => $payload,
            ]);
        } catch (\Throwable $e) {
            Log::error('Security dashboard error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => true,
                'data' => [
                    'total_requests_24h' => 0,
                    'blocked_requests_24h' => 0,
                    'failed_logins_24h' => 0,
                    'active_sessions' => 0,
                    'blocked_ips' => 0,
                    'rate_limit_hits' => 0,
                    'security_alerts' => 0,
                    'uptime_percentage' => 99.9,
                ],
            ]);
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
        try {
            $search = (string) $request->input('search', '');
            $status = (string) $request->input('status', 'active');

            if (Schema::hasTable('blocked_ips')) {
                $query = DB::table('blocked_ips');

                if (Schema::hasColumn('blocked_ips', 'status') && $status !== '' && $status !== 'all') {
                    $query->where('status', $status);
                }

                if ($search !== '') {
                    $query->where('ip_address', 'like', '%' . addcslashes($search, '%_\\') . '%');
                }

                $perPage = min((int) $request->input('per_page', 50), 100);
                $ips = $query->orderByDesc('created_at')->paginate($perPage);

                $data = collect($ips->items())->map(function ($item) {
                    $blockedAt = $this->toIso8601String($item->created_at ?? null);

                    return [
                        'id' => (string) $item->id,
                        'ip' => (string) ($item->ip_address ?? ''),
                        'ip_address' => (string) ($item->ip_address ?? ''),
                        'reason' => (string) ($item->reason ?? ''),
                        'block_type' => isset($item->block_type) ? (string) $item->block_type : 'manual',
                        'status' => isset($item->status) ? (string) $item->status : 'active',
                        'blocked_at' => $blockedAt,
                        'created_at' => $blockedAt,
                        'expires_at' => $this->toIso8601String($item->expires_at ?? null),
                    ];
                })->values()->all();

                return response()->json([
                    'success' => true,
                    'data' => $data,
                    'meta' => [
                        'current_page' => $ips->currentPage(),
                        'last_page' => $ips->lastPage(),
                        'per_page' => $ips->perPage(),
                        'total' => $ips->total(),
                    ],
                ]);
            }

            // Fallback: use generic blacklist if blocked_ips table does not exist.
            if (Schema::hasTable('blacklists')) {
                $query = DB::table('blacklists')
                    ->where('type', 'ip')
                    ->where(function ($q) {
                        $q->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                    });

                if ($search !== '') {
                    $query->where('value', 'like', '%' . addcslashes($search, '%_\\') . '%');
                }

                $items = $query->orderByDesc('created_at')->limit(100)->get();

                $data = $items->map(function ($item) {
                    $blockedAt = $this->toIso8601String($item->created_at ?? null);

                    return [
                        'id' => (string) $item->id,
                        'ip' => (string) ($item->value ?? ''),
                        'ip_address' => (string) ($item->value ?? ''),
                        'reason' => (string) ($item->reason ?? 'Bloqueado na blacklist'),
                        'block_type' => 'blacklist',
                        'status' => 'active',
                        'blocked_at' => $blockedAt,
                        'created_at' => $blockedAt,
                        'expires_at' => $this->toIso8601String($item->expires_at ?? null),
                    ];
                })->values()->all();

                return response()->json([
                    'success' => true,
                    'data' => $data,
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => count($data),
                        'total' => count($data),
                        'source' => 'blacklists',
                    ],
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 0,
                    'total' => 0,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SecurityMonitorController::blockedIps error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 0,
                    'total' => 0,
                ],
            ]);
        }
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
            $limit = min(max((int) $request->input('limit', 80), 1), 200);

            $sinceInput = (string) $request->input('since', now()->subMinutes(15)->toIso8601String());
            try {
                $since = \Carbon\Carbon::parse($sinceInput)->toDateTimeString();
            } catch (\Throwable $e) {
                $since = now()->subMinutes(15)->toDateTimeString();
            }

            $activity = collect([]);

            if (Schema::hasTable('admin_access_logs')) {
                $adminAccesses = DB::table('admin_access_logs')
                    ->where('accessed_at', '>=', $since)
                    ->orderByDesc('accessed_at')
                    ->limit($limit)
                    ->get();

                $activity = $activity->concat($adminAccesses->map(function ($row) {
                    $status = isset($row->response_status) ? (int) $row->response_status : 200;

                    return [
                        'id' => 'admin_' . (string) $row->id,
                        'type' => $status >= 500 ? 'error' : 'request',
                        'ip' => (string) ($row->ip_address ?? 'unknown'),
                        'path' => '/' . ltrim((string) ($row->endpoint ?? ''), '/'),
                        'status' => $status,
                        'method' => strtoupper((string) ($row->method ?? 'GET')),
                        'timestamp' => $this->toIso8601String($row->accessed_at ?? null) ?? now()->toIso8601String(),
                        'user_agent' => isset($row->user_agent) ? (string) $row->user_agent : null,
                        'country' => null,
                    ];
                }));
            }

            if (Schema::hasTable('security_events')) {
                $hasSeverity = Schema::hasColumn('security_events', 'severity');
                $hasCountryCode = Schema::hasColumn('security_events', 'country_code');
                $hasDescription = Schema::hasColumn('security_events', 'description');
                $hasEndpoint = Schema::hasColumn('security_events', 'endpoint');
                $hasMethod = Schema::hasColumn('security_events', 'method');

                $securityEvents = DB::table('security_events')
                    ->where('created_at', '>=', $since)
                    ->select([
                        'id',
                        'event_type',
                        'ip_address',
                        $hasEndpoint ? 'endpoint' : DB::raw('NULL as endpoint'),
                        $hasMethod ? 'method' : DB::raw("'GET' as method"),
                        $hasSeverity ? 'severity' : DB::raw("'low' as severity"),
                        $hasDescription ? 'description' : DB::raw('NULL as description'),
                        'user_agent',
                        $hasCountryCode ? 'country_code' : DB::raw('NULL as country_code'),
                        'created_at',
                    ])
                    ->orderByDesc('created_at')
                    ->limit($limit)
                    ->get();

                $activity = $activity->concat($securityEvents->map(function ($row) {
                    $eventType = (string) ($row->event_type ?? 'security_event');
                    $severity = isset($row->severity) ? (string) $row->severity : 'low';
                    $status = $this->statusFromSecurityEvent($eventType, $severity);

                    return [
                        'id' => 'sec_' . (string) $row->id,
                        'type' => $this->activityTypeFromSecurityEvent($eventType, $status),
                        'ip' => (string) ($row->ip_address ?? 'unknown'),
                        'path' => $this->normalizePath((string) ($row->endpoint ?? '/security/events')),
                        'status' => $status,
                        'method' => strtoupper((string) ($row->method ?? 'GET')),
                        'timestamp' => $this->toIso8601String($row->created_at ?? null) ?? now()->toIso8601String(),
                        'user_agent' => isset($row->user_agent) ? (string) $row->user_agent : null,
                        'country' => isset($row->country_code) ? (string) $row->country_code : null,
                    ];
                }));
            }

            if (Schema::hasTable('rate_limit_logs') && Schema::hasColumn('rate_limit_logs', 'blocked')) {
                $timeColumn = $this->resolveRateLimitTimeColumn();

                $rateLimitBlocks = DB::table('rate_limit_logs')
                    ->where('blocked', true)
                    ->where($timeColumn, '>=', $since)
                    ->orderByDesc($timeColumn)
                    ->limit($limit)
                    ->get();

                $activity = $activity->concat($rateLimitBlocks->map(function ($row) use ($timeColumn) {
                    [$method, $path] = $this->parseRateLimitEndpoint((string) ($row->endpoint ?? 'GET:/'));

                    return [
                        'id' => 'rl_' . (string) $row->id,
                        'type' => 'block',
                        'ip' => $this->extractIpFromIdentifier(isset($row->identifier) ? (string) $row->identifier : null) ?? 'unknown',
                        'path' => $path,
                        'status' => 429,
                        'method' => $method,
                        'timestamp' => $this->toIso8601String($row->{$timeColumn} ?? null) ?? now()->toIso8601String(),
                        'user_agent' => null,
                        'country' => null,
                    ];
                }));
            }

            $sorted = $activity
                ->sortByDesc(fn (array $item) => (string) ($item['timestamp'] ?? ''))
                ->take($limit)
                ->values()
                ->all();

            return response()->json([
                'success' => true,
                'data' => $sorted,
                'meta' => [
                    'server_time' => now()->toIso8601String(),
                    'since' => $since,
                    'total' => count($sorted),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SecurityMonitorController::liveActivity error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => ['server_time' => now()->toIso8601String()],
            ]);
        }
    }

    /**
     * Get security alerts (high priority events)
     */
    public function alerts(Request $request): JsonResponse
    {
        try {
            $limit = min(max((int) $request->input('limit', 50), 1), 200);
            $from = (string) $request->input('from', $this->getTimeframeSince('24h'));
            $includeAcknowledged = $request->boolean('include_acknowledged', false);

            $alerts = collect([]);

            if (Schema::hasTable('security_events')) {
                $hasSeverity = Schema::hasColumn('security_events', 'severity');
                $hasAcknowledged = Schema::hasColumn('security_events', 'acknowledged');
                $hasDescription = Schema::hasColumn('security_events', 'description');

                $query = DB::table('security_events')
                    ->where('created_at', '>=', $from);

                if ($hasSeverity) {
                    $query->whereIn('severity', ['critical', 'high']);
                } else {
                    $query->whereIn('event_type', [
                        'failed_login',
                        'blocked_request',
                        'request_blocked',
                        'rate_limited',
                        'sql_injection_attempt',
                        'xss_attempt',
                        'brute_force_detected',
                        'unauthorized_access',
                    ]);
                }

                if (!$includeAcknowledged && $hasAcknowledged) {
                    $query->where('acknowledged', false);
                }

                $rows = $query
                    ->orderByDesc('created_at')
                    ->limit($limit)
                    ->get([
                        'id',
                        'event_type',
                        $hasSeverity ? 'severity' : DB::raw("'high' as severity"),
                        $hasDescription ? 'description' : DB::raw('NULL as description'),
                        'ip_address',
                        $hasAcknowledged ? 'acknowledged' : DB::raw('false as acknowledged'),
                        'created_at',
                    ]);

                $alerts = $alerts->concat($rows->map(function ($row) {
                    $eventType = (string) ($row->event_type ?? 'security_event');

                    return [
                        'id' => (string) $row->id,
                        'type' => $eventType,
                        'severity' => $this->normalizeSeverity(isset($row->severity) ? (string) $row->severity : 'high'),
                        'message' => (string) ($row->description ?: Str::headline(str_replace('_', ' ', $eventType))),
                        'ip' => isset($row->ip_address) ? (string) $row->ip_address : null,
                        'timestamp' => $this->toIso8601String($row->created_at ?? null) ?? now()->toIso8601String(),
                        'acknowledged' => (bool) ($row->acknowledged ?? false),
                    ];
                }));
            }

            // Fallback when no dedicated security events are available.
            if ($alerts->isEmpty() && Schema::hasTable('admin_access_logs') && Schema::hasColumn('admin_access_logs', 'response_status')) {
                $adminAlerts = DB::table('admin_access_logs')
                    ->where('accessed_at', '>=', $from)
                    ->where('response_status', '>=', 500)
                    ->orderByDesc('accessed_at')
                    ->limit($limit)
                    ->get();

                $alerts = $alerts->concat($adminAlerts->map(function ($row) {
                    return [
                        'id' => 'admin_error_' . (string) $row->id,
                        'type' => 'admin_error',
                        'severity' => 'high',
                        'message' => 'Erro ' . (int) $row->response_status . ' em ' . $this->normalizePath((string) ($row->endpoint ?? '/admin')),
                        'ip' => isset($row->ip_address) ? (string) $row->ip_address : null,
                        'timestamp' => $this->toIso8601String($row->accessed_at ?? null) ?? now()->toIso8601String(),
                        'acknowledged' => false,
                    ];
                }));
            }

            $result = $alerts
                ->sortByDesc(fn (array $item) => (string) ($item['timestamp'] ?? ''))
                ->take($limit)
                ->values();

            return response()->json([
                'success' => true,
                'data' => $result->all(),
                'meta' => [
                    'total' => $result->count(),
                    'critical_count' => $result->where('severity', 'critical')->count(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SecurityMonitorController::alerts error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => ['total' => 0, 'critical_count' => 0],
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

    protected function getTotalRequestsCount(string $since): int
    {
        $total = 0;

        if (Schema::hasTable('rate_limit_logs')) {
            $timeColumn = $this->resolveRateLimitTimeColumn();
            $query = DB::table('rate_limit_logs')->where($timeColumn, '>=', $since);

            $rateLimitRequests = Schema::hasColumn('rate_limit_logs', 'requests_count')
                ? (int) ($query->sum('requests_count') ?? 0)
                : (int) $query->count();

            $total += $rateLimitRequests;
        }

        if (Schema::hasTable('admin_access_logs') && Schema::hasColumn('admin_access_logs', 'accessed_at')) {
            $total += (int) DB::table('admin_access_logs')
                ->where('accessed_at', '>=', $since)
                ->count();
        }

        // Fallback for environments where request-level logs are not enabled.
        if ($total === 0 && Schema::hasTable('audit_logs') && Schema::hasColumn('audit_logs', 'created_at')) {
            $total = (int) DB::table('audit_logs')
                ->where('created_at', '>=', $since)
                ->count();
        }

        return $total;
    }

    protected function getSecurityAlertsCount(string $since): int
    {
        if (Schema::hasTable('security_events')) {
            $query = DB::table('security_events')
                ->where('created_at', '>=', $since);

            if (Schema::hasColumn('security_events', 'severity')) {
                $query->whereIn('severity', ['high', 'critical']);
            } else {
                $query->whereIn('event_type', [
                    'failed_login',
                    'blocked_request',
                    'request_blocked',
                    'rate_limited',
                    'sql_injection_attempt',
                    'xss_attempt',
                    'brute_force_detected',
                    'unauthorized_access',
                ]);
            }

            if (Schema::hasColumn('security_events', 'acknowledged')) {
                $query->where('acknowledged', false);
            }

            return (int) $query->count();
        }

        if (Schema::hasTable('admin_access_logs') && Schema::hasColumn('admin_access_logs', 'response_status')) {
            return (int) DB::table('admin_access_logs')
                ->where('accessed_at', '>=', $since)
                ->where('response_status', '>=', 500)
                ->count();
        }

        return 0;
    }

    protected function getActiveSessionsCount(): int
    {
        $active = 0;

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'last_login_at')) {
            $query = DB::table('users')
                ->where('last_login_at', '>=', now()->subMinutes(30));

            if (Schema::hasColumn('users', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            $active = (int) $query->count();
        }

        if (Schema::hasTable('admin_access_logs')
            && Schema::hasColumn('admin_access_logs', 'user_id')
            && Schema::hasColumn('admin_access_logs', 'accessed_at')) {
            $adminActive = (int) DB::table('admin_access_logs')
                ->whereNotNull('user_id')
                ->where('accessed_at', '>=', now()->subMinutes(30))
                ->distinct()
                ->count('user_id');

            $active = max($active, $adminActive);
        }

        return $active;
    }

    protected function calculateUptimePercentage(string $since): float
    {
        if (Schema::hasTable('admin_access_logs')
            && Schema::hasColumn('admin_access_logs', 'response_status')
            && Schema::hasColumn('admin_access_logs', 'accessed_at')) {
            $total = (int) DB::table('admin_access_logs')
                ->where('accessed_at', '>=', $since)
                ->count();

            if ($total > 0) {
                $serverErrors = (int) DB::table('admin_access_logs')
                    ->where('accessed_at', '>=', $since)
                    ->where('response_status', '>=', 500)
                    ->count();

                $uptime = (($total - $serverErrors) / $total) * 100;
                return round(max(0, min(100, $uptime)), 2);
            }
        }

        return 99.9;
    }

    protected function resolveRateLimitTimeColumn(): string
    {
        if (Schema::hasColumn('rate_limit_logs', 'updated_at')) {
            return 'updated_at';
        }

        if (Schema::hasColumn('rate_limit_logs', 'window_start')) {
            return 'window_start';
        }

        return 'created_at';
    }

    protected function normalizePath(string $path): string
    {
        $normalized = '/' . ltrim(trim($path), '/');
        return $normalized === '/' ? '/unknown' : $normalized;
    }

    protected function parseRateLimitEndpoint(string $endpoint): array
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return ['GET', '/unknown'];
        }

        if (str_contains($endpoint, ':')) {
            [$method, $path] = explode(':', $endpoint, 2);
            $method = strtoupper(trim($method)) ?: 'GET';
            return [$method, $this->normalizePath($path)];
        }

        return ['GET', $this->normalizePath($endpoint)];
    }

    protected function extractIpFromIdentifier(?string $identifier): ?string
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        if (str_starts_with($identifier, 'ip:')) {
            return substr($identifier, 3);
        }

        if (filter_var($identifier, FILTER_VALIDATE_IP)) {
            return $identifier;
        }

        return null;
    }

    protected function normalizeSeverity(string $severity): string
    {
        return match (strtolower(trim($severity))) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            'low' => 'low',
            'info' => 'low',
            default => 'medium',
        };
    }

    protected function statusFromSecurityEvent(string $eventType, string $severity): int
    {
        $eventType = strtolower($eventType);
        $severity = strtolower($severity);

        if ($eventType === 'failed_login') {
            return 401;
        }

        if (in_array($eventType, ['blocked_request', 'request_blocked', 'ip_blocked', 'rate_limited'], true)) {
            return 429;
        }

        if (in_array($eventType, ['unauthorized_access', 'sql_injection_attempt', 'xss_attempt', 'brute_force_detected'], true)) {
            return 403;
        }

        return match ($severity) {
            'critical' => 500,
            'high' => 403,
            'medium' => 400,
            default => 200,
        };
    }

    protected function activityTypeFromSecurityEvent(string $eventType, int $status): string
    {
        $eventType = strtolower($eventType);

        if (str_contains($eventType, 'login')) {
            return 'login';
        }

        if (str_contains($eventType, 'block')
            || str_contains($eventType, 'rate')
            || str_contains($eventType, 'unauthorized')
            || str_contains($eventType, 'sql')
            || str_contains($eventType, 'xss')
            || str_contains($eventType, 'brute')) {
            return 'block';
        }

        if ($status >= 400) {
            return 'error';
        }

        return 'request';
    }

    protected function toIso8601String(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        try {
            return now()->parse((string) $value)->toIso8601String();
        } catch (\Throwable $e) {
            return is_string($value) ? $value : null;
        }
    }

    protected function getBlockedRequestsCount(string $since): int
    {
        if (!Schema::hasTable('security_events')) {
            return 0;
        }
        return DB::table('security_events')
            ->whereIn('event_type', [
                'request_blocked',
                'blocked_request',
                'ip_blocked',
            ])
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

        $timeColumn = $this->resolveRateLimitTimeColumn();

        return DB::table('rate_limit_logs')
            ->where('blocked', true)
            ->where($timeColumn, '>=', $since)
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
        if (Schema::hasTable('blocked_ips')) {
            $query = DB::table('blocked_ips');

            if (Schema::hasColumn('blocked_ips', 'status')) {
                $query->where('status', 'active');
            }

            if (Schema::hasColumn('blocked_ips', 'expires_at')) {
                $query->where(function ($q) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
                });
            }

            return (int) $query->count();
        }

        if (Schema::hasTable('blacklists')) {
            return (int) DB::table('blacklists')
                ->where('type', 'ip')
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
                })
                ->count();
        }

        return 0;
    }

    protected function getAdminAccessCount(string $since): int
    {
        if (!Schema::hasTable('admin_access_logs') || !Schema::hasColumn('admin_access_logs', 'accessed_at')) {
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
