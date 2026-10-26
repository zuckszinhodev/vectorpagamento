<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Account;
use App\Models\Charge;
use App\Models\AcquirerConfig;
use App\Models\AccountCustomFee;
use App\Models\AccountWallet;
use App\Models\Withdrawal;
use App\Models\AccountAcquirer;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\WalletAdjustment;
use App\Services\AdminApprovalService;
use App\Services\PaymentService;
use App\Services\WithdrawalService;
use App\Domain\Acquirer\Entities\Acquirer as AcquirerEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PragmaRX\Google2FA\Google2FA;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Customer Management — extracted from AdminController
 *
 * Covers: listing, show, status, delete, payment info, fees, charges, balance recalculation.
 */
class CustomerAdminController extends Controller
{
    public function __construct(
        private readonly AdminApprovalService $approvalService,
        private readonly PaymentService $paymentService,
    )
    {
    }

    /**
     * List all customers (accounts with users)
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 20), 100);

        $query = User::with(['account']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = addcslashes($request->input('search'), '%_\\');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%")
                  ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        $users = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Pre-load wallets to avoid N+1 queries
        $accountIds = $users->pluck('account_id')->filter()->unique()->values();
        $wallets = AccountWallet::whereIn('account_id', $accountIds)
            ->where('currency', 'BRL')
            ->pluck('available_balance', 'account_id');

        $data = $users->map(function ($user) use ($wallets) {
            return [
                'id' => $user->id,
                'account_id' => $user->account_id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status ?? 'active',
                'kyc_status' => $user->kyc_status ?? 'pending',
                'available_balance' => (int) ($wallets[$user->account_id] ?? 0),
                'created_at' => $user->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    /**
     * Update customer status
     */
    public function updateStatus(Request $request, string $customerId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,suspended,blocked'],
            'block_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = User::find($customerId);

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
        }

        $updateData = ['status' => $validated['status']];

        if (in_array($validated['status'], ['blocked', 'suspended'])) {
            $updateData['block_reason'] = $validated['block_reason'] ?? null;
            $updateData['blocked_at'] = now();
        } else {
            $updateData['block_reason'] = null;
            $updateData['blocked_at'] = null;
        }

        $user->forceFill($updateData)->save();

        if (in_array($validated['status'], ['blocked', 'suspended'])) {
            cache()->put("user_blocked:{$user->id}", true, now()->addDays(30));
            Log::info('User blocked/suspended by admin', [
                'user_id' => $user->id,
                'status' => $validated['status'],
                'admin_id' => $request->user()->id ?? 'unknown',
            ]);
        } else {
            cache()->forget("user_blocked:{$user->id}");
        }

