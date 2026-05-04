<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Get role from user's related Role model
        $userRole = $user->getRoleName();

        if (!in_array($userRole, $roles)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient role permissions'
            ], 403);
        }

        return $next($request);
    }
}
