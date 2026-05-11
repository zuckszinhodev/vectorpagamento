<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminPermission
{
    /**
     * Fallback permission map when middleware parameter is not provided.
     */
    private const ENDPOINT_PERMISSION_MAP = [
        '#^admin/stats#' => 'admin.dashboard.read',
        '#^admin/activities#' => 'admin.dashboard.read',
        '#^admin/chart#' => 'admin.dashboard.read',
        '#^admin/payment-methods#' => 'admin.dashboard.read',
        '#^admin/customers#' => 'admin.customers.manage',
        '#^admin/withdrawals#' => 'admin.withdrawals.manage',
        '#^admin/charges#' => 'admin.dashboard.read',
        '#^admin/kyc#' => 'admin.kyc.review',
        '#^admin/acquirers#' => 'admin.acquirers.manage',
        '#^admin/system-config#' => 'admin.config.manage',
        '#^admin/settings#' => 'admin.config.manage',
        '#^admin/api-keys#' => 'admin.config.manage',
        '#^admin/webhooks#' => 'admin.webhooks.manage',
        '#^admin/notifications#' => 'admin.notifications.manage',
        '#^admin/fraud#' => 'admin.fraud.manage',
        '#^admin/chargebacks#' => 'admin.chargebacks.manage',
        '#^admin/reconciliation#' => 'admin.reconciliation.manage',
        '#^admin/security#' => 'admin.security.monitor',
        '#^admin/products#' => 'admin.showcase.manage',
        '#^admin/showcase#' => 'admin.showcase.manage',
        '#^users#' => 'admin.users.manage',
    ];

    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = $request->user();

        if (!$user || !method_exists($user, 'getRoleName')) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'Acesso negado.',
                ],
            ], 401);
        }

        $role = (string) $user->getRoleName();
        if (!in_array($role, ['owner', 'admin', 'support', 'financial', 'antifraud'], true)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'forbidden_role',
                    'message' => 'Perfil sem acesso administrativo.',
                ],
            ], 403);
        }

        if ($role === 'owner') {
            return $next($request);
        }

        $requiredPermission = $permission ?: $this->resolvePermissionFromPath($request);

        if ($requiredPermission === null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'permission_not_mapped',
                    'message' => 'Permissao administrativa nao mapeada para este endpoint.',
                ],
            ], 403);
        }

        if (!method_exists($user, 'hasPermission') || !$user->hasPermission($requiredPermission)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'insufficient_permissions',
                    'message' => 'Permissao insuficiente para esta acao.',
                    'required_permission' => $requiredPermission,
                ],
            ], 403);
        }

        return $next($request);
    }

    private function resolvePermissionFromPath(Request $request): ?string
    {
        $path = preg_replace('#^api/v1/#', '', trim($request->path(), '/'));

        foreach (self::ENDPOINT_PERMISSION_MAP as $pattern => $permission) {
            if (preg_match($pattern, $path)) {
                return $permission;
            }
        }

        return null;
    }
}
