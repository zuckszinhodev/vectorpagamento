<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * SECURITY FIX: Enhanced exception handler
 * 
 * - Never expose stack traces in production
 * - Use generic error messages to prevent information disclosure
 * - Log detailed errors server-side for debugging
 * - Sanitize error responses to prevent sensitive data leakage
 */
class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
        'secret',
        'api_key',
        'token',
        'credit_card',
        'card_number',
        'cvv',
        'cvc',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            if (app()->bound('sentry') && $this->shouldReport($e)) {
                app('sentry')->captureException($e);
            }
        });
    }

    /**
     * Render an exception into an HTTP response.
     * Always return JSON for API routes with secure error handling.
     */
    public function render($request, Throwable $e): JsonResponse|\Symfony\Component\HttpFoundation\Response
    {
        // Force JSON response for all API routes
        if ($request->is('api/*') || $request->is('v1/*') || $request->expectsJson()) {
            $request->headers->set('Accept', 'application/json');
            return $this->renderApiException($request, $e);
        }

        return parent::render($request, $e);
    }

    /**
     * Render API exceptions with security in mind.
     * Never expose internal details in production.
     */
    protected function renderApiException($request, Throwable $e): JsonResponse
    {
        // Get appropriate status code
        $statusCode = $this->getStatusCode($e);
        
        // Log the full exception for debugging (server-side only)
        if ($statusCode >= 500) {
            try {
                $userId = $request->user()?->id;
            } catch (\Throwable $authEx) {
                $userId = null;
            }
            Log::error('API Exception', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_id' => $userId,
            ]);
        }

        // Handle validation exceptions (safe to expose field errors)
        if ($e instanceof ValidationException) {
            return response()->json([
                'success' => false,
                'message' => 'Os dados enviados são inválidos.',
                'errors' => $e->errors(),
                'error' => [
                    'code' => 'validation_error',
                    'message' => 'Os dados enviados são inválidos.',
                    'errors' => $e->errors(),
                ],
            ], 422);
        }

        // Handle authentication exceptions
        if ($e instanceof AuthenticationException) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'Não autenticado.',
                ],
            ], 401);
        }

        // Handle model not found
        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Recurso não encontrado.',
                ],
            ], 404);
        }

        // Handle database query errors (missing table/column, connection issues)
        if ($e instanceof QueryException) {
            Log::error('Database QueryException', [
                'message' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
                'url' => $request->fullUrl(),
            ]);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'database_error',
                    'message' => 'Erro temporário no banco de dados. Tente novamente.',
                ],
            ], 500);
        }

        // Handle HTTP exceptions (403, 429, etc.)
        if ($e instanceof HttpException) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => $this->getErrorCode($statusCode),
                    'message' => $this->getSafeMessage($e, $statusCode),
                ],
            ], $statusCode);
        }

        // For all other exceptions in production, use generic message
        $isProduction = !config('app.debug');
        
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'internal_error',
                'message' => $isProduction 
                    ? 'Ocorreu um erro interno. Tente novamente mais tarde.'
                    : $e->getMessage(),
                // Only include debug info in non-production
                ...($isProduction ? [] : [
                    'debug' => [
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ],
                ]),
            ],
        ], $statusCode);
    }

    /**
     * Get HTTP status code from exception.
     */
    protected function getStatusCode(Throwable $e): int
    {
        if ($e instanceof HttpException) {
            return $e->getStatusCode();
        }

        if ($e instanceof AuthenticationException) {
            return 401;
        }

        if ($e instanceof ValidationException) {
            return 422;
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return 404;
        }

        return 500;
    }

    /**
     * Get error code string from status code.
     */
    protected function getErrorCode(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            422 => 'validation_error',
            429 => 'rate_limit_exceeded',
            500 => 'internal_error',
            502 => 'bad_gateway',
            503 => 'service_unavailable',
            default => 'error',
        };
    }

    /**
     * Get safe user-facing message.
     */
    protected function getSafeMessage(Throwable $e, int $statusCode): string
    {
        // Use generic messages for common status codes
        return match ($statusCode) {
            400 => 'Requisição inválida.',
            401 => 'Não autenticado.',
            403 => 'Acesso negado.',
            404 => 'Recurso não encontrado.',
            405 => 'Método não permitido.',
            422 => 'Os dados enviados são inválidos.',
            429 => 'Limite de requisições excedido. Tente novamente em alguns minutos.',
            500 => 'Ocorreu um erro interno. Tente novamente mais tarde.',
            502 => 'Serviço temporariamente indisponível.',
            503 => 'Serviço em manutenção. Tente novamente em alguns minutos.',
            default => $e instanceof HttpException && !config('app.debug')
                ? 'Ocorreu um erro. Tente novamente.'
                : $e->getMessage(),
        };
    }
}
