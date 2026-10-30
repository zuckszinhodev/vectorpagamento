<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountAcquirer;
use App\Models\AccountWallet;
use App\Models\AcquirerConfig;
use App\Models\Charge;
use App\Models\WalletAdjustment;
use App\Domain\Acquirer\Entities\Acquirer as AcquirerEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountController extends Controller
{
    /**
     * Get current account details
     */
    public function show(Request $request): JsonResponse
    {
        try {
        $user = $request->user();
        $account = Account::find($user->account_id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'account_not_found',
                    'message' => 'Conta não encontrada',
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $account,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AccountController::show error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * List globally active acquirers for the current account priority management.
     */
    public function acquirers(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user?->account_id) {
                return response()->json(['success' => false, 'message' => 'Conta não encontrada'], 404);
            }

            $accountAssignments = AccountAcquirer::where('account_id', $user->account_id)
                ->where('is_active', true)
                ->get()
                ->keyBy('acquirer_id');

            $activeConfigs = AcquirerConfig::where('enabled', true)
                ->orderBy('priority', 'asc')
                ->get();

            foreach ($activeConfigs as $config) {
                // Preserve the admin-controlled is_active flag on existing entities;
                // only default it to true when creating a brand new entity.
                $entity = AcquirerEntity::firstOrNew(['slug' => $config->acquirer_key]);
                $entity->name = $config->display_name ?? ucfirst($config->acquirer_key);
                $entity->supported_methods = $config->supported_methods ?? [];
                if (!$entity->exists) {
                    $entity->is_active = true;
                    $entity->settings = [];
                }
                $entity->save();
            }

            if ($accountAssignments->isNotEmpty()) {
                $slugs = AcquirerEntity::whereIn('id', $accountAssignments->keys())
                    ->get()
                    ->sortBy(fn (AcquirerEntity $entity) => $accountAssignments->get($entity->id)?->priority ?? 99)
                    ->pluck('slug')
                    ->filter(fn (string $slug) => $activeConfigs->contains('acquirer_key', $slug))
                    ->values();
            } else {
                $slugs = $activeConfigs->pluck('acquirer_key')->values();
            }

            $configs = AcquirerConfig::whereIn('acquirer_key', $slugs)
                ->get()
                ->keyBy('acquirer_key');

            $entities = AcquirerEntity::whereIn('slug', $slugs)
                ->get()
                ->keyBy('slug');

            $chargeMetrics = Charge::where('account_id', $user->account_id)
                ->where('created_at', '>=', now()->subDay())
                ->get(['acquirer', 'status'])
                ->groupBy(fn (Charge $charge) => strtolower((string) ($charge->acquirer ?? '')));

            $items = $slugs->map(function (string $slug) use ($entities, $configs, $accountAssignments, $chargeMetrics) {
                $entity = $entities->get($slug);
                if (!$entity || !$entity->is_active) {
                    return null;
                }

                $config = $configs->get($slug);
                $assignment = $accountAssignments->get($entity->id);
                $supportedMethods = $config?->supported_methods ?? $entity->supported_methods ?? [];
                $settings = $assignment?->settings ?? [];
                $metricsRows = $chargeMetrics->get(strtolower($slug), collect());
                $volumeToday = $metricsRows->count();
                $approvedToday = $metricsRows->filter(fn (Charge $charge) => in_array($charge->status, ['paid', 'approved', 'completed'], true))->count();
                $failedToday = $metricsRows->filter(fn (Charge $charge) => in_array($charge->status, ['failed', 'cancelled', 'refused', 'expired'], true))->count();

                return [
                    'acquirer_id' => $entity->id,
                    'slug' => $slug,
                    'name' => $config?->display_name ?? $entity->name,
                    'nominal_name' => $config?->nominal_name,
                    'priority' => $assignment?->priority ?? $config?->priority ?? 99,
                    'is_active' => (bool) $entity->is_active,
                    'routing_weight' => (int) ($settings['routing_weight'] ?? 1),
                    'supported_methods' => $supportedMethods,
                    'supports_pix' => in_array('pix', $supportedMethods, true),
                    'supports_pix_out' => in_array('pix_out', $supportedMethods, true),
                    'supports_credit_card' => in_array('credit_card', $supportedMethods, true) || in_array('card', $supportedMethods, true),
                    'supports_boleto' => in_array('boleto', $supportedMethods, true),
                    'metrics' => [
                        'approval_rate' => $volumeToday > 0 ? round(($approvedToday / $volumeToday) * 100, 1) : null,
                        'volume_today' => $volumeToday,
                        'failures_today' => $failedToday,
                        'latency_ms' => $settings['latency_ms'] ?? null,
                    ],
                ];
            })->filter()->sortBy('priority')->values();

            return response()->json(['success' => true, 'data' => $items]);
        } catch (\Throwable $e) {
            Log::error('AccountController::acquirers error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao carregar adquirentes'], 500);
        }
    }

    public function acquirerRoutingSettings(Request $request): JsonResponse
    {
        try {
            $account = $this->resolveAccount($request);
            if (!$account) {
                return response()->json(['success' => false, 'message' => 'Conta nÃ£o encontrada'], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->getAccountRoutingSettings($account),
            ]);
        } catch (\Throwable $e) {
            Log::error('AccountController::acquirerRoutingSettings error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro ao carregar configuracao de rotacao'], 500);
        }
    }

    public function updateAcquirerRoutingSettings(Request $request): JsonResponse
    {
        $account = $this->resolveAccount($request);
        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Conta nÃ£o encontrada'], 404);
        }

        $validated = $request->validate([
            'rotation_mode' => ['required', Rule::in(['priority', 'weighted', 'fallback', 'approval'])],
            'method_rules' => ['nullable', 'array'],
            'method_rules.pix' => ['nullable', 'uuid'],
            'method_rules.credit_card' => ['nullable', 'uuid'],
            'method_rules.boleto' => ['nullable', 'uuid'],
            'weights' => ['nullable', 'array'],
            'weights.*' => ['integer', 'min:1', 'max:100'],
            'history_note' => ['nullable', 'string', 'max:180'],
        ]);

        try {
            $allowedAcquirerIds = $this->allowedAccountAcquirerIds($account->id);
            $methodRules = collect($validated['method_rules'] ?? [])
                ->only(['pix', 'credit_card', 'boleto'])
                ->map(fn ($acquirerId) => in_array($acquirerId, $allowedAcquirerIds, true) ? $acquirerId : null)
                ->all();

            DB::transaction(function () use ($account, $validated, $allowedAcquirerIds, $methodRules, $request) {
                foreach (($validated['weights'] ?? []) as $acquirerId => $weight) {
                    if (!in_array($acquirerId, $allowedAcquirerIds, true)) {
                        continue;
                    }

                    $assignment = AccountAcquirer::firstOrNew([
                        'account_id' => $account->id,
                        'acquirer_id' => $acquirerId,
                    ]);

                    if (!$assignment->exists) {
                        $assignment->credentials = encrypt([]);
                        $assignment->priority = 99;
                        $assignment->is_active = true;
                    }

                    $settings = $assignment->settings ?? [];
                    $settings['routing_weight'] = (int) $weight;
                    $assignment->settings = $settings;
                    $assignment->save();
                }

                $settings = $account->settings ?? [];
                $routing = $settings['acquirer_routing'] ?? [];
                $history = array_slice($routing['history'] ?? [], 0, 9);

                array_unshift($history, [
                    'action' => $validated['history_note'] ?? 'Configuracao de rotacao atualizada',
                    'user_id' => $request->user()?->id,
                    'at' => now()->toIso8601String(),
                ]);

                $settings['acquirer_routing'] = [
                    'rotation_mode' => $validated['rotation_mode'],
                    'method_rules' => $methodRules,
                    'history' => array_slice($history, 0, 10),
                    'updated_at' => now()->toIso8601String(),
                ];

                $account->settings = $settings;
                $account->save();
            });

            $account->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Configuracao de rotacao atualizada',
                'data' => $this->getAccountRoutingSettings($account),
            ]);
        } catch (\Throwable $e) {
            Log::error('AccountController::updateAcquirerRoutingSettings error', [
                'error' => $e->getMessage(),
                'account_id' => $account->id,
            ]);
            return response()->json(['success' => false, 'message' => 'Erro ao salvar configuracao de rotacao'], 500);
        }
    }

    /**
     * Persist current account acquirer priority order.
     */
    public function reorderAcquirers(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user?->account_id) {
            return response()->json(['success' => false, 'message' => 'Conta não encontrada'], 404);
        }

        $validated = $request->validate([
            'acquirers' => ['required', 'array', 'min:1'],
            'acquirers.*.acquirer_id' => ['required', 'uuid'],
            'acquirers.*.priority' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $accountAssignmentIds = AccountAcquirer::where('account_id', $user->account_id)
                ->where('is_active', true)
                ->pluck('acquirer_id');

            $activeConfigSlugs = AcquirerConfig::where('enabled', true)->pluck('acquirer_key');
            $globalEntityIds = AcquirerEntity::whereIn('slug', $activeConfigSlugs)
                ->pluck('id')
                ->all();

            if ($accountAssignmentIds->isNotEmpty()) {
                $allowedEntityIds = array_values(array_unique(array_merge(
                    $accountAssignmentIds->values()->all(),
                    $globalEntityIds,
                )));
            } else {
                $allowedEntityIds = $globalEntityIds;
            }

            DB::transaction(function () use ($validated, $user, $allowedEntityIds) {
                foreach ($validated['acquirers'] as $item) {
                    if (!in_array($item['acquirer_id'], $allowedEntityIds, true)) {
                        continue;
                    }

                    $assignment = AccountAcquirer::firstOrNew([
                        'account_id' => $user->account_id,
                        'acquirer_id' => $item['acquirer_id'],
                    ]);

                    if (!$assignment->exists) {
                        $assignment->credentials = encrypt([]);
                        $assignment->settings = [];
                    }

                    $assignment->is_active = true;
                    $assignment->priority = (int) $item['priority'];
                    $assignment->save();
                }

                $account = Account::find($user->account_id);
                if ($account) {
                    $settings = $account->settings ?? [];
                    $routing = $settings['acquirer_routing'] ?? [];
                    $history = array_slice($routing['history'] ?? [], 0, 9);
                    array_unshift($history, [
                        'action' => 'Prioridade das nominais atualizada',
                        'user_id' => $user->id,
                        'at' => now()->toIso8601String(),
                    ]);
                    $routing['history'] = array_slice($history, 0, 10);
                    $routing['updated_at'] = now()->toIso8601String();
                    $settings['acquirer_routing'] = $routing;
                    $account->settings = $settings;
                    $account->save();
                }
            });

            return response()->json(['success' => true, 'message' => 'Prioridade das nominais atualizada']);
        } catch (\Throwable $e) {
            Log::error('AccountController::reorderAcquirers error', ['error' => $e->getMessage(), 'account_id' => $user->account_id]);
            return response()->json(['success' => false, 'message' => 'Erro ao salvar prioridade das nominais'], 500);
        }
    }

    private function resolveAccount(Request $request): ?Account
    {
        $user = $request->user();
        if (!$user?->account_id) {
            return null;
        }

        return Account::find($user->account_id);
    }

    private function getAccountRoutingSettings(Account $account): array
    {
        $routing = ($account->settings ?? [])['acquirer_routing'] ?? [];
        $assignments = AccountAcquirer::where('account_id', $account->id)->get(['acquirer_id', 'settings']);

        return [
            'rotation_mode' => $routing['rotation_mode'] ?? 'priority',
            'method_rules' => array_merge([
                'pix' => null,
                'credit_card' => null,
                'boleto' => null,
            ], $routing['method_rules'] ?? []),
            'weights' => $assignments
                ->mapWithKeys(fn (AccountAcquirer $assignment) => [
                    $assignment->acquirer_id => (int) (($assignment->settings ?? [])['routing_weight'] ?? 1),
                ])
                ->all(),
            'history' => $routing['history'] ?? [],
            'updated_at' => $routing['updated_at'] ?? null,
        ];
    }

    private function allowedAccountAcquirerIds(string $accountId): array
    {
        $assignedIds = AccountAcquirer::where('account_id', $accountId)
            ->pluck('acquirer_id')
            ->all();

        $activeConfigSlugs = AcquirerConfig::where('enabled', true)->pluck('acquirer_key');
        $globalIds = AcquirerEntity::whereIn('slug', $activeConfigSlugs)
            ->pluck('id')
            ->all();

        return array_values(array_unique(array_merge($assignedIds, $globalIds)));
    }

    /**
     * Get saved payment info (PIX keys + bank) from account settings
     */
    public function getPaymentInfo(Request $request): JsonResponse
    {
        try {
        $user = $request->user();
        $account = Account::find($user->account_id);

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Conta não encontrada'], 404);
        }

        $settings = $account->settings ?? [];

        // Migrate legacy single pix to pix_keys array
        $pixKeys = $settings['pix_keys'] ?? [];
        if (empty($pixKeys) && !empty($settings['pix']['pix_key'])) {
            $pixKeys = [
                [
                    'id' => uniqid('pix_'),
                    'pix_key' => $settings['pix']['pix_key'],
                    'pix_type' => $settings['pix']['pix_type'] ?? 'cpf',
                    'label' => 'Principal',
                ],
            ];
            // Persist migration
            $settings['pix_keys'] = $pixKeys;
            unset($settings['pix']);
            $account->update(['settings' => $settings]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'pix_keys' => $pixKeys,
                'bank_account' => $settings['bank_account'] ?? null,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AccountController::getPaymentInfo error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Save payment info (PIX keys and/or bank account) to account settings
     */
    public function savePaymentInfo(Request $request): JsonResponse
    {
        try {
        $user = $request->user();
        $account = Account::find($user->account_id);

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Conta não encontrada'], 404);
        }

        $validated = $request->validate([
            // Multiple PIX keys
            'pix_keys' => ['nullable', 'array', 'max:10'],
            'pix_keys.*.id' => ['nullable', 'string', 'max:50'],
            'pix_keys.*.pix_key' => ['required', 'string', 'max:255'],
            'pix_keys.*.pix_type' => ['required', 'in:cpf,cnpj,email,phone,random'],
            'pix_keys.*.label' => ['nullable', 'string', 'max:100'],
            // Bank account (unchanged)
            'bank_account' => ['nullable', 'array'],
            'bank_account.bank_code' => ['required_with:bank_account', 'string'],
            'bank_account.bank_name' => ['required_with:bank_account', 'string'],
            'bank_account.agency' => ['required_with:bank_account', 'string'],
            'bank_account.account_number' => ['required_with:bank_account', 'string'],
            'bank_account.account_type' => ['required_with:bank_account', 'in:checking,savings'],
            'bank_account.holder_name' => ['required_with:bank_account', 'string'],
            'bank_account.holder_document' => ['required_with:bank_account', 'string'],
        ]);

        $settings = $account->settings ?? [];

        if (array_key_exists('pix_keys', $validated) && is_array($validated['pix_keys'])) {
            // Ensure each key has an id
            $pixKeys = array_map(function ($key) {
                if (empty($key['id'])) {
                    $key['id'] = uniqid('pix_');
                }
                return $key;
            }, $validated['pix_keys']);

            // Check for duplicate pix_key values
            $keyValues = array_column($pixKeys, 'pix_key');
            if (count($keyValues) !== count(array_unique($keyValues))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Chaves PIX duplicadas não são permitidas',
                ], 422);
            }

            $settings['pix_keys'] = array_values($pixKeys);
            // Remove legacy single pix field if present
            unset($settings['pix']);
        }

        if (array_key_exists('bank_account', $validated)) {
            $settings['bank_account'] = $validated['bank_account'];
        }

        $account->update(['settings' => $settings]);

        return response()->json([
            'success' => true,
            'data' => [
                'pix_keys' => $settings['pix_keys'] ?? [],
                'bank_account' => $settings['bank_account'] ?? null,
            ],
            'message' => 'Dados de pagamento salvos com sucesso',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AccountController::savePaymentInfo error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Update account settings
     */
    public function update(Request $request): JsonResponse
    {
        try {
        $user = $request->user();
        $account = Account::find($user->account_id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'account_not_found',
                    'message' => 'Conta não encontrada',
                ],
            ], 404);
        }

        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'business_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email'],
            'phone' => ['nullable', 'string'],
            'address' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
        ]);

        $updateData = $request->only([
            'name', 'business_name', 'email', 'phone', 'address', 'settings',
        ]);

        $account->update(array_filter($updateData, fn($v) => $v !== null));

        return response()->json([
            'success' => true,
            'data' => $account->fresh(),
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AccountController::update error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Get account wallet/balance
     */
    public function wallet(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
        
            $wallet = AccountWallet::where('account_id', $user->account_id)->first();

            if (!$wallet) {
                // Create default wallet if not exists
                $wallet = AccountWallet::create([
                    'id' => \Illuminate\Support\Str::uuid()->toString(),
                    'account_id' => $user->account_id,
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'reserved_balance' => 0,
                    'currency' => 'BRL',
                ]);
            }

            // Calculate totals from charges with proper fee deduction
            $approvedCharges = Charge::where('account_id', $user->account_id)
                ->whereIn('status', ['paid', 'approved'])
                ->get();

            // Pre-load all acquirer configs in a single query (avoids N+1)
            try {
                $acquirerConfigs = AcquirerConfig::whereIn(
                    'acquirer_key',
                    $approvedCharges->pluck('acquirer')->unique()->filter()
                )->get()->keyBy('acquirer_key');
            } catch (\Throwable $e) {
                $acquirerConfigs = collect();
            }

            $totalRevenue = 0;
            $totalNet = 0;
            foreach ($approvedCharges as $charge) {
                $totalRevenue += $charge->amount;

                // Split charges have no fee
                if ($charge->payment_method === 'split') {
                    $totalNet += $charge->amount;
                    continue;
                }

                $fee = app(\App\Services\SystemFeeService::class)->forCharge($charge, $acquirerConfigs->get($charge->acquirer));
                $totalNet += ($charge->amount - $fee);
            }

            $pendingCharges = Charge::where('account_id', $user->account_id)
                ->whereIn('status', ['pending', 'processing'])
                ->sum('amount');

            // Deduct all non-cancelled/non-failed withdrawals so open requests keep funds locked
            try {
                $lockedWithdrawals = \App\Models\Withdrawal::where('account_id', $user->account_id)
                    ->whereNotIn('status', ['cancelled', 'failed'])
                    ->sum('amount');

                $pendingWithdrawals = \App\Models\Withdrawal::where('account_id', $user->account_id)
                    ->whereIn('status', ['pending', 'pending_approval', 'processing'])
                    ->sum('amount');
            } catch (\Throwable $e) {
                $lockedWithdrawals = 0;
                $pendingWithdrawals = 0;
            }

            try {
                $manualAdjustments = (int) WalletAdjustment::query()
                    ->where('account_id', $user->account_id)
                    ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN -amount ELSE amount END), 0) AS total")
                    ->value('total');
            } catch (\Throwable $e) {
                $manualAdjustments = 0;
            }

            $correctBalance = max(0, $totalNet - (int) $lockedWithdrawals + $manualAdjustments);

            // Sync wallet
            $walletUpdates = [];
            if ((int) $wallet->available_balance !== $correctBalance) {
                $walletUpdates['available_balance'] = $correctBalance;
            }
            if ((int) $wallet->pending_balance !== (int) $pendingWithdrawals) {
                $walletUpdates['pending_balance'] = (int) $pendingWithdrawals;
            }

            if ($walletUpdates !== []) {
                $wallet->update($walletUpdates);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'available_balance' => $correctBalance,
                    'pending_balance' => (int) $pendingCharges + (int) $pendingWithdrawals,
                    'reserved_balance' => $wallet->reserved_balance ?? 0,
                    'total_revenue' => $totalRevenue,
                    'manual_adjustments' => $manualAdjustments,
                    'currency' => $wallet->currency,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AccountController::wallet failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'internal_error', 'message' => 'Erro ao carregar carteira'],
            ], 500);
        }
    }

    /**
     * Get account transactions history
     */
    public function transactions(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $query = Charge::where('account_id', $user->account_id);

            if ($request->query('status')) {
                $query->where('status', $request->query('status'));
            }

            if ($request->query('date_from')) {
                $query->whereDate('created_at', '>=', $request->query('date_from'));
            }

            if ($request->query('date_to')) {
                $query->whereDate('created_at', '<=', $request->query('date_to'));
            }

            $perPage = min((int) $request->query('per_page', 20), 100);
            $transactions = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $transactions->items(),
                'meta' => [
                    'current_page' => $transactions->currentPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                    'last_page' => $transactions->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AccountController::transactions failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'internal_error', 'message' => 'Erro ao carregar transações'],
            ], 500);
        }
    }

    /**
     * Get dashboard customization settings
     */
    public function getDashboardCustomization(Request $request): JsonResponse
    {
        try {
        $user = $request->user();
        $account = Account::find($user->account_id);

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Conta não encontrada'], 404);
        }

        $settings = $account->settings ?? [];
        $customization = $settings['dashboard_customization'] ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'primary_color'    => $customization['primary_color'] ?? '#9333ea',
                'secondary_color'  => $customization['secondary_color'] ?? '#7c3aed',
                'sidebar_color'    => $customization['sidebar_color'] ?? '#111111',
                'banner_url'       => $customization['banner_url'] ?? null,
                'logo_url'         => $customization['logo_url'] ?? null,
                'favicon_url'      => $customization['favicon_url'] ?? null,
                'platform_name'    => $customization['platform_name'] ?? 'Vector Pagamentos',
                'font_family'      => $customization['font_family'] ?? 'Inter',
                'border_radius'    => $customization['border_radius'] ?? 'rounded',
                'dark_mode_default' => $customization['dark_mode_default'] ?? false,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AccountController::getDashboardCustomization error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Save dashboard customization settings
     */
    public function saveDashboardCustomization(Request $request): JsonResponse
    {
        try {
        $user = $request->user();
        $account = Account::find($user->account_id);

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Conta não encontrada'], 404);
        }

        $validated = $request->validate([
            'primary_color'     => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color'   => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sidebar_color'     => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'banner_url'        => ['nullable', 'string', 'max:500'],
            'logo_url'          => ['nullable', 'string', 'max:500'],
            'favicon_url'       => ['nullable', 'string', 'max:500'],
            'platform_name'     => ['nullable', 'string', 'max:50'],
            'font_family'       => ['nullable', 'string', 'in:Inter,Poppins,Roboto,Nunito,Montserrat,Open Sans'],
            'border_radius'     => ['nullable', 'string', 'in:none,small,rounded,full'],
            'dark_mode_default' => ['nullable', 'boolean'],
        ]);

        $settings = $account->settings ?? [];
        $settings['dashboard_customization'] = array_merge(
            $settings['dashboard_customization'] ?? [],
            array_filter($validated, fn($v) => $v !== null)
        );

        $account->update(['settings' => $settings]);

        return response()->json([
            'success' => true,
            'data' => $settings['dashboard_customization'],
            'message' => 'Personalização salva com sucesso',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AccountController::saveDashboardCustomization error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Get withdrawal IP whitelist
     */
    public function getWithdrawalIpWhitelist(Request $request): JsonResponse
    {
        try {
            $account = Account::find($request->user()->account_id);

            if (!$account) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'account_not_found', 'message' => 'Conta não encontrada'],
                ], 404);
            }

            $settings = $account->settings ?? [];
            $ips = $settings['withdrawal_ip_whitelist'] ?? [];

            return response()->json([
                'success' => true,
                'data' => ['ips' => $ips],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AccountController::getWithdrawalIpWhitelist error', [
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Update withdrawal IP whitelist
     */
    public function updateWithdrawalIpWhitelist(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'ips' => ['required', 'array', 'max:20'],
                'ips.*' => ['required', 'string', 'ip'],
            ], [
                'ips.*.ip' => 'O valor ":input" não é um endereço IP válido.',
            ]);

            $account = Account::find($request->user()->account_id);

            if (!$account) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'account_not_found', 'message' => 'Conta não encontrada'],
                ], 404);
            }

            $settings = $account->settings ?? [];
            $settings['withdrawal_ip_whitelist'] = array_values(array_unique($request->input('ips')));
            $account->settings = $settings;
            $account->save();

            return response()->json([
                'success' => true,
                'data' => ['ips' => $settings['withdrawal_ip_whitelist']],
                'message' => 'IPs autorizados atualizados com sucesso.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'validation_error',
                    'message' => 'Dados inválidos.',
                    'details' => $e->errors(),
                ],
            ], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AccountController::updateWithdrawalIpWhitelist error', [
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }
}
