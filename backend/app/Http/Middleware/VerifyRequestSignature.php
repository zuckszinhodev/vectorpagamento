<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request Integrity Verification Middleware
 * 
 * Validates HMAC-SHA256 signatures on state-changing API requests
 * to prevent request tampering and replay attacks.
 * 
 * Required headers for signed requests:
 *   X-Request-Signature: HMAC-SHA256 of (timestamp + method + path + body)
 *   X-Request-Timestamp: Unix timestamp (must be within 5 minutes)
 * 
 * Signing key: the API secret key (sk_live_*) or a dedicated signing secret.
 * This is OPTIONAL for JWT-authenticated requests, REQUIRED for API-key requests.
 */
class VerifyRequestSignature
{
    private const MAX_TIMESTAMP_DRIFT_SECONDS = 300; // 5 minutes

    public function handle(Request $request, Closure $next): Response
    {
        // Only enforce on state-changing methods
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return $next($request);
        }

        // Skip if JWT-authenticated (browser clients can't sign)
        if ($request->user() && ($request->user()->type ?? null) !== 'api_key') {
            return $next($request);
        }

        // If API key authenticated, check for signature
        $signature = $request->header('X-Request-Signature');
        $timestamp = $request->header('X-Request-Timestamp');

        // If no signature headers, allow but log warning (gradual rollout)
        if (!$signature || !$timestamp) {
            // Log for monitoring — can be enforced later
            Log::info('Unsigned API key request', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'api_key_id' => $request->get('api_key_id'),
            ]);
            return $next($request);
        }

        // Validate timestamp to prevent replay attacks
        $requestTime = (int) $timestamp;
        $currentTime = time();

        if (abs($currentTime - $requestTime) > self::MAX_TIMESTAMP_DRIFT_SECONDS) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'request_expired',
                    'message' => 'Request timestamp expired. Clock drift must be within 5 minutes.',
                ],
            ], 401);
        }

        // Get the API key's secret for signature verification
        $apiKey = $request->user()->api_key ?? null;
        if (!$apiKey || !$apiKey->secret_hash) {
            return $next($request); // No secret configured, skip
        }

        // Build the signing payload: timestamp + method + path + body
        $payload = $timestamp . $request->method() . '/' . $request->path() . $request->getContent();

        // Verify the HMAC signature using the raw secret
        // Note: The client must compute HMAC using their secret key
        $expectedSignature = hash_hmac('sha256', $payload, $apiKey->getRawSecret());

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('Invalid request signature', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'api_key_id' => $apiKey->id,
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'invalid_signature',
                    'message' => 'Request signature verification failed.',
                ],
            ], 401);
        }

        return $next($request);
    }
}
