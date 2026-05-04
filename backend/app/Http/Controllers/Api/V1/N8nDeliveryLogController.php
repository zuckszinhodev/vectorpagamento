<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\N8nDeliveryLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class N8nDeliveryLogController extends Controller
{
    /**
     * Listar delivery logs com filtros
     */
    public function index(Request $request): JsonResponse
    {
        $query = N8nDeliveryLog::with('integration:id,name,webhook_url')
            ->orderBy('created_at', 'desc');

        if ($request->filled('integration_id')) {
            $query->forIntegration($request->integration_id);
        }

        if ($request->filled('event')) {
            $query->where('event', $request->event);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
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
    }

    /**
     * Detalhes de um delivery log
     */
    public function show(Request $request, string $logId): JsonResponse
    {
        $user = $request->user();
        $query = N8nDeliveryLog::with('integration');

        if ($user->role !== 'admin') {
            $query->whereHas('integration', function ($q) use ($user) {
                $q->where('account_id', $user->account_id);
            });
        }

        $log = $query->find($logId);

        if (!$log) {
            return response()->json([
                'success' => false,
                'message' => 'Log não encontrado',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $log,
        ]);
    }

    /**
     * Estatísticas de entregas
     */
    public function stats(Request $request): JsonResponse
    {
        $period = $request->input('period', '7d');
        $daysMap = ['1d' => 1, '7d' => 7, '30d' => 30];
        $days = $daysMap[$period] ?? 7;
        $from = now()->subDays($days)->startOfDay();

        $logs = N8nDeliveryLog::where('created_at', '>=', $from)->get();

        $total = $logs->count();
        $success = $logs->where('status', 'success')->count();
        $failed = $logs->where('status', 'failed')->count();
        $retrying = $logs->where('status', 'retrying')->count();
        $avgResponseTime = $logs->where('status', 'success')->avg('response_time_ms');

        // Group by event
        $byEvent = $logs->groupBy('event')
            ->map(fn($group) => [
                'total' => $group->count(),
                'success' => $group->where('status', 'success')->count(),
                'failed' => $group->where('status', 'failed')->count(),
            ])->toArray();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'success' => $success,
                'failed' => $failed,
                'retrying' => $retrying,
                'success_rate' => $total > 0 ? round(($success / $total) * 100, 1) : 0,
                'avg_response_time_ms' => $avgResponseTime ? round($avgResponseTime) : null,
                'by_event' => $byEvent,
            ],
        ]);
    }
}
