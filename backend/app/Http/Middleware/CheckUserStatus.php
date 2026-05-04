<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that checks if the authenticated user's account is still active.
 * If the user has been blocked or suspended by an admin, all API requests
 * are rejected immediately with a 403 response.
 */
class CheckUserStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = $request->user();
        } catch (\Throwable $e) {
            $user = null;
        }

        if ($user && in_array($user->status, ['blocked', 'suspended'])) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'account_blocked',
                    'message' => 'Sua conta foi bloqueada pela administração.',
                    'block_reason' => $user->block_reason,
                ],
            ], 403);
        }

        return $next($request);
    }
}
