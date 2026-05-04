<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Blacklist;
use App\Models\Charge;
use App\Models\SecurityThreat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Security Admin Controller
 * 
 * Manages platform security: threats, blocklist, and security stats
 */
class SecurityAdminController extends Controller
{
    /**
     * Get security statistics
     */
    public function stats(): JsonResponse
    {
        try {
            $today = now()->startOfDay();
            $weekAgo = now()->subDays(7)->startOfDay();

            // Try to get threats from security_threats table, fallback to audit_logs
            $threatsToday = 0;
            $threatsWeek = 0;
            
            if (DB::getSchemaBuilder()->hasTable('security_threats')) {
                $threatsToday = SecurityThreat::where('created_at', '>=', $today)->count();
                $threatsWeek = SecurityThreat::where('created_at', '>=', $weekAgo)->count();
            }

            // Get blocked counts from blacklist
            $blockedIps = Blacklist::where('type', Blacklist::TYPE_IP)->active()->count();
            $blockedEmails = Blacklist::where('type', Blacklist::TYPE_EMAIL)->active()->count();

            // Calculate fraud prevented (charges blocked)
            $fraudPrevented = Charge::where('status', 'blocked')
                ->where('created_at', '>=', $weekAgo)
                ->sum('amount');

            return response()->json([
                'success' => true,
                'data' => [
                    'threats_today' => $threatsToday,
                    'threats_week' => $threatsWeek,
                    'blocked_ips' => $blockedIps,
                    'blocked_emails' => $blockedEmails,
                    'fraud_prevented_amount' => (int) $fraudPrevented,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SecurityAdminController::stats error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => true,
                'data' => [
                    'threats_today' => 0,
                    'threats_week' => 0,
                    'blocked_ips' => 0,
                    'blocked_emails' => 0,
                    'fraud_prevented_amount' => 0,
                ],
            ]);
        }
    }

    /**
     * List security threats
     */
    public function threats(Request $request): JsonResponse
    {
        try {
            $perPage = min($request->integer('per_page', 15), 100);
            $searchInput = (string) $request->input('search', '');
            $search = $searchInput !== '' ? addcslashes($searchInput, '%_\\') : '';
            $severity = (string) $request->input('severity', '');

            // Check if table exists
            if (!DB::getSchemaBuilder()->hasTable('security_threats')) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $perPage,
                        'total' => 0,
                    ],
                ]);
            }

            $query = SecurityThreat::query()->orderBy('created_at', 'desc');

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('ip_address', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            if ($severity && $severity !== 'all') {
                $query->where('severity', $severity);
            }

            $threats = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $threats->items(),
                'meta' => [
                    'current_page' => $threats->currentPage(),
                    'last_page' => $threats->lastPage(),
                    'per_page' => $threats->perPage(),
                    'total' => $threats->total(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SecurityAdminController::threats error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 0],
            ]);
        }
    }

    /**
     * Block IP from a threat
     */
    public function blockThreat(string $threatId): JsonResponse
    {
        try {
            if (!DB::getSchemaBuilder()->hasTable('security_threats')) {
                return response()->json(['success' => false, 'message' => 'Tabela de ameaças não existe'], 404);
            }

            $threat = SecurityThreat::find($threatId);
            if (!$threat) {
                return response()->json(['success' => false, 'message' => 'Ameaça não encontrada'], 404);
            }

            // Add IP to blacklist
            Blacklist::updateOrCreate(
                ['type' => Blacklist::TYPE_IP, 'value' => $threat->ip_address],
                [
                    'reason' => "Bloqueado via ameaça: {$threat->type}",
                    'is_global' => true,
                    'expires_at' => null,
                ]
            );

            // Mark threat as blocked
            $threat->update(['blocked' => true]);

            return response()->json([
                'success' => true,
                'message' => 'IP bloqueado com sucesso',
            ]);
        } catch (\Throwable $e) {
            Log::error('SecurityAdminController::blockThreat error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao bloquear IP'], 500);
        }
    }

    /**
     * Dismiss a threat
     */
    public function dismissThreat(string $threatId): JsonResponse
    {
        try {
            if (!DB::getSchemaBuilder()->hasTable('security_threats')) {
                return response()->json(['success' => false, 'message' => 'Tabela de ameaças não existe'], 404);
            }

            $threat = SecurityThreat::find($threatId);
            if (!$threat) {
                return response()->json(['success' => false, 'message' => 'Ameaça não encontrada'], 404);
            }

            $threat->delete();

            return response()->json([
                'success' => true,
                'message' => 'Ameaça descartada',
            ]);
        } catch (\Throwable $e) {
            Log::error('SecurityAdminController::dismissThreat error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao descartar ameaça'], 500);
        }
    }

    /**
     * List blocklist items
     */
    public function blocklist(Request $request): JsonResponse
    {
        try {
            $perPage = min($request->integer('per_page', 15), 100);
            $type = (string) $request->input('type', '');

            $query = Blacklist::query()
                ->active()
                ->orderBy('created_at', 'desc');

            if ($type && $type !== 'all') {
                $normalizedType = $this->normalizeBlacklistType($type) ?? $type;
                $query->where('type', $normalizedType);
            }

            $items = $query->paginate($perPage);

            $data = collect($items->items())->map(function ($item) {
                return [
                    'id' => $item->id,
                    'type' => $item->type,
                    'value' => $item->value,
                    'reason' => $item->reason,
                    'blocked_by' => null,
                    'created_at' => $item->created_at?->toIso8601String(),
                    'expires_at' => $item->expires_at?->toIso8601String(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => [
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                    'per_page' => $items->perPage(),
                    'total' => $items->total(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SecurityAdminController::blocklist error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 0],
            ]);
        }
    }

    /**
     * Add item to blocklist
     */
    public function addToBlocklist(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'type' => ['required', 'string', 'in:ip,email,phone,card_bin,document,bin,fingerprint'],
                'value' => ['required', 'string', 'max:255'],
                'reason' => ['nullable', 'string', 'max:500'],
                'expires_at' => ['nullable', 'date'],
            ]);

            $normalizedType = $this->normalizeBlacklistType($validated['type']);
            if (!$normalizedType) {
                return response()->json(['success' => false, 'message' => 'Tipo de bloqueio invalido'], 422);
            }

            // Check if already exists
            $existing = Blacklist::where('type', $normalizedType)
                ->where('value', $validated['value'])
                ->first();

            if ($existing) {
                $existing->update([
                    'reason' => $validated['reason'] ?? $existing->reason,
                    'expires_at' => $validated['expires_at'] ?? null,
                ]);
                $item = $existing;
            } else {
                $item = Blacklist::create([
                    'type' => $normalizedType,
                    'value' => $validated['value'],
                    'reason' => $validated['reason'] ?? null,
                    'expires_at' => $validated['expires_at'] ?? null,
                    'is_global' => true,
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $item->id,
                    'type' => $normalizedType,
                    'value' => $item->value,
                    'reason' => $item->reason,
                    'created_at' => $item->created_at?->toIso8601String(),
                ],
                'message' => 'Item bloqueado com sucesso',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Dados inválidos', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('SecurityAdminController::addToBlocklist error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao adicionar bloqueio'], 500);
        }
    }

    /**
     * Remove item from blocklist
     */
    public function removeFromBlocklist(string $blockId): JsonResponse
    {
        try {
            $item = Blacklist::find($blockId);
            if (!$item) {
                return response()->json(['success' => false, 'message' => 'Item não encontrado'], 404);
            }

            $item->delete();

            return response()->json([
                'success' => true,
                'message' => 'Desbloqueado com sucesso',
            ]);
        } catch (\Throwable $e) {
            Log::error('SecurityAdminController::removeFromBlocklist error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao desbloquear'], 500);
        }
    }

    private function normalizeBlacklistType(string $type): ?string
    {
        return match (strtolower(trim($type))) {
            'ip' => Blacklist::TYPE_IP,
            'email' => Blacklist::TYPE_EMAIL,
            'document' => Blacklist::TYPE_DOCUMENT,
            'bin', 'card_bin' => Blacklist::TYPE_BIN,
            'fingerprint', 'phone' => Blacklist::TYPE_FINGERPRINT,
            default => null,
        };
    }
}
