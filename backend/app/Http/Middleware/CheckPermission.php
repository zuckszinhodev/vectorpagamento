<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Get permissions from user or API key (deny-by-default when no permissions set)
        $permissions = $user->permissions ?? [];

        // Check if has wildcard permission or specific permission
        if (in_array('*', $permissions)) {
            return $next($request);
        }

        // Normalize permission separators (support both dot and colon notation)
        // e.g., 'products.read' matches 'products:read' and vice-versa
        $permissionDot = str_replace(':', '.', $permission);
        $permissionColon = str_replace('.', ':', $permission);

        // Check for specific permission (both notations)
        if (in_array($permissionDot, $permissions) || in_array($permissionColon, $permissions)) {
            return $next($request);
        }

        // Check for category wildcard (e.g., 'products.*' or 'products:*')
        $categoryDot = explode('.', $permissionDot)[0] ?? '';
        $categoryColon = explode(':', $permissionColon)[0] ?? '';
        if (in_array($categoryDot . '.*', $permissions) || in_array($categoryColon . ':*', $permissions)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Insufficient permissions'
        ], 403);
    }
}
