<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SECURITY FIX: Enhanced security headers for all API responses.
 * 
 * Implements OWASP recommended security headers:
 * - Prevents MIME sniffing attacks
 * - Prevents clickjacking
 * - Enables XSS filtering
 * - Controls referrer information
 * - Enforces HTTPS (HSTS)
 * - Restricts browser features
 * - Implements Content Security Policy
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $isSecureRequest = $request->isSecure()
            || $request->header('X-Forwarded-Proto') === 'https'
            || $request->server('HTTPS') === 'on';

        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        
        // Prevent clickjacking
        $response->headers->set('X-Frame-Options', 'DENY');
        
        // Enable XSS filter (legacy browsers)
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        
        // Control referrer information
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Isolate browsing context for authenticated/API responses
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');

        // Enforce HTTPS with HSTS (1 year + preload ready)
        if ($isSecureRequest) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }
        
        // Restrict dangerous browser features
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        
        // Content Security Policy for API responses
        // Strict policy: only allow same-origin resources
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        
        // Prevent caching of sensitive data
        if ($this->isSensitiveEndpoint($request)) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        // Remove server identification headers (information disclosure)
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');
        
        // Remove PHP version if exposed
        if (function_exists('header_remove')) {
            @header_remove('X-Powered-By');
        }

        // Ensure CDN/proxies do not serve cached authenticated responses across users.
        $response->headers->set('Vary', 'Origin, Authorization', false);

        return $response;
    }
    
    /**
     * Check if the endpoint contains sensitive data that shouldn't be cached.
     */
    protected function isSensitiveEndpoint(Request $request): bool
    {
        $sensitivePaths = [
            'auth',
            'account',
            'users',
            'admin',
            'kyc',
            'withdrawals',
            'wallet',
            'payment-info',
            'api-keys',
        ];
        
        foreach ($sensitivePaths as $path) {
            if (str_contains($request->path(), $path)) {
                return true;
            }
        }
        
        return false;
    }
}
