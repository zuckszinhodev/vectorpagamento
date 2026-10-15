<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Chargeback;
use App\Services\ChargebackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChargebackController extends Controller
{
    public function __construct(
        private ChargebackService $service,
    ) {}

    /**
     * Listar chargebacks com filtros
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Chargeback::with(['charge' => function ($q) {
                $q->select('id', 'amount', 'status', 'customer', 'payment_method', 'acquirer', 'description', 'created_at', 'paid_at');
            }])
                ->orderBy('created_at', 'desc');

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('account_id')) {
                $query->where('account_id', $request->account_id);
            }

            if ($request->filled('charge_id')) {
                $query->where('charge_id', $request->charge_id);
            }

            if ($request->filled('date_from')) {
                $query->where('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->where('created_at', '<=', $request->date_to);
            }

            if ($request->filled('expiring') && $request->expiring === 'true') {
                $query->expiring();
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
            \Illuminate\Support\Facades\Log::error('ChargebackController::index error', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'data' => [], 'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]]);
        }
    }

    /**
     * Listar chargebacks do usuário autenticado
     */
    public function userIndex(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $query = Chargeback::with(['charge' => function ($q) {
                $q->select('id', 'amount', 'status', 'customer', 'payment_method', 'acquirer', 'description', 'created_at', 'paid_at');
            }])
                ->where('account_id', $user->account_id)
                ->orderBy('created_at', 'desc');

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('charge_id')) {
                $query->where('charge_id', $request->charge_id);
            }

            if ($request->filled('date_from')) {
                $query->where('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->where('created_at', '<=', $request->date_to);
            }

            if ($request->filled('expiring') && $request->expiring === 'true') {
                $query->expiring();
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
            \Illuminate\Support\Facades\Log::error('ChargebackController::userIndex error', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'data' => [], 'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]]);
        }
    }

    /**
     * Detalhes de um chargeback
     */
    public function show(string $chargebackId): JsonResponse
    {
        $cb = Chargeback::with(['charge', 'transaction', 'account'])->find($chargebackId);

        if (!$cb) {
            return response()->json([
                'success' => false,
                'message' => 'Chargeback não encontrado',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $cb,
        ]);
    }

    /**
     * Detalhes de um chargeback do usuário autenticado
     */
    public function userShow(Request $request, string $chargebackId): JsonResponse
    {
        $user = $request->user();
        
        $cb = Chargeback::with(['charge', 'transaction', 'account'])
            ->where('account_id', $user->account_id)
            ->find($chargebackId);

        if (!$cb) {
            return response()->json([
                'success' => false,
                'message' => 'Chargeback não encontrado',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $cb,
        ]);
    }

    /**
     * Abrir chargeback manualmente (admin)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'charge_id' => ['required', 'uuid', 'exists:charges,id'],
            'amount' => ['nullable', 'integer', 'min:1'],
            'reason_code' => ['nullable', 'string', 'max:50'],
            'reason' => ['nullable', 'string', 'max:500'],
            'acquirer_reference' => ['nullable', 'string', 'max:255'],
            'deadline_at' => ['nullable', 'date', 'after:now'],
        ]);

        try {
            $chargeback = $this->service->open($validated);

            return response()->json([
                'success' => true,
                'data' => $chargeback,
                'message' => 'Chargeback aberto',
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Enviar evidência para contestação
     */
    public function submitEvidence(Request $request, string $chargebackId): JsonResponse
    {
        $query = Chargeback::query();
        if (!$request->is('api/v1/admin/chargebacks/*')) {
            $query->where('account_id', $request->user()->account_id);
        }
        $cb = $query->find($chargebackId);

        if (!$cb) {
            return response()->json([
                'success' => false,
                'message' => 'Chargeback não encontrado',
            ], 404);
        }

        if ($cb->isResolved()) {
            return response()->json(['success' => false, 'message' => 'Disputa já encerrada'], 422);
        }

        $validated = $request->validate(\App\Http\Requests\ChargebackEvidenceRules::rules());
        if (collect($request->file('images', []))->sum(fn ($file) => $file->getSize()) > 20 * 1024 * 1024) {
            return response()->json(['success' => false, 'message' => 'Os anexos devem somar no máximo 20 MB.'], 422);
        }

        try {
            $evidence = [
                'description' => $validated['description'],
                'tracking_number' => $validated['tracking_number'] ?? null,
                'delivery_proof' => $validated['delivery_proof'] ?? null,
                'images' => [],
                'med' => $validated['med'] ?? null,
                'submitted_at' => now()->toIso8601String(),
            ];

            // Handle image uploads
            if ($request->hasFile('images')) {
                $accountId = $cb->account_id;
                foreach ($request->file('images') as $image) {
                    $filename = \Illuminate\Support\Str::uuid() . '.' . $image->extension();
                    $path = "chargebacks/{$accountId}/{$cb->id}/{$filename}";
                    
                    if (!\Illuminate\Support\Facades\Storage::disk('private')->put($path, file_get_contents($image->getRealPath()))) {
                        throw new \RuntimeException('Não foi possível salvar o anexo. Tente novamente.');
                    }
                    
                    $evidence['images'][] = [
                        'path' => $path,
                        'filename' => $image->getClientOriginalName(),
                        'size' => $image->getSize(),
                        'mime_type' => $image->getMimeType(),
                        'uploaded_at' => now()->toIso8601String(),
                    ];
                }
            }

            $cb = $this->service->submitEvidence($cb, $evidence);

            return response()->json([
                'success' => true,
                'data' => $cb,
                'message' => 'Evidência enviada',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function downloadEvidence(Request $request, string $chargebackId, int $index)
    {
        $query = Chargeback::query();
        if (!$request->is('api/v1/admin/chargebacks/*')) {
            $query->where('account_id', $request->user()->account_id);
        }
        $cb = $query->findOrFail($chargebackId);
        $file = ($cb->evidence['images'] ?? [])[$index] ?? null;
        abort_unless(is_array($file) && isset($file['path']), 404);
        abort_unless(str_starts_with($file['path'], "chargebacks/{$cb->account_id}/{$cb->id}/"), 404);
        $disk = \Illuminate\Support\Facades\Storage::disk('private');
        abort_unless($disk->exists($file['path']), 404);
        return $disk->download($file['path'], $file['filename'] ?? basename($file['path']), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Resolver chargeback (admin)
     */
    public function resolve(Request $request, string $chargebackId): JsonResponse
    {
        $cb = Chargeback::find($chargebackId);

        if (!$cb) {
            return response()->json([
                'success' => false,
                'message' => 'Chargeback não encontrado',
            ], 404);
        }

        $validated = $request->validate([
            'outcome' => ['required', 'in:won,lost'],
            'notes' => ['required', 'string', 'max:1000'],
        ]);

        $user = $request->user();

        try {
            $cb = $this->service->resolve(
                $cb,
                $validated['outcome'],
                $validated['notes'],
                $user->id ?? null,
            );

            return response()->json([
                'success' => true,
                'data' => $cb,
                'message' => 'Chargeback resolvido',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Dashboard de chargebacks
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $period = $request->input('period', '30d');
            $daysMap = ['7d' => 7, '30d' => 30, '90d' => 90];
            $days = $daysMap[$period] ?? 30;
            $from = now()->subDays($days)->startOfDay();

            $cbs = Chargeback::where('created_at', '>=', $from)->get();

            $active = $cbs->filter(fn($cb) => !$cb->isResolved());
            $won = $cbs->where('status', Chargeback::STATUS_WON);
            $lost = $cbs->where('status', Chargeback::STATUS_LOST);
            $expiring = Chargeback::expiring(3)->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $cbs->count(),
                    'active' => $active->count(),
                    'won' => $won->count(),
                    'lost' => $lost->count(),
                    'expiring_soon' => $expiring,
                    'total_amount' => $cbs->sum('amount'),
                    'won_amount' => $won->sum('amount'),
                    'lost_amount' => $lost->sum('amount'),
                    'win_rate' => ($won->count() + $lost->count()) > 0
                        ? round(($won->count() / ($won->count() + $lost->count())) * 100, 1)
                        : 0,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ChargebackController::dashboard error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => true,
                'data' => [
                    'total' => 0, 'active' => 0, 'won' => 0, 'lost' => 0,
                    'expiring_soon' => 0, 'total_amount' => 0, 'won_amount' => 0,
                    'lost_amount' => 0, 'win_rate' => 0,
                ],
            ]);
        }
    }
}
