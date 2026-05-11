<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceCorsHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $forceCorsHeaders = filter_var(env('FORCE_CORS_HEADERS', false), FILTER_VALIDATE_BOOLEAN);

        if (!$forceCorsHeaders || !$this->isApiRequest($request)) {
            return $next($request);
        }

        $origin = (string) $request->headers->get('Origin', '');

        if ($origin === '') {
            return $next($request);
        }

        $allowAllOrigins = filter_var(env('CORS_ALLOW_ALL_ORIGINS', false), FILTER_VALIDATE_BOOLEAN);
        $supportsCredentials = filter_var(env('CORS_SUPPORTS_CREDENTIALS', !$allowAllOrigins), FILTER_VALIDATE_BOOLEAN);
        $allowedOrigins = $this->resolveAllowedOrigins();

        $originAllowed = $allowAllOrigins || in_array($origin, $allowedOrigins, true);

        if (!$originAllowed) {
            return $next($request);
        }

        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
            $this->applyCorsHeaders($response, $origin, $allowAllOrigins, $supportsCredentials, $request);
            return $response;
        }

        $response = $next($request);

        if (!$response->headers->has('Access-Control-Allow-Origin')) {
            $this->applyCorsHeaders($response, $origin, $allowAllOrigins, $supportsCredentials, $request);
        }

        return $response;
    }

    private function isApiRequest(Request $request): bool
    {
        return str_starts_with($request->path(), 'api/');
    }

    private function resolveAllowedOrigins(): array
    {
        $frontendUrl = (string) env('FRONTEND_URL', 'http://localhost:3000');
        $allowLocalOrigins = filter_var(
            env('CORS_ALLOW_LOCAL_ORIGINS', env('APP_ENV', 'production') !== 'production'),
            FILTER_VALIDATE_BOOLEAN
        );

        $extraAllowedOrigins = array_filter(array_map(
            static fn ($origin) => trim((string) $origin),
            explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
        ));

        return array_values(array_unique(array_filter([
            $frontendUrl,
            'https://vectorpagamentos.com',
            'https://app.vectorpagamentos.com',
            'https://api.vectorpagamentos.com',
            ...$extraAllowedOrigins,
            ...($allowLocalOrigins ? [
                'http://localhost:3000',
                'http://localhost:3001',
                'http://localhost:5173',
                'http://127.0.0.1:3000',
                'http://127.0.0.1:5173',
            ] : []),
        ])));
    }

    private function applyCorsHeaders(Response $response, string $origin, bool $allowAllOrigins, bool $supportsCredentials, Request $request): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $allowAllOrigins ? '*' : $origin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');

        $requestedHeaders = (string) $request->headers->get('Access-Control-Request-Headers', '*');
        $response->headers->set('Access-Control-Allow-Headers', $requestedHeaders !== '' ? $requestedHeaders : '*');
        $response->headers->set('Access-Control-Max-Age', '86400');

        if ($supportsCredentials && !$allowAllOrigins) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        $response->headers->set('Vary', 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers');
    }
}
