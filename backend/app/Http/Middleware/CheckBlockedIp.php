<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to check if an IP is blocked.
 * Blocks requests from banned IPs before they reach the application.
 */
class CheckBlockedIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        // Check cache first for performance
        if (Cache::get("blocked_ip:{$ip}")) {
            $this->logBlockedRequest($request);
            return $this->blockedResponse($ip);
        }

        // Only check database if table exists (avoid errors before migration)
        try {
            if (Schema::hasTable('blocked_ips')) {
                $blocked = DB::table('blocked_ips')
                    ->where('ip_address', $ip)
                    ->where('status', 'active')
                    ->where(function ($query) {
                        $query->whereNull('expires_at')
                              ->orWhere('expires_at', '>', now());
                    })
                    ->first();

                if ($blocked) {
                    // Refresh cache
                    $ttl = $blocked->expires_at 
                        ? now()->diffInSeconds($blocked->expires_at) 
                        : 86400 * 365; // 1 year for permanent blocks
                    
                    Cache::put("blocked_ip:{$ip}", true, $ttl);
                    
                    $this->logBlockedRequest($request, $blocked->reason);
                    return $this->blockedResponse($ip);
                }
            }
        } catch (\Exception $e) {
            // Don't break the app if there's a DB issue, just log it
            Log::error('CheckBlockedIp middleware error', ['error' => $e->getMessage()]);
        }

        return $next($request);
    }

    protected function blockedResponse(string $ip): Response
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'ip_blocked',
                'message' => 'Acesso negado. Seu IP foi bloqueado por atividade suspeita.',
            ],
        ], 403);
    }

    protected function logBlockedRequest(Request $request, ?string $reason = null): void
    {
        try {
            if (Schema::hasTable('security_events')) {
                DB::table('security_events')->insert([
                    'id' => \Illuminate\Support\Str::uuid()->toString(),
                    'event_type' => 'blocked_request',
                    'severity' => 'medium',
                    'ip_address' => $request->ip(),
                    'user_agent' => mb_substr($request->userAgent() ?? '', 0, 500),
                    'endpoint' => $request->path(),
                    'method' => $request->method(),
                    'description' => 'Requisição bloqueada de IP banido' . ($reason ? ": {$reason}" : ''),
                    'metadata' => json_encode([
                        'blocked_reason' => $reason,
                        'requested_url' => $request->fullUrl(),
                    ]),
                    'acknowledged' => false,
                    'created_at' => now(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to log blocked request', ['error' => $e->getMessage()]);
        }
    }
}
