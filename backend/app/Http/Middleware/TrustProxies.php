<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * SECURITY FIX: Proxy trust configuration
 * 
 * WARNING: Never use '*' for proxies in production!
 * This would allow any client to spoof X-Forwarded-* headers,
 * bypassing IP-based security controls and rate limiting.
 * 
 * Configure TRUSTED_PROXIES in .env with your actual proxy IPs:
 * - Load balancer IPs
 * - CDN/Cloudflare IPs (use official IP ranges)
 * - Reverse proxy IPs
 */
class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     * 
     * Set via TRUSTED_PROXIES environment variable.
     * Format: comma-separated IPs or CIDR ranges.
     * Example: "10.0.0.0/8,172.16.0.0/12,192.168.0.0/16"
     * 
     * For Cloudflare, add their IP ranges:
     * https://www.cloudflare.com/ips/
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * Bootstrap the proxy configuration.
     */
    public function __construct()
    {
        // Only trust explicitly configured proxies
        $trustedProxies = config('app.trusted_proxies', '');
        
        if (!empty($trustedProxies)) {
            $this->proxies = array_map('trim', explode(',', $trustedProxies));
        } else {
            // In production with no config, don't trust any proxy headers
            // This is secure by default
            $this->proxies = [];
        }
    }

    /**
     * The headers that should be used to detect proxies.
     * Only trust standard X-Forwarded headers.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO;
}
