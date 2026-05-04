<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SecurityLogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * SECURITY FIX: Strict rate limiting for authentication endpoints.
 * 
 * Prevents brute force attacks on login, registration, and password reset.
 * Uses IP-based and email-based rate limiting with progressive delays.
 */
class StrictAuthRateLimiter
{
    /**
     * Rate limit configuration
     */
    private const MAX_ATTEMPTS_PER_IP = 5;           // Max attempts per IP
    private const MAX_ATTEMPTS_PER_EMAIL = 5;        // Max attempts per email
    private const DECAY_MINUTES = 15;                // Block duration in minutes
    private const PROGRESSIVE_DELAY_ENABLED = true;  // Enable progressive delays

    public function __construct(
        protected SecurityLogService $securityLog
    ) {}

    public function handle(Request $request, Closure $next, string $action = 'auth'): Response
    {
        $ip = $request->ip();
        $email = $this->extractEmail($request);
        
        // Check IP-based rate limit
        $ipKey = $this->getIpKey($ip, $action);
        $ipAttempts = (int) Cache::get($ipKey, 0);
        
        if ($ipAttempts >= self::MAX_ATTEMPTS_PER_IP) {
            $this->logRateLimitExceeded($request, 'ip', $ip, $action);
            return $this->rateLimitResponse($ipKey, 'ip');
        }
        
        // Check email-based rate limit (if email is provided)
        if ($email) {
            $emailKey = $this->getEmailKey($email, $action);
            $emailAttempts = (int) Cache::get($emailKey, 0);
            
            if ($emailAttempts >= self::MAX_ATTEMPTS_PER_EMAIL) {
                $this->logRateLimitExceeded($request, 'email', $email, $action);
                return $this->rateLimitResponse($emailKey, 'email');
            }
        }
        
        // Apply progressive delay for suspicious activity
        if (self::PROGRESSIVE_DELAY_ENABLED && $ipAttempts > 2) {
            $delay = min($ipAttempts * 500, 3000); // Max 3 second delay
            usleep($delay * 1000);
        }
        
        // Execute the request
        $response = $next($request);
        
        // Increment counters on failed authentication (4xx responses)
        if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500) {
            $this->incrementAttempts($ipKey);
            
            // Log failed login attempt
            if ($action === 'login' && $email) {
                $this->securityLog->logFailedLogin($request, $email);
            }
            
            if ($email) {
                $emailKey = $this->getEmailKey($email, $action);
                $this->incrementAttempts($emailKey);
            }
        } else {
            // Reset on successful authentication
            Cache::forget($ipKey);
            if ($email) {
                Cache::forget($this->getEmailKey($email, $action));
            }
        }
        
        // Add rate limit headers to response
        $remaining = max(0, self::MAX_ATTEMPTS_PER_IP - $ipAttempts - 1);
        $response->headers->set('X-RateLimit-Limit', (string) self::MAX_ATTEMPTS_PER_IP);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
        
        return $response;
    }

    /**
     * Extract email from request (for login, register, forgot-password).
     */
    protected function extractEmail(Request $request): ?string
    {
        $email = $request->input('email');
        
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        
        return strtolower(trim($email));
    }

    /**
     * Generate cache key for IP-based rate limiting.
     */
    protected function getIpKey(string $ip, string $action): string
    {
        return "rate_limit:auth:{$action}:ip:" . md5($ip);
    }

    /**
     * Generate cache key for email-based rate limiting.
     */
    protected function getEmailKey(string $email, string $action): string
    {
        return "rate_limit:auth:{$action}:email:" . md5(strtolower($email));
    }

    /**
     * Increment attempt counter with expiration.
     */
    protected function incrementAttempts(string $key): void
    {
        $attempts = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $attempts, now()->addMinutes(self::DECAY_MINUTES));
    }

    /**
     * Generate rate limit exceeded response.
     */
    protected function rateLimitResponse(string $key, string $type): Response
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'rate_limit_exceeded',
                'message' => 'Muitas tentativas. Por favor, aguarde alguns minutos antes de tentar novamente.',
                'retry_after' => self::DECAY_MINUTES * 60,
            ],
        ], 429)->withHeaders([
            'X-RateLimit-Limit' => self::MAX_ATTEMPTS_PER_IP,
            'X-RateLimit-Remaining' => 0,
            'Retry-After' => self::DECAY_MINUTES * 60,
        ]);
    }

    /**
     * Log rate limit exceeded event for security monitoring.
     */
    protected function logRateLimitExceeded(Request $request, string $type, string $identifier, string $action): void
    {
        // Log to security events
        $this->securityLog->logRateLimited($request, "{$action}:{$type}");
        
        Log::warning('Authentication rate limit exceeded', [
            'type' => $type,
            'identifier_hash' => md5($identifier), // Don't log actual IP/email
            'action' => $action,
            'path' => $request->path(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
