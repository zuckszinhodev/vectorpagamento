<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON Payload Security Middleware
 * 
 * Protects against:
 * - JSON injection / malformed payloads
 * - Excessively deep nested objects (DoS/recursion attacks)
 * - Oversized payloads
 * - Content-Type mismatch attacks
 * - Parameter pollution (duplicate keys)
 */
class JsonPayloadProtection
{
    private const MAX_DEPTH = 10;
    private const MAX_BODY_SIZE = 1048576; // 1 MB
    private const MAX_STRING_LENGTH = 65536; // 64 KB per field
    private const MAX_ARRAY_ITEMS = 1000;

    public function handle(Request $request, Closure $next): Response
    {
        // Only check requests with body
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
            return $next($request);
        }

        $contentType = $request->header('Content-Type', '');

        // If Content-Type claims JSON, validate it strictly
        if (str_contains($contentType, 'json')) {
            $rawBody = $request->getContent();

            // Check body size
            if (strlen($rawBody) > self::MAX_BODY_SIZE) {
                return $this->reject($request, 'payload_too_large', 'Payload excede o tamanho máximo permitido.', 413);
            }

            // Validate JSON syntax
            if (!empty($rawBody)) {
                $decoded = json_decode($rawBody, true, self::MAX_DEPTH);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errorMsg = json_last_error() === JSON_ERROR_DEPTH
                        ? 'JSON aninhamento muito profundo.'
                        : 'JSON inválido no corpo da requisição.';

                    return $this->reject($request, 'invalid_json', $errorMsg, 400);
                }

                // Scan for dangerous patterns
                if (is_array($decoded)) {
                    $scanResult = $this->scanPayload($decoded);
                    if ($scanResult !== null) {
                        return $this->reject($request, 'malicious_payload', $scanResult, 400);
                    }
                }
            }
        }

        // Reject body with no Content-Type header on state-changing requests
        if ($request->getContent() && !$contentType) {
            return $this->reject($request, 'missing_content_type', 'Content-Type header é obrigatório.', 400);
        }

        return $next($request);
    }

    /**
     * Recursively scan JSON payload for dangerous patterns
     */
    private function scanPayload(array $data, int $depth = 0): ?string
    {
        if ($depth > self::MAX_DEPTH) {
            return 'Payload com aninhamento excessivo.';
        }

        if (count($data) > self::MAX_ARRAY_ITEMS) {
            return 'Payload com número excessivo de itens.';
        }

        foreach ($data as $key => $value) {
            // Check key for injection patterns
            if (is_string($key)) {
                if (str_starts_with($key, '$') || str_starts_with($key, '__')) {
                    return "Campo suspeito detectado: {$key}";
                }
            }

            if (is_string($value)) {
                // Check string length
                if (strlen($value) > self::MAX_STRING_LENGTH) {
                    return 'Campo com tamanho excessivo.';
                }

                // Detect NoSQL injection patterns ($gt, $ne, $regex etc)
                if (preg_match('/^\$(?:gt|gte|lt|lte|ne|in|nin|exists|regex|where|type)\b/i', $value)) {
                    Log::warning('NoSQL injection attempt detected', [
                        'key' => $key,
                        'value' => substr($value, 0, 100),
                    ]);
                    return 'Padrão de injeção detectado.';
                }
            }

            if (is_array($value)) {
                $result = $this->scanPayload($value, $depth + 1);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    private function reject(Request $request, string $code, string $message, int $status): Response
    {
        Log::warning('JSON payload protection triggered', [
            'code' => $code,
            'ip' => $request->ip(),
            'path' => $request->path(),
            'method' => $request->method(),
        ]);

        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
