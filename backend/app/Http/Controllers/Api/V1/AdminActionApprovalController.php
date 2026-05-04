<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AdminActionApproval;
use App\Services\AdminApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminActionApprovalController extends Controller
{
    public function __construct(private readonly AdminApprovalService $approvalService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'pending');
        $perPage = min((int) $request->query('per_page', 20), 100);

        $query = AdminActionApproval::query()->orderByDesc('requested_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($request->filled('action_key')) {
            $query->where('action_key', $request->query('action_key'));
        }

        $approvals = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $approvals->items(),
            'meta' => [
                'current_page' => $approvals->currentPage(),
                'per_page' => $approvals->perPage(),
                'total' => $approvals->total(),
                'last_page' => $approvals->lastPage(),
            ],
        ]);
    }

    public function approve(Request $request, string $approvalId): JsonResponse
    {
        $reviewReason = $request->input('review_reason');
        $approval = $this->approvalService->approve($request, $approvalId, $reviewReason);

        if (!$approval) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'approval_not_allowed',
                    'message' => 'Aprovacao nao encontrada, expirada ou invalida para este revisor.',
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $approval,
            'message' => 'Acao aprovada com sucesso.',
        ]);
    }

    public function reject(Request $request, string $approvalId): JsonResponse
    {
        $reviewReason = $request->input('review_reason');
        $approval = $this->approvalService->reject($request, $approvalId, $reviewReason);

        if (!$approval) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'approval_not_allowed',
                    'message' => 'Aprovacao nao encontrada, expirada ou invalida para este revisor.',
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $approval,
            'message' => 'Acao rejeitada com sucesso.',
        ]);
    }
}
