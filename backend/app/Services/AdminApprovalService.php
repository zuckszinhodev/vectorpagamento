<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminActionApproval;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminApprovalService
{
    /**
     * Resolve an already-approved request by ID and consume it once used.
     */
    public function consumeApprovedRequest(Request $request, string $approvalId, string $actionKey): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        $approval = AdminActionApproval::where('id', $approvalId)
            ->where('action_key', $actionKey)
            ->where('requester_user_id', $user->id)
            ->first();

        if (!$approval) {
            return false;
        }

        if ($approval->status !== 'approved') {
            return false;
        }

        if ($approval->expires_at && $approval->expires_at->isPast()) {
            $approval->update(['status' => 'expired']);
            return false;
        }

        $approval->update([
            'status' => 'consumed',
            'consumed_at' => now(),
        ]);

        return true;
    }

    /**
     * Require approval for sensitive action; creates pending request if not approved yet.
     */
    public function requireApproval(Request $request, array $context): array
    {
        $user = $request->user();

        if (!$user) {
            return [
                'approved' => false,
                'http_status' => 401,
                'response' => [
                    'success' => false,
                    'error' => [
                        'code' => 'unauthenticated',
                        'message' => 'Usuario nao autenticado.',
                    ],
                ],
            ];
        }

        $actionKey = (string) ($context['action_key'] ?? 'admin.critical_action');
        $resourceType = (string) ($context['resource_type'] ?? 'generic');
        $resourceId = isset($context['resource_id']) ? (string) $context['resource_id'] : null;
        $payload = $context['payload'] ?? [];
        $requestReason = isset($context['request_reason']) ? (string) $context['request_reason'] : null;
        $expiresMinutes = (int) ($context['expires_minutes'] ?? 30);

        $approvalId = (string) ($request->header('X-Admin-Approval-Id') ?: $request->input('approval_id', ''));
        if ($approvalId !== '' && $this->consumeApprovedRequest($request, $approvalId, $actionKey)) {
            return ['approved' => true];
        }

        $pending = AdminActionApproval::where('requester_user_id', $user->id)
            ->where('action_key', $actionKey)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('requested_at')
            ->first();

        if (!$pending) {
            $pending = AdminActionApproval::create([
                'id' => Str::uuid()->toString(),
                'account_id' => $user->account_id,
                'requester_user_id' => $user->id,
                'action_key' => $actionKey,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'status' => 'pending',
                'payload' => $payload,
                'request_reason' => $requestReason,
                'requested_at' => now(),
                'expires_at' => now()->addMinutes($expiresMinutes),
            ]);

            $this->writeAudit($request, 'admin_approval_requested', $resourceType, $resourceId, [
                'approval_id' => $pending->id,
                'action_key' => $actionKey,
                'payload' => $payload,
            ]);
        }

        return [
            'approved' => false,
            'http_status' => 202,
            'response' => [
                'success' => false,
                'error' => [
                    'code' => 'dual_control_required',
                    'message' => 'Acao sensivel requer aprovacao de outro administrador.',
                    'approval_id' => $pending->id,
                    'action_key' => $actionKey,
                    'status' => $pending->status,
                    'expires_at' => optional($pending->expires_at)->toIso8601String(),
                ],
            ],
        ];
    }

    public function approve(Request $request, string $approvalId, ?string $reviewReason = null): ?AdminActionApproval
    {
        $reviewer = $request->user();
        if (!$reviewer) {
            return null;
        }

        $approval = AdminActionApproval::where('id', $approvalId)
            ->where('status', 'pending')
            ->first();

        if (!$approval) {
            return null;
        }

        if ($approval->requester_user_id === $reviewer->id) {
            return null;
        }

        if ($approval->expires_at && $approval->expires_at->isPast()) {
            $approval->update(['status' => 'expired']);
            return null;
        }

        $approval->update([
            'status' => 'approved',
            'reviewer_user_id' => $reviewer->id,
            'review_reason' => $reviewReason,
            'reviewed_at' => now(),
        ]);

        $this->writeAudit($request, 'admin_approval_approved', $approval->resource_type, $approval->resource_id, [
            'approval_id' => $approval->id,
            'action_key' => $approval->action_key,
        ]);

        return $approval->fresh();
    }

    public function reject(Request $request, string $approvalId, ?string $reviewReason = null): ?AdminActionApproval
    {
        $reviewer = $request->user();
        if (!$reviewer) {
            return null;
        }

        $approval = AdminActionApproval::where('id', $approvalId)
            ->where('status', 'pending')
            ->first();

        if (!$approval) {
            return null;
        }

        if ($approval->requester_user_id === $reviewer->id) {
            return null;
        }

        $approval->update([
            'status' => 'rejected',
            'reviewer_user_id' => $reviewer->id,
            'review_reason' => $reviewReason,
            'reviewed_at' => now(),
        ]);

        $this->writeAudit($request, 'admin_approval_rejected', $approval->resource_type, $approval->resource_id, [
            'approval_id' => $approval->id,
            'action_key' => $approval->action_key,
        ]);

        return $approval->fresh();
    }

    private function writeAudit(Request $request, string $action, string $entityType, ?string $entityId, array $metadata = []): void
    {
        try {
            AuditLog::create([
                'id' => Str::uuid()->toString(),
                'user_id' => $request->user()?->id,
                'account_id' => $request->user()?->account_id,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => $metadata,
            ]);
        } catch (\Throwable) {
            // noop
        }
    }
}
