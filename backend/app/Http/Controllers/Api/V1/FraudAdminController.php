<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Blacklist;
use App\Models\RiskScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FraudAdminController extends Controller
{
    /**
     * Dashboard de fraude — métricas e tendências
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $period = $request->input('period', '30d');
            $daysMap = ['7d' => 7, '30d' => 30, '90d' => 90];
            $days = $daysMap[$period] ?? 30;
            $from = now()->subDays($days)->startOfDay();

            $riskScores = RiskScore::where('created_at', '>=', $from)->get();

            $totalAnalyzed = $riskScores->count();
            $highRisk = $riskScores->whereIn('risk_level', ['high', 'critical'])->count();
            $blocked = $riskScores->where('recommendation', 'decline')->count();
            $avgScore = $totalAnalyzed > 0 ? round($riskScores->avg('total_score'), 1) : 0;

            // Distribution
            $distribution = [
                'low' => $riskScores->where('risk_level', 'low')->count(),
                'medium' => $riskScores->where('risk_level', 'medium')->count(),
                'high' => $riskScores->where('risk_level', 'high')->count(),
                'critical' => $riskScores->where('risk_level', 'critical')->count(),
            ];

            // Daily trend
            $dailyTrend = RiskScore::where('created_at', '>=', $from)
                ->selectRaw('DATE(created_at) as date, COUNT(*) as total, AVG(total_score) as avg_score')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            // Active blacklist entries
            $blacklistCount = Blacklist::active()->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_analyzed' => $totalAnalyzed,
                    'high_risk_count' => $highRisk,
                    'blocked_count' => $blocked,
                    'average_score' => $avgScore,
                    'distribution' => $distribution,
                    'daily_trend' => $dailyTrend,
                    'active_blacklist_entries' => $blacklistCount,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('FraudAdminController::dashboard error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => true,
                'data' => [
                    'total_analyzed' => 0,
                    'high_risk_count' => 0,
                    'blocked_count' => 0,
                    'average_score' => 0,
                    'distribution' => ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0],
                    'daily_trend' => [],
                    'active_blacklist_entries' => 0,
                ],
            ]);
        }
    }

    /**
     * Listar risk scores com filtros
     */
    public function riskScores(Request $request): JsonResponse
    {
        try {
            $query = RiskScore::with('charge:id,amount,status,customer,payment_method')
                ->orderBy('created_at', 'desc');

            if ($request->filled('risk_level')) {
                $query->where('risk_level', $request->risk_level);
            }

            if ($request->filled('recommendation')) {
                $query->where('recommendation', $request->recommendation);
            }

            if ($request->filled('min_score')) {
                $query->where('total_score', '>=', (int) $request->min_score);
            }

            if ($request->filled('date_from')) {
                $query->where('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->where('created_at', '<=', $request->date_to);
            }

            $perPage = min((int) $request->input('per_page', 20), 100);
            $scores = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $scores->items(),
                'meta' => [
                    'current_page' => $scores->currentPage(),
                    'per_page' => $scores->perPage(),
                    'total' => $scores->total(),
                    'last_page' => $scores->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('FraudAdminController::riskScores error', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'data' => [], 'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]]);
        }
    }

    /**
     * Detalhes de um risk score
     */
    public function riskScoreDetail(string $riskScoreId): JsonResponse
    {
        try {
            $score = RiskScore::with('charge')->find($riskScoreId);

            if (!$score) {
                return response()->json([
                    'success' => false,
                    'message' => 'Risk score não encontrado',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $score,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('FraudAdminController::riskScoreDetail error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao carregar risk score'], 500);
        }
    }

    // ── Blacklist CRUD ─────────────────────────────────────────

    /**
     * Listar blacklist
     */
    public function blacklistIndex(Request $request): JsonResponse
    {
        try {
            $query = Blacklist::orderBy('created_at', 'desc');

            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            if ($request->filled('search')) {
                $search = addcslashes($request->search, '%_\\');
                $query->where(function ($q) use ($search) {
                    $q->where('value', 'LIKE', "%{$search}%")
                      ->orWhere('reason', 'LIKE', "%{$search}%");
                });
            }

            if ($request->filled('is_global')) {
                $query->where('is_global', filter_var($request->is_global, FILTER_VALIDATE_BOOLEAN));
            }

            if ($request->filled('active_only') && $request->active_only === 'true') {
                $query->active();
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
            \Illuminate\Support\Facades\Log::error('FraudAdminController::blacklistIndex error', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'data' => [], 'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]]);
        }
    }

    /**
     * Adicionar à blacklist
     */
    public function blacklistStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:email,document,ip,bin,fingerprint'],
            'value' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
            'is_global' => ['boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        // Check duplicate
        $exists = Blacklist::where('type', $validated['type'])
            ->where('value', $validated['value'])
            ->active()
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Este item já está na blacklist',
            ], 422);
        }

        $entry = Blacklist::create([
            'id' => Str::uuid()->toString(),
            'type' => $validated['type'],
            'value' => $validated['value'],
            'reason' => $validated['reason'] ?? null,
            'is_global' => $validated['is_global'] ?? true,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $entry,
            'message' => 'Item adicionado à blacklist',
        ], 201);
    }

    /**
     * Atualizar item da blacklist
     */
    public function blacklistUpdate(Request $request, string $blacklistId): JsonResponse
    {
        $entry = Blacklist::find($blacklistId);

        if (!$entry) {
            return response()->json([
                'success' => false,
                'message' => 'Item não encontrado',
            ], 404);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'is_global' => ['boolean'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $entry->update($validated);

        return response()->json([
            'success' => true,
            'data' => $entry->fresh(),
            'message' => 'Item atualizado',
        ]);
    }

    /**
     * Remover da blacklist
     */
    public function blacklistDestroy(string $blacklistId): JsonResponse
    {
        $entry = Blacklist::find($blacklistId);

        if (!$entry) {
            return response()->json([
                'success' => false,
                'message' => 'Item não encontrado',
            ], 404);
        }

        $entry->delete();

        return response()->json([
            'success' => true,
            'message' => 'Item removido da blacklist',
        ]);
    }

    /**
     * Device fingerprints — listar dispositivos suspeitos
     */
    public function devices(Request $request): JsonResponse
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('device_fingerprints')) {
                return response()->json(['success' => true, 'data' => [], 'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]]);
            }

            $query = DB::table('device_fingerprints')
                ->orderBy('last_seen_at', 'desc');

            if ($request->filled('trust_level')) {
                $query->where('trust_level', $request->trust_level);
            }

            if ($request->filled('search')) {
                $search = addcslashes($request->search, '%_\\');
                $query->where(function ($q) use ($search) {
                    $q->where('customer_email', 'LIKE', "%{$search}%")
                      ->orWhere('ip_address', 'LIKE', "%{$search}%")
                      ->orWhere('fingerprint_hash', 'LIKE', "%{$search}%");
                });
            }

            $perPage = min((int) $request->input('per_page', 20), 100);
            $devices = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $devices->items(),
                'meta' => [
                    'current_page' => $devices->currentPage(),
                    'per_page' => $devices->perPage(),
                    'total' => $devices->total(),
                    'last_page' => $devices->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('FraudAdminController::devices error', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'data' => [], 'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]]);
        }
    }

    /**
     * Alterar trust_level de um device
     */
    public function updateDeviceTrust(Request $request, string $fingerprintId): JsonResponse
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('device_fingerprints')) {
                return response()->json(['success' => false, 'message' => 'Tabela de dispositivos não disponível'], 500);
            }

            $validated = $request->validate([
                'trust_level' => ['required', 'in:trusted,neutral,suspicious,blocked'],
            ]);

            $updated = DB::table('device_fingerprints')
                ->where('id', $fingerprintId)
                ->update(['trust_level' => $validated['trust_level'], 'updated_at' => now()]);

            if (!$updated) {
                return response()->json(['success' => false, 'message' => 'Dispositivo não encontrado'], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Trust level atualizado',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('FraudAdminController::updateDeviceTrust error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao atualizar trust level'], 500);
        }
    }
}