        return response()->json([
            'success' => true,
            'data' => $user->fresh(),
            'message' => 'Status do cliente atualizado com sucesso',
        ]);
    }

    /**
     * Enable/disable withdrawals for a specific customer account.
     */
    public function updateWithdrawalAccess(Request $request, string $customerId): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $user = User::find($customerId);

        if (!$user || !$user->account_id) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
        }

        $account = Account::find($user->account_id);

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Conta não encontrada'], 404);
        }

        $settings = is_array($account->settings) ? $account->settings : [];
        $settings['withdrawal_enabled'] = (bool) $validated['enabled'];

        $account->settings = $settings;
        $account->save();

        Log::info('Customer withdrawal access updated by admin', [
            'customer_id' => $user->id,
            'account_id' => $account->id,
            'withdrawal_enabled' => (bool) $settings['withdrawal_enabled'],
            'admin_id' => request()->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'customer_id' => $user->id,
                'account_id' => $account->id,
                'withdrawal_enabled' => (bool) $settings['withdrawal_enabled'],
            ],
            'message' => (bool) $settings['withdrawal_enabled']
                ? 'Saques habilitados para a empresa.'
                : 'Saques desabilitados para a empresa.',
        ]);
    }

    /**
     * Delete a customer (hard delete with cascading cleanup)
     */
    public function destroy(Request $request, string $customerId): JsonResponse
    {
        $currentUser = $request->user();

        if ($customerId === $currentUser->id) {
            return response()->json(['success' => false, 'message' => 'Você não pode excluir sua própria conta'], 400);
        }

        $user = User::find($customerId);

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
        }

        $userRole = $user->getRoleName();
        if (in_array($userRole, ['admin', 'owner'])) {
            return response()->json(['success' => false, 'message' => 'Não é possível excluir um administrador'], 403);
        }

        $accountId = $user->account_id;

        DB::beginTransaction();
        try {
            \App\Models\KycActivityLog::where('user_id', $user->id)->forceDelete();
            \App\Models\KycDocument::where('user_id', $user->id)->forceDelete();
            \App\Models\KycSubmission::where('user_id', $user->id)->forceDelete();

            $user->forceDelete();

            if ($accountId) {
                $otherUsersCount = User::withTrashed()->where('account_id', $accountId)->count();
                if ($otherUsersCount === 0) {
                    $account = Account::withTrashed()->find($accountId);
                    if ($account) {
                        DB::table('audit_logs')->where('account_id', $accountId)->delete();
                        DB::table('security_events')->where('account_id', $accountId)->delete();
                        $account->forceDelete();
                    }
                }
            }

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Usuário excluído permanentemente com sucesso']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao excluir usuário: ' . $e->getMessage(), [
                'user_id' => $customerId,
                'account_id' => $accountId ?? null,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['success' => false, 'message' => 'Erro ao excluir usuário'], 500);
        }
    }

    /**
     * Get detailed customer information
     */
    public function show(string $customerId): JsonResponse
    {
        try {
            $user = User::with(['account'])->find($customerId);

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
            }

            try {
                $user->load(['latestKycSubmission', 'kycDocuments']);
            } catch (\Exception $e) {
                Log::warning('Failed to load KYC relations for user', ['user_id' => $customerId, 'error' => $e->getMessage()]);
            }

            // Revenue stats
            $totalRevenue = 0;
            $totalCharges = 0;
            $approvedCharges = 0;
            $pendingCharges = 0;
            $refundedCharges = 0;
            $availableBalance = 0;

            try {
                if ($user->account_id) {
                    $totalRevenue = Charge::where('account_id', $user->account_id)->where('status', 'approved')->sum('amount');
                    $totalCharges = Charge::where('account_id', $user->account_id)->count();
                    $approvedCharges = Charge::where('account_id', $user->account_id)->where('status', 'approved')->count();
                    $pendingCharges = Charge::where('account_id', $user->account_id)->where('status', 'pending')->count();
                    $refundedCharges = Charge::where('account_id', $user->account_id)->where('status', 'refunded')->count();
                    $availableBalance = $this->calculateCorrectBalance($user->account_id);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to load charge stats', ['error' => $e->getMessage()]);
            }

            // Withdrawal info
            $totalWithdrawn = 0;
            $pendingWithdrawals = 0;
            $bankInfo = null;
            $pixInfo = null;
            $allPixKeys = [];
            $withdrawalEnabled = true;

            try {
                $totalWithdrawn = Withdrawal::where('user_id', $user->id)
                    ->whereIn('status', ['completed', 'approved', 'processing'])
                    ->sum('amount');

                $totalWithdrawn += (int) WalletAdjustment::query()
                    ->where('account_id', $user->account_id)
                    ->where('direction', 'debit')
                    ->sum('amount');

                $pendingWithdrawals = Withdrawal::where('user_id', $user->id)
                    ->where('status', 'pending')
                    ->sum('amount');

                // 1. Check account settings (new pix_keys format first, then legacy pix)
                $account = Account::find($user->account_id);
                if ($account) {
                    $accountSettings = $account->settings ?? [];
                    $withdrawalEnabled = (bool) ($accountSettings['withdrawal_enabled'] ?? true);
                    // Check new pix_keys array format (saved from Settings page)
                    if (!empty($accountSettings['pix_keys']) && is_array($accountSettings['pix_keys'])) {
                        $allPixKeys = $accountSettings['pix_keys'];
                        $firstKey = $accountSettings['pix_keys'][0] ?? null;
                        if ($firstKey && !empty($firstKey['pix_key'])) {
                            $pixInfo = [
                                'pix_key' => $firstKey['pix_key'],
                                'pix_type' => $firstKey['pix_type'] ?? 'cpf',
                            ];
                        }
                    }
                    // Fallback to legacy single pix format
                    if (!$pixInfo && !empty($accountSettings['pix']['pix_key'])) {
                        $pixInfo = [
                            'pix_key' => $accountSettings['pix']['pix_key'],
                            'pix_type' => $accountSettings['pix']['pix_type'] ?? null,
                        ];
                    }
                    if (!empty($accountSettings['bank_account']['bank_code'])) {
                        $bankInfo = $accountSettings['bank_account'];
                    }
                }

                // 2. Fallback: withdrawal history
                if (!$pixInfo || !$bankInfo) {
                    $withdrawals = Withdrawal::where('user_id', $user->id)->orderBy('created_at', 'desc')->limit(10)->get();
                    foreach ($withdrawals as $w) {
                        if ($w->destination) {
                            $dest = $w->destination;
                            if (!$bankInfo && ($dest['type'] ?? '') === 'bank_account') {
                                $bankInfo = [
                                    'bank_code' => $dest['bank_code'] ?? null,
                                    'bank_name' => $dest['bank_name'] ?? null,
                                    'agency' => $dest['agency'] ?? null,
                                    'account_number' => $dest['account_number'] ?? null,
                                    'account_type' => $dest['account_type'] ?? null,
                                    'holder_name' => $dest['holder_name'] ?? null,
                                    'holder_document' => $dest['holder_document'] ?? null,
                                ];
                            }
                            if (!$pixInfo && ($dest['type'] ?? '') === 'pix') {
                                $pixInfo = ['pix_key' => $dest['pix_key'] ?? null, 'pix_type' => $dest['pix_type'] ?? null];
                            }
                        }
                        if ($bankInfo && $pixInfo) break;
                    }
                }

                // 3. Fallback: KYC document
                if (!$pixInfo) {
                    try {
                        $kyc = $user->latestKycSubmission;
                        if ($kyc && $kyc->document_number) {
                            $isCompany = $kyc->account_type === 'company';
                            $pixInfo = [
                                'pix_key' => $isCompany ? ($kyc->company_document ?? $kyc->document_number) : $kyc->document_number,
                                'pix_type' => $isCompany ? 'cnpj' : 'cpf',
                                'from_kyc' => true,
                            ];
                        }
                    } catch (\Exception $e) {
                        // Ignore
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed to load withdrawal info', ['error' => $e->getMessage()]);
            }

            // KYC data
            $kycData = null;
            $documents = collect();
            try {
                $kyc = $user->latestKycSubmission;
                if ($kyc) {
                    $kycData = [
                        'id' => $kyc->id,
                        'status' => $kyc->status,
                        'full_name' => $kyc->full_name,
                        'document_number' => $kyc->document_number,
                        'birth_date' => $kyc->birth_date,
                        'phone' => $kyc->phone,
                        'account_type' => $kyc->account_type,
                        'company_name' => $kyc->company_name,
                        'company_document' => $kyc->company_document,
                        'address' => [
                            'street' => $kyc->address_street ?? null,
                            'number' => $kyc->address_number ?? null,
                            'complement' => $kyc->address_complement ?? null,
                            'neighborhood' => $kyc->address_neighborhood ?? null,
                            'city' => $kyc->address_city ?? null,
                            'state' => $kyc->address_state ?? null,
                            'zip_code' => $kyc->address_zip_code ?? null,
                        ],
                        'reviewed_at' => $kyc->reviewed_at,
                        'rejection_reason' => $kyc->rejection_reason,
                        'submitted_at' => $kyc->created_at?->toIso8601String(),
                    ];
                }

                if ($user->relationLoaded('kycDocuments')) {
                    $documents = $user->kycDocuments->map(fn($doc) => [
                        'id' => $doc->id,
                        'type' => $doc->document_type,
                        'file_name' => $doc->file_name,
                        'status' => $doc->status,
                        'file_url' => $doc->file_url ?? null,
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to load KYC data', ['error' => $e->getMessage()]);
            }

            // Recent charges
            $recentCharges = collect();
            try {
                if ($user->account_id) {
                    $recentCharges = Charge::where('account_id', $user->account_id)
                        ->with(['product' => function ($q) { $q->withTrashed()->select('id', 'name'); }])
                        ->orderBy('created_at', 'desc')
                        ->limit(5)
                        ->get(['id', 'amount', 'fee_amount', 'net_amount', 'status', 'payment_method', 'product_id', 'customer', 'description', 'metadata', 'created_at'])
                        ->map(function ($charge) {
                            $customerData = $charge->customer ?? [];
                            $metadata = $charge->metadata ?? [];
                            $productName = $charge->product?->name ?? $charge->description ?? ($metadata['products'][0]['name'] ?? null);
                            return [
                                'id' => $charge->id,
                                'amount' => $charge->amount,
                                'fee_amount' => $charge->fee_amount ?? 0,
                                'net_amount' => $charge->net_amount ?? 0,
                                'status' => $charge->status,
                                'payment_method' => $charge->payment_method,
                                'product_name' => $productName,
                                'customer_name' => $customerData['name'] ?? $customerData['nome'] ?? null,
                                'customer_email' => $customerData['email'] ?? null,
                                'created_at' => $charge->created_at,
                            ];
                        });
                }
            } catch (\Exception $e) {
                Log::warning('Failed to load recent charges', ['error' => $e->getMessage()]);
            }

            // Activity logs
            $logs = [['event' => 'Cadastro', 'date' => $user->created_at?->toIso8601String()]];
            try {
                if ($user->email_verified_at) {
                    $logs[] = ['event' => 'Email verificado', 'date' => $user->email_verified_at instanceof \Carbon\Carbon ? $user->email_verified_at->toIso8601String() : (string) $user->email_verified_at];
                }
                if ($user->kyc_submitted_at) {
                    $logs[] = ['event' => 'KYC enviado', 'date' => $user->kyc_submitted_at instanceof \Carbon\Carbon ? $user->kyc_submitted_at->toIso8601String() : (string) $user->kyc_submitted_at];
                }
                if ($user->kyc_approved_at) {
                    $logs[] = ['event' => 'KYC aprovado', 'date' => $user->kyc_approved_at instanceof \Carbon\Carbon ? $user->kyc_approved_at->toIso8601String() : (string) $user->kyc_approved_at];
                }
                if ($user->last_login_at) {
                    $logs[] = ['event' => 'Último login', 'date' => $user->last_login_at instanceof \Carbon\Carbon ? $user->last_login_at->toIso8601String() : (string) $user->last_login_at, 'ip' => $user->last_login_ip];
                }
            } catch (\Exception $e) {
                Log::warning('Failed to build activity logs', ['error' => $e->getMessage()]);
            }

            usort($logs, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'account_id' => $user->account_id,
                    'name' => $user->name ?? '',
                    'email' => $user->email ?? '',
                    'phone' => $user->phone,
                    'role' => $user->role?->name ?? 'user',
                    'status' => $user->status ?? 'active',
                    'block_reason' => $user->block_reason,
                    'blocked_at' => $user->blocked_at instanceof \Carbon\Carbon ? $user->blocked_at->toIso8601String() : ($user->blocked_at ? (string) $user->blocked_at : null),
                    'kyc_status' => $user->kyc_status ?? 'pending',
                    'mfa_enabled' => (bool)($user->mfa_enabled ?? false),
                    'fraud_risk' => app(\App\Domain\Fraud\Services\SellerFraudRiskService::class)->assess($user->account_id),
                    'created_at' => $user->created_at instanceof \Carbon\Carbon ? $user->created_at->toIso8601String() : ($user->created_at ? (string) $user->created_at : null),
                    'last_login_at' => $user->last_login_at instanceof \Carbon\Carbon ? $user->last_login_at->toIso8601String() : ($user->last_login_at ? (string) $user->last_login_at : null),
                    'last_login_ip' => $user->last_login_ip,
                    'account' => [
                        'name' => $user->account?->name,
                        'document' => $user->account?->document,
                        'document_type' => $user->account?->document_type,
                        'email' => $user->account?->email,
                        'phone' => $user->account?->phone,
                    ],
                    'financials' => [
                        'total_revenue' => $totalRevenue ?? 0,
                        'total_charges' => $totalCharges ?? 0,
                        'approved_charges' => $approvedCharges ?? 0,
                        'available_balance' => $availableBalance ?? 0,
                        'pending_charges' => $pendingCharges ?? 0,
                        'refunded_charges' => $refundedCharges ?? 0,
                        'total_withdrawn' => $totalWithdrawn ?? 0,
                        'pending_withdrawals' => $pendingWithdrawals ?? 0,
                    ],
                    'bank_info' => $bankInfo ?? null,
                    'pix_info' => $pixInfo ?? null,
                    'pix_keys' => $allPixKeys ?? [],
                    'withdrawal_enabled' => $withdrawalEnabled,
                    'kyc' => $kycData ?? null,
                    'kyc_documents' => $documents ?? [],
                    'recent_charges' => $recentCharges ?? [],
                    'logs' => $logs,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('showCustomer fatal error', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar detalhes do cliente',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update customer payment info (PIX/Bank)
     */
    public function updatePaymentInfo(Request $request, string $customerId): JsonResponse
    {
        $user = User::find($customerId);
        if (!$user || !$user->account_id) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
        }

        $validated = $request->validate([
            'pix.pix_key' => ['nullable', 'string', 'max:255'],
            'pix.pix_type' => ['nullable', 'in:cpf,cnpj,email,phone,random'],
            'bank_account.bank_code' => ['nullable', 'string', 'max:10'],
            'bank_account.bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account.agency' => ['nullable', 'string', 'max:20'],
            'bank_account.account_number' => ['nullable', 'string', 'max:30'],
            'bank_account.account_type' => ['nullable', 'in:checking,savings'],
            'bank_account.holder_name' => ['nullable', 'string', 'max:255'],
            'bank_account.holder_document' => ['nullable', 'string', 'max:20'],
        ]);

        $account = Account::find($user->account_id);
        $settings = $account->settings ?? [];

        if (!empty($validated['pix']['pix_key'])) {
            $newPix = [
                'id' => uniqid('pix_'),
                'pix_key' => $validated['pix']['pix_key'],
                'pix_type' => $validated['pix']['pix_type'] ?? 'cpf',
                'label' => 'Principal',
            ];
            // Update pix_keys array: replace first key or add new one
            $existingKeys = $settings['pix_keys'] ?? [];
            if (!empty($existingKeys)) {
                $existingKeys[0] = array_merge($existingKeys[0], [
                    'pix_key' => $validated['pix']['pix_key'],
                    'pix_type' => $validated['pix']['pix_type'] ?? 'cpf',
                ]);
                $settings['pix_keys'] = $existingKeys;
            } else {
                $settings['pix_keys'] = [$newPix];
            }
            // Keep legacy format for backward compatibility
            $settings['pix'] = [
                'pix_key' => $validated['pix']['pix_key'],
                'pix_type' => $validated['pix']['pix_type'] ?? 'cpf',
            ];
        } else {
            unset($settings['pix']);
            $settings['pix_keys'] = [];
        }

        if (!empty($validated['bank_account']['bank_code'])) {
            $settings['bank_account'] = $validated['bank_account'];
        } else {
            unset($settings['bank_account']);
        }

        $account->settings = $settings;
        $account->save();

        return response()->json([
            'success' => true,
            'message' => 'Dados de pagamento atualizados',
            'data' => [
                'pix_info' => $settings['pix'] ?? null,
                'bank_info' => $settings['bank_account'] ?? null,
            ],
        ]);
    }

    // ── Custom Fees ──────────────────────────────────────────────

    public function getCustomFees(string $customerId): JsonResponse
    {
        $user = User::find($customerId);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Usuário não encontrado'], 404);
        }

        $fees = \App\Models\AccountCustomFee::where('account_id', $user->account_id)->first();

        return response()->json(['success' => true, 'data' => $fees]);
    }

    public function updateCustomFees(Request $request, string $customerId): JsonResponse
    {
        $user = User::find($customerId);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Usuário não encontrado'], 404);
        }

        $validated = $request->validate([
            'pix_fee_percent' => 'nullable|numeric|min:0|max:100',
            'pix_fee_fixed' => 'nullable|numeric|min:0',
            'credit_card_fee_percent' => 'nullable|numeric|min:0|max:100',
            'credit_card_fee_fixed' => 'nullable|numeric|min:0',
            'boleto_fee_percent' => 'nullable|numeric|min:0|max:100',
            'boleto_fee_fixed' => 'nullable|numeric|min:0',
            'withdrawal_pix_fee' => 'nullable|numeric|min:0',
            'withdrawal_ted_fee' => 'nullable|numeric|min:0',
            'instant_withdrawal_fee_percent' => 'nullable|numeric|min:0|max:100',
            'antifraude_fee_percent' => 'nullable|numeric|min:0|max:100',
            'antifraude_fee_fixed' => 'nullable|numeric|min:0',
            'installment_fee_percent' => 'nullable|numeric|min:0|max:100',
            'min_withdrawal' => 'nullable|numeric|min:0',
            'holdback_percent' => 'nullable|numeric|min:0|max:100',
            'holdback_days' => 'nullable|integer|min:0|max:365',
            'notes' => 'nullable|string|max:1000',
        ]);

        $approvalCheck = $this->approvalService->requireApproval($request, [
            'action_key' => 'admin.customer.update_fees',
            'resource_type' => 'account',
            'resource_id' => (string) $user->account_id,
            'request_reason' => 'Atualizacao manual de taxas personalizadas',
            'payload' => $validated,
            'expires_minutes' => 30,
        ]);

        if (!($approvalCheck['approved'] ?? false)) {
            return response()->json($approvalCheck['response'], $approvalCheck['http_status'] ?? 202);
        }

        $validated['set_by_user_id'] = $request->user()->id;

        $fees = \App\Models\AccountCustomFee::updateOrCreate(
            ['account_id' => $user->account_id],
            $validated
        );

        return response()->json([
            'success' => true,
            'message' => 'Taxas personalizadas atualizadas com sucesso',
            'data' => $fees->fresh(),
        ]);
    }

    // ── Charges ──────────────────────────────────────────────────

    public function listCharges(Request $request, string $customerId): JsonResponse
    {
        $user = User::find($customerId);
        if (!$user || !$user->account_id) {
            return response()->json(['success' => false, 'message' => 'Usuário não encontrado'], 404);
        }

        $perPage = min($request->input('per_page', 10), 50);
        $status = $request->input('status');

        $query = Charge::where('account_id', $user->account_id)
            ->with(['product' => function ($q) { $q->withTrashed()->select('id', 'name'); }])
            ->orderBy('created_at', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        if ($request->filled('search')) {
            $search = addcslashes($request->input('search'), '%_\\');
            $query->where(function ($q) use ($search) {
                $q->where('id', 'ilike', "%{$search}%")
                  ->orWhere('customer->name', 'ilike', "%{$search}%")
                  ->orWhere('customer->nome', 'ilike', "%{$search}%")
                  ->orWhere('customer->email', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        $charges = $query->paginate($perPage);

        $data = $charges->map(function ($charge) {
            $customerData = $charge->customer ?? [];
            $metadata = $charge->metadata ?? [];
            $productName = $charge->product?->name ?? $charge->description ?? ($metadata['products'][0]['name'] ?? null);
            return [
                'id' => $charge->id,
                'amount' => $charge->amount,
                'fee_amount' => $charge->fee_amount ?? 0,
                'net_amount' => $charge->net_amount ?? 0,
                'status' => $charge->status,
                'payment_method' => $charge->payment_method,
                'product_name' => $productName,
                'customer_name' => $customerData['name'] ?? $customerData['nome'] ?? null,
                'customer_email' => $customerData['email'] ?? null,
                'created_at' => $charge->created_at,
                'paid_at' => $charge->paid_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $charges->currentPage(),
                'per_page' => $charges->perPage(),
                'total' => $charges->total(),
                'last_page' => $charges->lastPage(),
            ],
        ]);
    }

    // ── Charge Detail ───────────────────────────────────────────

    public function showCharge(Request $request, string $customerId, string $chargeId): JsonResponse
    {
        $user = User::find($customerId);
        if (!$user || !$user->account_id) {
            return response()->json(['success' => false, 'message' => 'Usuário não encontrado'], 404);
        }

        $charge = Charge::where('id', $chargeId)
            ->where('account_id', $user->account_id)
            ->with(['transactions', 'product' => function ($q) { $q->withTrashed(); }])
            ->first();

        if (!$charge) {
            return response()->json(['success' => false, 'message' => 'Cobrança não encontrada'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $charge,
        ]);
    }

    public function updateChargeStatus(Request $request, string $customerId, string $chargeId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:processing,approved,refunded'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
            'mfa_code' => ['required', 'digits:6'],
        ]);

        $admin = $request->user();
        if (!$admin || !$admin->mfa_enabled || empty($admin->mfa_secret)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'mfa_required',
                    'message' => 'MFA obrigatorio para alterar status manualmente.',
                ],
            ], 403);
        }

        $google2fa = new Google2FA();
        if (!$google2fa->verifyKey($admin->mfa_secret, $validated['mfa_code'], 1)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'invalid_mfa_code',
                    'message' => 'Codigo 2FA invalido.',
                ],
            ], 422);
        }

        $customer = User::find($customerId);
        if (!$customer || !$customer->account_id) {
            return response()->json(['success' => false, 'message' => 'Usuario nao encontrado'], 404);
        }

        $charge = Charge::where('id', $chargeId)
            ->where('account_id', $customer->account_id)
            ->first();

        if (!$charge) {
            return response()->json(['success' => false, 'message' => 'Cobranca nao encontrada'], 404);
        }

        $newStatus = $validated['status'];
        $oldStatus = (string) $charge->status;

        if ($newStatus === $oldStatus) {
            return response()->json([
                'success' => false,
                'message' => 'A cobranca ja esta com este status.',
            ], 422);
        }

        $statusUpdate = ['status' => $newStatus];
        $wasPreviouslyPaid = !empty($charge->paid_at);
        $previousNetAmount = (int) ($charge->net_amount ?? 0);

        if ($newStatus === 'approved') {
            $statusUpdate['paid_at'] = $charge->paid_at ?? now();
            $statusUpdate['refunded_at'] = null;
            $statusUpdate['cancelled_at'] = null;
            $statusUpdate['failure_reason'] = null;
            $statusUpdate['cancellation_reason'] = null;
        }

        if ($newStatus === 'processing') {
            $statusUpdate['failure_reason'] = null;
            $statusUpdate['cancellation_reason'] = null;
            $statusUpdate['cancelled_at'] = null;
            $statusUpdate['refunded_at'] = null;
        }

        if ($newStatus === 'refunded') {
            $statusUpdate['refunded_at'] = $charge->refunded_at ?? now();
        }

        $metadata = is_array($charge->metadata) ? $charge->metadata : [];
        $metadata['manual_status_override'] = [
            'from' => $oldStatus,
            'to' => $newStatus,
            'reason' => $validated['reason'],
            'changed_by' => [
                'id' => $admin->id,
                'email' => $admin->email,
                'name' => $admin->name,
            ],
            'changed_at' => now()->toIso8601String(),
            'requires_2fa' => true,
        ];
        $statusUpdate['metadata'] = $metadata;

        $txStatusMap = [
            'processing' => 'processing',
            'approved' => 'approved',
            'refunded' => 'refunded',
        ];

        $becameApproved = $newStatus === 'approved' && $oldStatus !== 'approved';
        $leftApproved = $oldStatus === 'approved' && $newStatus !== 'approved' && $previousNetAmount > 0;
        $firstApproval = $becameApproved && !$wasPreviouslyPaid;
        $becameRefunded = $newStatus === 'refunded' && $oldStatus !== 'refunded';
        $walletBalanceAction = $becameApproved ? 'credit' : ($leftApproved ? 'debit' : null);

        DB::transaction(function () use ($charge, $statusUpdate, $newStatus, $txStatusMap, $validated, $becameApproved, $leftApproved, $firstApproval): void {
            $charge->update($statusUpdate);

            $mappedTxStatus = $txStatusMap[$newStatus] ?? null;
            if (!$mappedTxStatus) {
                if ($leftApproved) {
                    $revertedCharge = $charge->fresh();
                    $this->paymentService->updateWalletOnRefund($revertedCharge, (int) $revertedCharge->amount);
                }

                if ($becameApproved) {
                    $approvedCharge = $charge->fresh();
                    if ((int) ($approvedCharge->net_amount ?? 0) > 0) {
                        $approvedCharge->update(['net_amount' => 0]);
                        $approvedCharge->refresh();
                    }
                    $this->paymentService->updateWalletOnPayment($approvedCharge);
                    if ($firstApproval) {
                        $this->paymentService->updateCheckoutOnPayment($approvedCharge);
                    }
                }

                return;
            }

            $transactions = Transaction::where('charge_id', $charge->id)->get();
            foreach ($transactions as $transaction) {
                $txUpdate = ['status' => $mappedTxStatus];

                if ($mappedTxStatus === 'approved') {
                    $txUpdate['processed_at'] = $transaction->processed_at ?? now();
                    if ($transaction->type === 'authorization') {
                        $txUpdate['type'] = 'capture';
                    }
                }

                if (in_array($mappedTxStatus, ['declined', 'failed', 'cancelled'], true)) {
                    $txUpdate['error_message'] = $validated['reason'];
                }

                if ($mappedTxStatus === 'refunded') {
                    $txUpdate['processed_at'] = $transaction->processed_at ?? now();
                }

                $transaction->update($txUpdate);
            }

            if ($leftApproved) {
                $revertedCharge = $charge->fresh();
                $this->paymentService->updateWalletOnRefund($revertedCharge, (int) $revertedCharge->amount);
            }

            if ($becameApproved) {
                $approvedCharge = $charge->fresh();
                if ((int) ($approvedCharge->net_amount ?? 0) > 0) {
                    $approvedCharge->update(['net_amount' => 0]);
                    $approvedCharge->refresh();
                }
                $this->paymentService->updateWalletOnPayment($approvedCharge);
                if ($firstApproval) {
                    $this->paymentService->updateCheckoutOnPayment($approvedCharge);
                }
            }
        });

        $charge->refresh();

        if ($firstApproval) {
            $this->paymentService->dispatchApprovedNotifications($charge);

            Log::warning('ALERTA: liquidacao executada por aprovacao manual de admin', [
                'admin_id' => $admin->id,
                'admin_email' => $admin->email,
                'customer_id' => $customerId,
                'account_id' => $customer->account_id,
                'charge_id' => $charge->id,
                'gross_amount' => $charge->amount,
                'fee_amount' => $charge->fee_amount,
                'net_amount' => $charge->net_amount,
                'wallet_liquidated' => true,
            ]);
        } elseif ($becameRefunded) {
            $this->paymentService->dispatchRefundNotifications($charge);

            Log::warning('ALERTA: estorno executado por alteracao manual de admin', [
                'admin_id' => $admin->id,
                'admin_email' => $admin->email,
                'customer_id' => $customerId,
                'account_id' => $customer->account_id,
                'charge_id' => $charge->id,
                'gross_amount' => $charge->amount,
                'fee_amount' => $charge->fee_amount,
                'net_amount' => $charge->net_amount,
                'wallet_reverted' => $leftApproved,
            ]);
        } elseif ($leftApproved) {
            Log::warning('ALERTA: liquidacao revertida por retorno manual para processamento', [
                'admin_id' => $admin->id,
                'admin_email' => $admin->email,
                'customer_id' => $customerId,
                'account_id' => $customer->account_id,
                'charge_id' => $charge->id,
                'gross_amount' => $charge->amount,
                'fee_amount' => $charge->fee_amount,
                'net_amount' => $charge->net_amount,
                'wallet_reverted' => true,
            ]);
        }

        AuditLog::create([
            'user_id' => $admin->id,
            'account_id' => $customer->account_id,
            'action' => 'admin_charge_status_manual_override',
            'entity_type' => 'charge',
            'entity_id' => $charge->id,
            'old_values' => [
                'status' => $oldStatus,
            ],
            'new_values' => [
                'status' => $newStatus,
                'reason' => $validated['reason'],
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'alert' => true,
                'severity' => 'high',
                'message' => 'Transacao alterada manualmente por administrador com 2FA.',
                'customer_id' => $customerId,
                'charge_id' => $chargeId,
                'requires_2fa' => true,
                'wallet_balance_action' => $walletBalanceAction,
            ],
        ]);

        Log::warning('ALERTA: transacao alterada manualmente por admin', [
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'customer_id' => $customerId,
            'charge_id' => $chargeId,
            'from_status' => $oldStatus,
            'to_status' => $newStatus,
            'reason' => $validated['reason'],
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Status da transacao atualizado com sucesso.',
            'data' => [
                'charge_id' => $charge->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'wallet_balance_action' => $walletBalanceAction,
                'net_amount' => $charge->net_amount,
                'changed_at' => now()->toIso8601String(),
            ],
        ]);
    }

    // ── Balance Recalculation ────────────────────────────────────

    public function recalculateBalance(Request $request, string $customerId): JsonResponse
    {
        try {
            $user = User::find($customerId);
            if (!$user || !$user->account_id) {
                return response()->json(['success' => false, 'message' => 'Usuário não encontrado'], 404);
            }

            $accountId = $user->account_id;

            $approvedCharges = Charge::where('account_id', $accountId)->where('status', 'approved')->get();

            // Pre-load all acquirer configs in a single query (avoids N+1)
            $acquirerConfigs = AcquirerConfig::whereIn(
                'acquirer_key',
                $approvedCharges->pluck('acquirer')->unique()->filter()
            )->get()->keyBy('acquirer_key');
            $customFee = AccountCustomFee::where('account_id', $accountId)->first();

            $totalGross = 0;
            $totalFees = 0;
            $totalNet = 0;

            foreach ($approvedCharges as $charge) {
                // Split charges have no fee — use full amount
                if ($charge->payment_method === 'split') {
                    $feeAmount = 0;
                    $netAmount = $charge->amount;
                } else {
                    $acquirerConfig = $acquirerConfigs->get($charge->acquirer);
                    $paymentMethod = is_string($charge->payment_method)
                        ? trim($charge->payment_method)
                        : '';
                    $customGatewayFee = $paymentMethod !== ''
                        ? $customFee?->calculateGatewayFee($charge->amount, $paymentMethod)
                        : null;
                    if ($acquirerConfig && !is_null($customGatewayFee)) {
                        $feeAmount = $customGatewayFee;
                    } elseif ($acquirerConfig) {
                        $feeAmount = $acquirerConfig->calculateGatewayFee($charge->amount);
                    } else {
                        $feeAmount = (int) round($charge->amount * 0.05) + 200;
                    }
                    $netAmount = $charge->amount - $feeAmount;
                }

                $charge->update(['fee_amount' => $feeAmount, 'net_amount' => $netAmount]);

                $totalGross += $charge->amount;
                $totalFees += $feeAmount;
                $totalNet += $netAmount;
            }

            $lockedWithdrawals = Withdrawal::where('account_id', $accountId)
                ->whereNotIn('status', ['cancelled', 'failed'])
                ->sum('amount');

            $pendingWithdrawals = Withdrawal::where('account_id', $accountId)
                ->whereIn('status', ['pending', 'pending_approval', 'processing'])
                ->sum('amount');

            $manualAdjustments = (int) WalletAdjustment::query()
                ->where('account_id', $accountId)
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN -amount ELSE amount END), 0) AS total")
                ->value('total');

            $correctBalance = $totalNet - $lockedWithdrawals + $manualAdjustments;

            $wallet = AccountWallet::firstOrCreate(
                ['account_id' => $accountId],
                ['currency' => 'BRL', 'available_balance' => 0, 'pending_balance' => 0, 'blocked_balance' => 0]
            );

            $previousBalance = $wallet->available_balance;
            $wallet->update([
                'available_balance' => max(0, $correctBalance),
                'pending_balance' => (int) $pendingWithdrawals,
            ]);

            Log::info('Admin recalculated wallet balance', [
                'admin_id' => $request->user()->id,
                'customer_id' => $customerId,
                'account_id' => $accountId,
                'total_gross' => $totalGross,
                'total_fees' => $totalFees,
                'total_net' => $totalNet,
                'locked_withdrawals' => $lockedWithdrawals,
                'previous_balance' => $previousBalance,
                'new_balance' => max(0, $correctBalance),
                'charges_recalculated' => count($approvedCharges),
                'manual_adjustments' => $manualAdjustments,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Saldo recalculado com sucesso',
                'data' => [
                    'total_gross' => $totalGross,
                    'total_fees' => $totalFees,
                    'total_net' => $totalNet,
                    'locked_withdrawals' => (int) $lockedWithdrawals,
                    'pending_withdrawals' => (int) $pendingWithdrawals,
                    'previous_balance' => (int) $previousBalance,
                    'new_balance' => max(0, $correctBalance),
                    'charges_recalculated' => count($approvedCharges),
                    'manual_adjustments' => $manualAdjustments,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('CustomerAdminController::recalculateBalance error', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId,
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
            return response()->json(['success' => false, 'message' => 'Erro ao recalcular saldo'], 500);
        }
    }

    // ── Private helpers ──────────────────────────────────────────

    private function calculateCorrectBalance(?string $accountId): int
    {
        if (!$accountId) return 0;

        try {
            $approvedCharges = Charge::where('account_id', $accountId)->where('status', 'approved')->get();

            // Pre-load all acquirer configs in a single query (avoids N+1)
            $acquirerConfigs = AcquirerConfig::whereIn(
                'acquirer_key',
                $approvedCharges->pluck('acquirer')->unique()->filter()
            )->get()->keyBy('acquirer_key');
            $customFee = AccountCustomFee::where('account_id', $accountId)->first();

            $totalNet = 0;
            foreach ($approvedCharges as $charge) {
                // Split charges have no fee — use full amount
                if ($charge->payment_method === 'split') {
                    $feeAmount = 0;
                    $netAmount = $charge->amount;
                } else {
                    $acquirerConfig = $charge->acquirer
                        ? $acquirerConfigs->get($charge->acquirer)
                        : null;
                    $paymentMethod = is_string($charge->payment_method)
                        ? trim($charge->payment_method)
                        : '';
                    $customGatewayFee = $paymentMethod !== ''
                        ? $customFee?->calculateGatewayFee($charge->amount, $paymentMethod)
                        : null;
                    if ($acquirerConfig && !is_null($customGatewayFee)) {
                        $feeAmount = $customGatewayFee;
                    } elseif ($acquirerConfig) {
                        $feeAmount = $acquirerConfig->calculateGatewayFee($charge->amount);
                    } else {
                        $feeAmount = (int) round($charge->amount * 0.05) + 200;
                    }
                    $netAmount = $charge->amount - $feeAmount;
                }

                if ((int)($charge->fee_amount ?? 0) !== $feeAmount || (int)($charge->net_amount ?? 0) !== $netAmount) {
                    $charge->update(['fee_amount' => $feeAmount, 'net_amount' => $netAmount]);
                }

                $totalNet += $netAmount;
            }

            $lockedWithdrawals = Withdrawal::where('account_id', $accountId)
                ->whereNotIn('status', ['cancelled', 'failed'])
                ->sum('amount');

            $pendingWithdrawals = Withdrawal::where('account_id', $accountId)
                ->whereIn('status', ['pending', 'pending_approval', 'processing'])
                ->sum('amount');

            $manualAdjustments = (int) WalletAdjustment::query()
                ->where('account_id', $accountId)
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN -amount ELSE amount END), 0) AS total")
                ->value('total');

            $correctBalance = max(0, $totalNet - (int) $lockedWithdrawals + $manualAdjustments);

            $wallet = AccountWallet::firstOrCreate(
                ['account_id' => $accountId],
                ['currency' => 'BRL', 'available_balance' => 0, 'pending_balance' => 0, 'blocked_balance' => 0]
            );

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

            return $correctBalance;
        } catch (\Exception $e) {
            Log::error('Failed to calculate balance', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            // Fallback to wallet balance
            $wallet = AccountWallet::where('account_id', $accountId)->where('currency', 'BRL')->first();
            return (int) ($wallet?->available_balance ?? 0);
        }
    }

    public function adjustWalletBalance(Request $request, string $customerId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'direction' => ['required', 'in:credit,debit'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $user = User::find($customerId);
            if (!$user || !$user->account_id) {
                return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
            }

            $admin = $request->user();
            $accountId = (string) $user->account_id;

            $result = DB::transaction(function () use ($validated, $admin, $user, $accountId, $request) {
                $wallet = AccountWallet::lockForUpdate()->firstOrCreate(
                    ['account_id' => $accountId],
                    ['currency' => 'BRL', 'available_balance' => 0, 'pending_balance' => 0, 'blocked_balance' => 0]
                );

                $beforeBalance = (int) $wallet->available_balance;
                $amount = abs((int) $validated['amount']);
                $signedAmount = $validated['direction'] === 'debit' ? -$amount : $amount;
                $afterBalance = $beforeBalance + $signedAmount;

                if ($afterBalance < 0) {
                    return [
                        'error' => response()->json([
                            'success' => false,
                            'message' => 'O ajuste não pode deixar o saldo negativo.',
                        ], 422),
                    ];
                }

                $adjustment = WalletAdjustment::create([
                    'id' => Str::uuid()->toString(),
                    'account_id' => $accountId,
                    'created_by' => $admin?->id,
                    'amount' => $amount,
                    'direction' => $validated['direction'],
                    'reason' => $validated['reason'],
                    'notes' => $validated['notes'] ?? null,
                    'metadata' => [
                        'customer_id' => $user->id,
                        'customer_email' => $user->email,
                        'source' => 'admin_wallet_adjustment',
                    ],
                ]);

                $wallet->update(['available_balance' => $afterBalance]);

                AuditLog::create([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $admin?->id,
                    'account_id' => $accountId,
                    'action' => 'wallet_balance_adjusted',
                    'entity_type' => 'wallet_adjustment',
                    'entity_id' => $adjustment->id,
                    'old_values' => ['available_balance' => $beforeBalance],
                    'new_values' => [
                        'available_balance' => $afterBalance,
                        'direction' => $validated['direction'],
                        'amount' => $amount,
                    ],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'metadata' => [
                        'reason' => $validated['reason'],
                        'notes' => $validated['notes'] ?? null,
                        'customer_id' => $user->id,
                        'customer_email' => $user->email,
                    ],
                ]);

                return [
                    'wallet' => $wallet->fresh(),
                    'adjustment' => $adjustment,
                ];
            });

            if (isset($result['error'])) {
                return $result['error'];
            }

            return response()->json([
                'success' => true,
                'message' => 'Ajuste de saldo aplicado com sucesso.',
                'data' => [
                    'wallet' => $result['wallet'],
                    'adjustment' => $result['adjustment'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('CustomerAdminController::adjustWalletBalance error', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Erro ao aplicar ajuste de saldo'], 500);
        }
    }

    /**
     * Get acquirers assigned to a customer's account
     */
    public function getAccountAcquirers(string $customerId): JsonResponse
    {
        try {
            $user = User::find($customerId);
            if (!$user || !$user->account_id) {
                return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
            }

            // All available acquirers (synced from acquirer_configs via migration)
            $allAcquirers = AcquirerEntity::orderBy('name')->get();

            // If acquirers table is empty, sync from acquirer_configs on-the-fly
            if ($allAcquirers->isEmpty()) {
                $configs = AcquirerConfig::orderBy('priority', 'asc')->get();
                foreach ($configs as $config) {
                    AcquirerEntity::firstOrCreate(
                        ['slug' => $config->acquirer_key],
                        [
                            'name' => $config->display_name ?? ucfirst($config->acquirer_key),
                            'is_active' => $config->enabled ?? true,
                            'supported_methods' => $config->supported_methods ?? [],
                            'settings' => [],
                        ]
                    );
                }
                $allAcquirers = AcquirerEntity::orderBy('name')->get();
            }

            // Acquirers assigned to this account
            $accountAcquirers = AccountAcquirer::where('account_id', $user->account_id)
                ->get()
                ->keyBy('acquirer_id');

            // Global config — only show globally active acquirers
            $acquirerConfigs = AcquirerConfig::where('enabled', true)->get()->keyBy('acquirer_key');

            // Filter to only acquirers that are active globally
            $activeAcquirers = $allAcquirers->filter(fn ($acq) => $acquirerConfigs->has($acq->slug));

            $result = $activeAcquirers->map(function ($acquirer) use ($accountAcquirers, $acquirerConfigs) {
                $assigned = $accountAcquirers->get($acquirer->id);
                $config = $acquirerConfigs->get($acquirer->slug);

                return [
                    'acquirer_id' => $acquirer->id,
                    'slug' => $acquirer->slug,
                    'name' => $config?->display_name ?? $acquirer->name,
                    'is_active_global' => $config?->enabled ?? $acquirer->is_active,
                    'supported_methods' => $config?->supported_methods ?? $acquirer->supported_methods ?? [],
                    'assigned' => $assigned !== null,
                    'is_active' => $assigned?->is_active ?? false,
                    'priority' => $assigned?->priority ?? $config?->priority ?? 99,
                    'account_acquirer_id' => $assigned?->id,
                ];
            });

            $acctSettings = (array) (optional(Account::find($user->account_id))->settings ?? []);

            return response()->json([
                'success' => true,
                'data' => $result->values(),
                'exclusive' => (bool) ($acctSettings['acquirer_exclusive'] ?? false),
                'exclusive_slug' => $acctSettings['acquirer_exclusive_slug'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('CustomerAdminController::getAccountAcquirers error', ['error' => $e->getMessage(), 'customer_id' => $customerId]);
            return response()->json(['success' => false, 'message' => 'Erro ao carregar adquirentes do cliente'], 500);
        }
    }

    /**
     * Update acquirers for a customer's account
     */
    public function updateAccountAcquirers(Request $request, string $customerId): JsonResponse
    {
        $user = User::find($customerId);
        if (!$user || !$user->account_id) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
        }

        $validated = $request->validate([
            'acquirers' => ['required', 'array'],
            'acquirers.*.acquirer_id' => ['required', 'uuid'],
            'acquirers.*.is_active' => ['required', 'boolean'],
            'acquirers.*.priority' => ['nullable', 'integer', 'min:0'],
        ]);

        $accountId = $user->account_id;

        DB::beginTransaction();
        try {
            foreach ($validated['acquirers'] as $item) {
                $acquirerId = $item['acquirer_id'];
                $isActive = $item['is_active'];
                $priority = $item['priority'] ?? 99;

                if ($isActive) {
                    AccountAcquirer::updateOrCreate(
                        [
                            'account_id' => $accountId,
                            'acquirer_id' => $acquirerId,
                        ],
                        [
                            'is_active' => true,
                            'priority' => $priority,
                            'credentials' => encrypt([]),
                            'settings' => [],
                        ]
                    );
                } else {
                    // Remove or deactivate
                    AccountAcquirer::where('account_id', $accountId)
                        ->where('acquirer_id', $acquirerId)
                        ->delete();
                }
            }

            DB::commit();

            Log::info('Account acquirers updated', [
                'account_id' => $accountId,
                'customer_id' => $customerId,
                'acquirers' => collect($validated['acquirers'])->pluck('acquirer_id'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Adquirentes do cliente atualizados com sucesso',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update account acquirers', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar adquirentes: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ── Fintech seller management ────────────────────────────────

    /**
     * Approve KYC manually, without requiring the seller to submit documents.
     */
    public function approveKyc(Request $request, string $customerId): JsonResponse
    {
        try {
            $user = User::with('account')->find($customerId);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
            }

            $now = now();
            $user->forceFill([
                'kyc_status' => 'approved',
                'kyc_submitted_at' => $user->kyc_submitted_at ?? $now,
                'kyc_approved_at' => $now,
                'kyc_rejection_reason' => null,
            ])->save();

            // Activate the account if it was still pending/suspended.
            if ($user->account && in_array((string) $user->account->status, ['pending', 'suspended', ''], true)) {
                $user->account->update(['status' => 'active']);
            }

            Log::info('Admin approved KYC manually (no documents)', [
                'customer_id' => $user->id,
                'admin_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'KYC aprovado manualmente (sem documentação).',
                'data' => ['kyc_status' => 'approved'],
            ]);
        } catch (\Throwable $e) {
            Log::error('CustomerAdminController::approveKyc error', ['error' => $e->getMessage(), 'customer_id' => $customerId]);
            return response()->json(['success' => false, 'message' => 'Erro ao aprovar KYC'], 500);
        }
    }

    /**
     * Remove / reset the seller's two-factor authentication (2FA).
     * The seller can configure it again from scratch.
     */
    public function resetMfa(Request $request, string $customerId): JsonResponse
    {
        try {
            $user = User::find($customerId);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
            }

            $user->forceFill([
                'mfa_enabled' => false,
                'mfa_secret' => null,
            ])->save();

            Log::info('Admin reset seller MFA/2FA', [
                'customer_id' => $user->id,
                'admin_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Autenticação de dois fatores (2FA) removida. O vendedor pode configurar novamente.',
                'data' => ['mfa_enabled' => false],
            ]);
        } catch (\Throwable $e) {
            Log::error('CustomerAdminController::resetMfa error', ['error' => $e->getMessage(), 'customer_id' => $customerId]);
            return response()->json(['success' => false, 'message' => 'Erro ao remover 2FA'], 500);
        }
    }

    /**
     * Make a single acquirer EXCLUSIVE for this seller: the seller will only
     * use the chosen acquirer (no failover to any other). Toggle off to release.
     * Body: { exclusive: bool, acquirer_id?: uuid (required when exclusive=true) }
     */
    public function setExclusiveAcquirer(Request $request, string $customerId): JsonResponse
    {
        $validated = $request->validate([
            'exclusive' => ['required', 'boolean'],
            'acquirer_id' => ['nullable', 'uuid', 'required_if:exclusive,true'],
        ]);

        $user = User::with('account')->find($customerId);
        if (!$user || !$user->account_id) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
        }

        $account = $user->account;
        $accountId = (string) $user->account_id;

        DB::beginTransaction();
        try {
            $settings = (array) ($account->settings ?? []);

            if ($validated['exclusive']) {
                $acquirer = AcquirerEntity::find($validated['acquirer_id']);
                if (!$acquirer) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Adquirente não encontrada'], 404);
                }

                // Keep ONLY the chosen acquirer active for this account.
                AccountAcquirer::where('account_id', $accountId)
                    ->where('acquirer_id', '!=', $acquirer->id)
                    ->delete();

                AccountAcquirer::updateOrCreate(
                    ['account_id' => $accountId, 'acquirer_id' => $acquirer->id],
                    ['is_active' => true, 'priority' => 0, 'credentials' => encrypt([]), 'settings' => []]
                );

                $settings['acquirer_exclusive'] = true;
                $settings['acquirer_exclusive_slug'] = $acquirer->slug;
            } else {
                $settings['acquirer_exclusive'] = false;
                unset($settings['acquirer_exclusive_slug']);
            }

            $account->update(['settings' => $settings]);
            DB::commit();

            Log::info('Admin set exclusive acquirer for seller', [
                'account_id' => $accountId,
                'exclusive' => $validated['exclusive'],
                'slug' => $settings['acquirer_exclusive_slug'] ?? null,
                'admin_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => $validated['exclusive']
                    ? 'Adquirente exclusiva ativada. Este vendedor usará somente a adquirente escolhida.'
                    : 'Modo exclusivo desativado.',
                'data' => [
                    'exclusive' => (bool) ($settings['acquirer_exclusive'] ?? false),
                    'slug' => $settings['acquirer_exclusive_slug'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('CustomerAdminController::setExclusiveAcquirer error', ['error' => $e->getMessage(), 'customer_id' => $customerId]);
            return response()->json(['success' => false, 'message' => 'Erro ao configurar adquirente exclusiva'], 500);
        }
    }

    // ── Risk controls: limits, rolling reserve, auto-approve ─────

    private function defaultRiskControls(): array
    {
        return [
            'limits' => [
                'enabled' => false,       // master switch: limits only apply when true
                'max_transaction' => 0,   // cents, 0 = unlimited
                'daily_volume' => 0,
                'monthly_volume' => 0,
                'max_withdrawal' => 0,
            ],
            'rolling_reserve' => [
                'enabled' => false,
                'percent' => 0,           // 0-100
                'days' => 30,
            ],
            'withdrawal_auto_approve' => null, // null = default threshold, true = auto, false = always manual
        ];
    }

    public function getRiskControls(string $customerId): JsonResponse
    {
        try {
            $user = User::with('account')->find($customerId);
            if (!$user || !$user->account) {
                return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
            }

            $settings = (array) ($user->account->settings ?? []);
            $defaults = $this->defaultRiskControls();

            $data = [
                'limits' => array_merge($defaults['limits'], (array) ($settings['limits'] ?? [])),
                'rolling_reserve' => array_merge($defaults['rolling_reserve'], (array) ($settings['rolling_reserve'] ?? [])),
                'withdrawal_auto_approve' => $settings['withdrawal_auto_approve'] ?? null,
            ];

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            Log::error('CustomerAdminController::getRiskControls error', ['error' => $e->getMessage(), 'customer_id' => $customerId]);
            return response()->json(['success' => false, 'message' => 'Erro ao carregar controles'], 500);
        }
    }

    public function updateRiskControls(Request $request, string $customerId): JsonResponse
    {
        $validated = $request->validate([
            'limits.enabled' => ['nullable', 'boolean'],
            'limits.max_transaction' => ['nullable', 'integer', 'min:0'],
            'limits.daily_volume' => ['nullable', 'integer', 'min:0'],
            'limits.monthly_volume' => ['nullable', 'integer', 'min:0'],
            'limits.max_withdrawal' => ['nullable', 'integer', 'min:0'],
            'rolling_reserve.enabled' => ['required', 'boolean'],
            'rolling_reserve.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rolling_reserve.days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'withdrawal_auto_approve' => ['nullable', 'boolean'],
        ]);

        try {
            $user = User::with('account')->find($customerId);
            if (!$user || !$user->account) {
                return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
            }

            $account = $user->account;
            $settings = (array) ($account->settings ?? []);

            $settings['limits'] = [
                'enabled' => (bool) ($validated['limits']['enabled'] ?? false),
                'max_transaction' => (int) ($validated['limits']['max_transaction'] ?? 0),
                'daily_volume' => (int) ($validated['limits']['daily_volume'] ?? 0),
                'monthly_volume' => (int) ($validated['limits']['monthly_volume'] ?? 0),
                'max_withdrawal' => (int) ($validated['limits']['max_withdrawal'] ?? 0),
            ];
            $settings['rolling_reserve'] = [
                'enabled' => (bool) $validated['rolling_reserve']['enabled'],
                'percent' => (float) ($validated['rolling_reserve']['percent'] ?? 0),
                'days' => (int) ($validated['rolling_reserve']['days'] ?? 30),
            ];
            $settings['withdrawal_auto_approve'] = array_key_exists('withdrawal_auto_approve', $validated)
                ? $validated['withdrawal_auto_approve']
                : ($settings['withdrawal_auto_approve'] ?? null);

            $account->update(['settings' => $settings]);

            Log::info('Admin updated seller risk controls', [
                'account_id' => $account->id,
                'admin_id' => $request->user()?->id,
            ]);

            return response()->json(['success' => true, 'message' => 'Controles atualizados com sucesso']);
        } catch (\Throwable $e) {
            Log::error('CustomerAdminController::updateRiskControls error', ['error' => $e->getMessage(), 'customer_id' => $customerId]);
            return response()->json(['success' => false, 'message' => 'Erro ao salvar controles'], 500);
        }
    }

    // ── Security: password + API keys ────────────────────────────

    /**
     * Admin forces a password reset. If no password is provided, a strong
     * temporary one is generated and returned (shown once). Also clears the
     * remember token.
     */
    public function resetPassword(Request $request, string $customerId): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
        ]);

        try {
            $user = User::find($customerId);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
            }

            $generated = null;
            $password = $validated['password'] ?? null;
            if (!$password) {
                $generated = Str::password(14, true, true, false);
                $password = $generated;
            }

            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => null,
            ])->save();

            Log::info('Admin reset seller password', [
                'customer_id' => $user->id,
                'admin_id' => $request->user()?->id,
                'generated' => $generated !== null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Senha redefinida com sucesso.',
                'data' => ['temporary_password' => $generated],
            ]);
        } catch (\Throwable $e) {
            Log::error('CustomerAdminController::resetPassword error', ['error' => $e->getMessage(), 'customer_id' => $customerId]);
            return response()->json(['success' => false, 'message' => 'Erro ao redefinir senha'], 500);
        }
    }

    public function getApiKeys(string $customerId): JsonResponse
    {
        try {
            $user = User::find($customerId);
            if (!$user || !$user->account_id) {
                return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
            }

            $keys = ApiKey::where('account_id', $user->account_id)
                ->orderByDesc('created_at')
                ->get(['id', 'name', 'type', 'key_prefix', 'environment', 'is_active', 'last_used_at', 'expires_at', 'created_at']);

            return response()->json(['success' => true, 'data' => $keys]);
        } catch (\Throwable $e) {
            Log::error('CustomerAdminController::getApiKeys error', ['error' => $e->getMessage(), 'customer_id' => $customerId]);
            return response()->json(['success' => false, 'message' => 'Erro ao carregar API keys'], 500);
        }
    }

    public function revokeApiKey(Request $request, string $customerId, string $keyId): JsonResponse
    {
        try {
            $user = User::find($customerId);
            if (!$user || !$user->account_id) {
                return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
            }

            $key = ApiKey::where('account_id', $user->account_id)->where('id', $keyId)->first();
            if (!$key) {
                return response()->json(['success' => false, 'message' => 'API key não encontrada'], 404);
            }

            $key->update(['is_active' => false]);

            Log::info('Admin revoked seller API key', [
                'customer_id' => $user->id,
                'api_key_id' => $keyId,
                'admin_id' => $request->user()?->id,
            ]);

            return response()->json(['success' => true, 'message' => 'API key revogada']);
        } catch (\Throwable $e) {
            Log::error('CustomerAdminController::revokeApiKey error', ['error' => $e->getMessage(), 'customer_id' => $customerId]);
            return response()->json(['success' => false, 'message' => 'Erro ao revogar API key'], 500);
        }
    }

    // ── Impersonation ────────────────────────────────────────────

    /**
     * Generate a JWT token to impersonate a customer (login-as)
     */
    public function impersonate(Request $request, string $customerId): JsonResponse
    {
        try {
            $user = User::find($customerId);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
            }

            $admin = $request->user();
            $token = JWTAuth::fromUser($user);

            Log::warning('Admin impersonating user', [
                'admin_id' => $admin->id,
                'admin_email' => $admin->email,
                'target_user_id' => $user->id,
                'target_email' => $user->email,
                'ip' => $request->ip(),
            ]);

            AuditLog::create([
                'user_id' => $admin->id,
                'account_id' => $admin->account_id,
                'action' => 'impersonate',
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'target_email' => $user->email,
                    'target_name' => $user->name,
                ],
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'token' => $token,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                ],
                'message' => 'Token de impersonação gerado',
            ]);
        } catch (\Throwable $e) {
            Log::error('CustomerAdminController::impersonate error', ['error' => $e->getMessage(), 'customer_id' => $customerId]);
            return response()->json(['success' => false, 'message' => 'Erro ao gerar token de impersonação'], 500);
        }
    }

    // ── Withdrawals List ─────────────────────────────────────────

    /**
     * List withdrawals for a specific customer
     */
    public function listWithdrawals(Request $request, string $customerId): JsonResponse
    {
        try {
            $user = User::find($customerId);
            if (!$user || !$user->account_id) {
                return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
            }

            $perPage = min($request->input('per_page', 15), 50);
            $status = $request->input('status');

            $query = Withdrawal::where('account_id', $user->account_id)
                ->orderBy('created_at', 'desc');

            if ($status && $status !== 'all') {
                $query->where('status', $status);
            }

            $withdrawals = $query->get();

            $adjustments = WalletAdjustment::query()
                ->where('account_id', $user->account_id)
                ->orderBy('created_at', 'desc')
                ->get();

            $items = $withdrawals->map(fn (Withdrawal $withdrawal) => $this->formatCustomerWithdrawalHistoryItem($withdrawal))
                ->concat($adjustments->map(fn (WalletAdjustment $adjustment) => $this->formatCustomerWalletAdjustmentHistoryItem($adjustment)))
                ->sortByDesc('created_at')
                ->values();

            $page = LengthAwarePaginator::resolveCurrentPage();
            $paginated = new LengthAwarePaginator(
                $items->forPage($page, $perPage)->values()->all(),
                $items->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            return response()->json([
                'success' => true,
                'data' => $paginated->items(),
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                    'last_page' => $paginated->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('CustomerAdminController::listWithdrawals error', ['error' => $e->getMessage(), 'customer_id' => $customerId]);
            return response()->json(['success' => false, 'message' => 'Erro ao listar saques'], 500);
        }
    }

    private function formatCustomerWithdrawalHistoryItem(Withdrawal $withdrawal): array
    {
        return [
            'id' => $withdrawal->id,
            'amount' => $withdrawal->amount,
            'fee' => $withdrawal->fee,
            'net_amount' => $withdrawal->net_amount,
            'method' => $withdrawal->method,
            'is_instant' => (bool) $withdrawal->is_instant,
            'status' => $withdrawal->status,
            'acquirer' => $withdrawal->acquirer,
            'acquirer_transfer_id' => $withdrawal->acquirer_transfer_id,
            'destination' => $withdrawal->destination,
            'failure_reason' => WithdrawalService::sanitizeAdminFailureReason($withdrawal->failure_reason),
            'notes' => WithdrawalService::sanitizeAdminFailureReason($withdrawal->notes),
            'created_at' => $withdrawal->created_at->toIso8601String(),
            'processed_at' => $withdrawal->processed_at?->toIso8601String(),
            'history_type' => 'withdrawal',
        ];
    }

    private function formatCustomerWalletAdjustmentHistoryItem(WalletAdjustment $adjustment): array
    {
        $label = $adjustment->direction === 'debit' ? 'Debito manual de saldo' : 'Credito manual de saldo';

        return [
            'id' => $adjustment->id,
            'amount' => $adjustment->amount,
            'fee' => 0,
            'net_amount' => $adjustment->amount,
            'method' => 'manual',
            'is_instant' => false,
            'status' => 'completed',
            'acquirer' => null,
            'acquirer_transfer_id' => null,
            'destination' => [
                'type' => 'manual_adjustment',
                'pix_key' => 'Ajuste manual',
            ],
            'failure_reason' => null,
            'notes' => trim(implode(' - ', array_filter([$label, $adjustment->reason, $adjustment->notes]))),
            'created_at' => $adjustment->created_at->toIso8601String(),
            'processed_at' => $adjustment->created_at->toIso8601String(),
            'history_type' => 'wallet_adjustment',
            'direction' => $adjustment->direction,
            'reason' => $adjustment->reason,
        ];
    }

    // ── Audit Logs ───────────────────────────────────────────────

    /**
     * List audit logs for a specific customer
     */
    public function listAuditLogs(Request $request, string $customerId): JsonResponse
    {
        try {
            $user = User::find($customerId);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
            }

            $page = max((int) $request->input('page', 1), 1);
            $perPage = min((int) $request->input('per_page', 20), 50);

            $auditLogs = AuditLog::where(function ($q) use ($user) {
                $q->where('user_id', $user->id);
                if ($user->account_id) {
                    $q->orWhere('account_id', $user->account_id);
                }
            })
                ->orderBy('created_at', 'desc')
                ->limit(500)
                ->get();

            $auditMapped = $auditLogs->map(function ($log) {
                $metadata = is_array($log->metadata) ? $log->metadata : [];
                $durationMs = isset($metadata['duration_ms']) && is_numeric($metadata['duration_ms'])
                    ? (float) $metadata['duration_ms']
                    : null;
                $enteredAt = (string) ($metadata['entered_at'] ?? $log->created_at->toIso8601String());
                $exitedAt = (string) ($metadata['exited_at'] ?? $log->created_at->toIso8601String());
                $statusCode = isset($metadata['status_code']) ? (int) $metadata['status_code'] : null;
                $securityFlags = isset($metadata['security_flags']) && is_array($metadata['security_flags'])
                    ? $metadata['security_flags']
                    : [];

                return [
                    'id' => $log->id,
                    'source' => 'audit_log',
                    'action' => $log->action,
                    'entity_type' => $log->entity_type,
                    'entity_id' => $log->entity_id,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                    'metadata' => $metadata,
                    'endpoint' => $metadata['path'] ?? null,
                    'method' => $metadata['method'] ?? null,
                    'response_status' => $statusCode,
                    'correlation_id' => $metadata['correlation_id'] ?? null,
                    'security_flags' => $securityFlags,
                    'request_body' => $metadata['request_body'] ?? null,
                    'entered_at' => $enteredAt,
                    'exited_at' => $exitedAt,
                    'duration_ms' => $durationMs,
                    'risk_level' => $this->resolveRiskLevel($statusCode, $durationMs, $securityFlags),
                    'created_at' => $log->created_at->toIso8601String(),
                ];
            });

            $accessLogs = DB::table('admin_access_logs')
                ->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                    if ($user->account_id) {
                        $q->orWhere('account_id', $user->account_id);
                    }
                })
                ->orderBy('accessed_at', 'desc')
                ->limit(500)
                ->get();

            $accessMapped = $accessLogs->map(function ($log) {
                $enteredAt = Carbon::parse((string) $log->accessed_at);
                $durationMs = is_numeric($log->response_time_ms) ? (float) $log->response_time_ms : null;
                $exitedAt = $durationMs !== null
                    ? (clone $enteredAt)->addMilliseconds((int) round($durationMs))
                    : $enteredAt;

                $securityFlags = [];
                if (is_string($log->security_flags) && $log->security_flags !== '') {
                    $decoded = json_decode($log->security_flags, true);
                    if (is_array($decoded)) {
                        $securityFlags = $decoded;
                    }
                } elseif (is_array($log->security_flags)) {
                    $securityFlags = $log->security_flags;
                }

                $requestBody = null;
                if (is_string($log->request_body) && $log->request_body !== '') {
                    $decodedRequestBody = json_decode($log->request_body, true);
                    $requestBody = is_array($decodedRequestBody) ? $decodedRequestBody : $log->request_body;
                }

                $statusCode = isset($log->response_status) ? (int) $log->response_status : null;

                return [
                    'id' => (string) $log->id,
                    'source' => 'admin_access_log',
                    'action' => 'admin_access',
                    'entity_type' => 'admin_endpoint',
                    'entity_id' => null,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'old_values' => null,
                    'new_values' => null,
                    'metadata' => [
                        'actor_role' => $log->actor_role,
                        'security_flags' => $securityFlags,
                    ],
                    'endpoint' => $log->endpoint,
                    'method' => $log->method,
                    'response_status' => $statusCode,
                    'correlation_id' => $log->correlation_id,
                    'security_flags' => $securityFlags,
                    'request_body' => $requestBody,
                    'entered_at' => $enteredAt->toIso8601String(),
                    'exited_at' => $exitedAt->toIso8601String(),
                    'duration_ms' => $durationMs,
                    'risk_level' => $this->resolveRiskLevel($statusCode, $durationMs, $securityFlags),
                    'created_at' => $enteredAt->toIso8601String(),
                ];
            });

            $combined = $auditMapped
                ->concat($accessMapped)
                ->sortByDesc(function ($item) {
                    return $item['entered_at'] ?? $item['created_at'] ?? '';
                })
                ->values();

            $total = $combined->count();
            $lastPage = max((int) ceil($total / $perPage), 1);
            $currentPage = min($page, $lastPage);
            $offset = ($currentPage - 1) * $perPage;
            $data = $combined->slice($offset, $perPage)->values();

            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => [
                    'current_page' => $currentPage,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => $lastPage,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('CustomerAdminController::listAuditLogs error', ['error' => $e->getMessage(), 'customer_id' => $customerId]);
            return response()->json(['success' => false, 'message' => 'Erro ao listar logs'], 500);
        }
    }

    private function resolveRiskLevel(?int $statusCode, ?float $durationMs, array $securityFlags = []): string
    {
        if (($statusCode !== null && $statusCode >= 400) || in_array('http_error_response', $securityFlags, true)) {
            return 'high';
        }

        if (($durationMs !== null && $durationMs > 1500) || in_array('slow_admin_request', $securityFlags, true)) {
            return 'medium';
        }

        return 'low';
    }
}
