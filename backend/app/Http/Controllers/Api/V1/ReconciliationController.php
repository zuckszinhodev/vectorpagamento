<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Reconciliation;
use App\Services\ReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function __construct(
        private ReconciliationService $service,
    ) {}

    /**
     * Listar reconciliações com filtros
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Reconciliation::orderBy('created_at', 'desc');

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            if ($request->filled('account_id')) {
                $query->where('account_id', $request->account_id);
            }

            if ($request->filled('date_from')) {
                $query->where('period_start', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->where('period_end', '<=', $request->date_to);
            }

            $perPage = min((int) $request->input('per_page', 20), 100);
            $items = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $items->items(),
                'meta' => [
                    'current_page' => $items->currentPage(),
                    'per_page' => $items->perPage(),
                    'total' => $items->total(),
                    'last_page' => $items->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ReconciliationController::index error', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'data' => [], 'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]]);
        }
    }

    /**
     * Detalhes de uma reconciliação
     */
    public function show(Request $request, string $reconciliationId): JsonResponse
    {
        $user = $request->user();
        $query = Reconciliation::query();

        if ($user->role !== 'admin') {
            $query->where('account_id', $user->account_id);
        }

        $rec = $query->find($reconciliationId);

        if (!$rec) {
            return response()->json([
                'success' => false,
                'message' => 'Reconciliação não encontrada',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $rec,
        ]);
    }

    /**
     * Iniciar reconciliação manual
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'account_id' => ['nullable', 'uuid'],
        ]);

        try {
            $user = $request->user();

            $reconciliation = $this->service->reconcile(
                Reconciliation::TYPE_MANUAL,
                new \DateTime($validated['period_start']),
                new \DateTime($validated['period_end']),
                $validated['account_id'] ?? null,
                $user->id ?? null,
            );

            return response()->json([
                'success' => true,
                'data' => $reconciliation,
                'message' => 'Reconciliação executada',
            ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ReconciliationController::store error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao executar reconciliação'], 500);
        }
    }

    /**
     * Dashboard / resumo de reconciliações
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $period = $request->input('period', '30d');
            $daysMap = ['7d' => 7, '30d' => 30, '90d' => 90];
            $days = $daysMap[$period] ?? 30;
            $from = now()->subDays($days)->startOfDay();

            $recs = Reconciliation::where('created_at', '>=', $from)
                ->where('status', 'completed')
                ->get();

            $latest = Reconciliation::where('status', 'completed')
                ->orderBy('reconciled_at', 'desc')
                ->first();

            $withDiscrepancies = $recs->filter(fn($r) => $r->hasDiscrepancies())->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_reconciliations' => $recs->count(),
                    'with_discrepancies' => $withDiscrepancies,
                    'total_revenue' => $recs->sum('total_amount'),
                    'total_fees' => $recs->sum('total_fees'),
                    'total_refunds' => $recs->sum('total_refunds'),
                    'total_chargebacks' => $recs->sum('total_chargebacks'),
                    'latest' => $latest,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ReconciliationController::dashboard error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => true,
                'data' => [
                    'total_reconciliations' => 0, 'with_discrepancies' => 0,
                    'total_revenue' => 0, 'total_fees' => 0,
                    'total_refunds' => 0, 'total_chargebacks' => 0,
                    'latest' => null,
                ],
            ]);
        }
    }
}
