<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureMfaEnabled
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || !($user->mfa_enabled ?? false)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'mfa_required',
                    'message' => 'A autenticação de dois fatores é exigida para acessar esta área.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
