<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWithdrawalJob;
use App\Models\Account;
use App\Models\AcquirerConfig;
use App\Models\Charge;
use App\Models\AuditLog;
use App\Models\Withdrawal;
use App\Models\AccountWallet;
use App\Models\SystemSetting;
use App\Models\AccountCustomFee;
use App\Services\AdminApprovalService;
use App\Services\WithdrawalService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WithdrawalController extends Controller
{
    public function __construct(private readonly AdminApprovalService $approvalService)
    {
    }

    /**
     * List all withdrawals
     */
    public function index(Request $request): JsonResponse
    {
        $accountId = $request->user()->account_id;
        $perPage = min($request->input('per_page', 20), 100);

        $query = Withdrawal::where('account_id', $accountId);

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('method')) {
            $query->where('method', $request->input('method'));
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }

        $withdrawals = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $withdrawals->items(),
            'meta' => [
                'current_page' => $withdrawals->currentPage(),
                'per_page' => $withdrawals->perPage(),
                'total' => $withdrawals->total(),
                'last_page' => $withdrawals->lastPage(),
            ],
        ]);
    }

    /**
     * Create a new withdrawal request
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'method' => ['required', 'in:pix,bank_transfer'],
            'mfa_code' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'idempotency_key' => ['sometimes', 'string', 'max:120'],
            'is_instant' => ['sometimes', 'boolean'],
            'destination' => ['required', 'array'],
            'destination.type' => ['required', 'in:pix,bank_account'],
            'destination.pix_key' => ['required_if:destination.type,pix', 'string'],
            'destination.pix_type' => ['required_if:destination.type,pix', 'in:cpf,cnpj,email,phone,random'],
            'destination.bank_code' => ['required_if:destination.type,bank_account', 'string'],
            'destination.bank_name' => ['required_if:destination.type,bank_account', 'string'],
            'destination.agency' => ['required_if:destination.type,bank_account', 'string'],
            'destination.account_number' => ['required_if:destination.type,bank_account', 'string'],
            'destination.account_type' => ['required_if:destination.type,bank_account', 'in:checking,savings'],
            'destination.holder_name' => ['required_if:destination.type,bank_account', 'string'],
            'destination.holder_document' => ['required_if:destination.type,bank_account', 'string'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $accountId = $request->user()->account_id;
        // For API key auth, user()->id equals account_id (not a real user).
        // Use account owner's user_id when authenticated via API key.
        $authUser = $request->user();
        $ownerUser = null;

        $withdrawalsEnabled = (bool) SystemSetting::get('withdrawals_enabled', true);
        if (!$withdrawalsEnabled) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'withdrawals_temporarily_disabled',
                    'message' => 'Os saques estão temporariamente indisponíveis. Tente novamente mais tarde.',
                ],
            ], 503);
        }

        // ── KYC Verification (mandatory for all withdrawal paths) ─────────────
        if (($authUser->type ?? null) === 'api_key') {
            // For API key auth: look up real account KYC status
            $account = \App\Models\Account::find($accountId);
            if (!$account || ($account->kyc_status ?? '') !== 'approved') {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code'       => 'kyc_required',
                        'message'    => 'A conta precisa completar a verificação KYC antes de realizar saques via API.',
                        'kyc_status' => $account?->kyc_status ?? 'unknown',
                    ],
                ], 403);
            }
        } else {
            if ($authUser->kyc_status !== 'approved') {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code'       => 'kyc_required',
                        'message'    => 'Você precisa completar a verificação KYC antes de realizar saques.',
                        'kyc_status' => $authUser->kyc_status,
                    ],
                ], 403);
            }
        }

        // ── MFA / Second-factor Verification ─────────────────────────────────
        if (($authUser->type ?? null) === 'api_key') {
            // For API key auth: enforce non-empty IP whitelist as second factor.
            // A stolen key without IP restrictions would allow unchecked withdrawals.
            $account = $account ?? \App\Models\Account::find($accountId);
            $allowedIps = ($account?->settings ?? [])['withdrawal_ip_whitelist'] ?? [];

            if (empty($allowedIps)) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code'    => 'ip_whitelist_required',
                        'message' => 'Saques via API Key requerem uma lista de IPs autorizados configurada. Configure os IPs permitidos nas configurações da conta.',
                    ],
                ], 403);
            }

            if (!in_array($request->ip(), $allowedIps, true)) {
                Log::warning('Withdrawal blocked: IP not in whitelist', [
                    'account_id' => $accountId,
                    'request_ip' => $request->ip(),
                    'allowed_ips' => $allowedIps,
                ]);
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code'    => 'ip_not_allowed',
                        'message' => 'O IP deste servidor não está autorizado para realizar saques. Configure os IPs permitidos nas configurações.',
                    ],
                ], 403);
            }

            $ownerUser = \App\Models\User::where('account_id', $accountId)->first();
            $userId = $ownerUser ? $ownerUser->id : $accountId;
        } else {
            if (!$authUser->mfa_enabled || empty($authUser->mfa_secret)) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code'    => 'mfa_required',
                        'message' => 'Você precisa ativar a verificação em dois fatores (2FA) nas configurações antes de realizar saques.',
                    ],
                ], 403);
            }

            $google2fa = new \PragmaRX\Google2FA\Google2FA();
            $valid = $google2fa->verifyKey($authUser->mfa_secret, $validated['mfa_code'], 1);

            if (!$valid) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code'    => 'mfa_invalid',
                        'message' => 'Código de verificação 2FA inválido. Tente novamente.',
                    ],
                ], 422);
            }

            $userId = $authUser->id;
        }

        $isAdminUser = $this->isAdminWithdrawalUser($authUser, (string) $accountId, $ownerUser);
        $isInstant = $validated['is_instant'] ?? false;

        $idempotencyKey = trim((string) (
            $validated['idempotency_key']
            ?? $request->header('X-Idempotency-Key')
            ?? $request->header('Idempotency-Key')
            ?? ''
        ));

        if ($idempotencyKey !== '' && (strlen($idempotencyKey) > 120 || preg_match('/[\x00-\x1F\x7F]/', $idempotencyKey))) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'invalid_idempotency_key',
                    'message' => 'A chave de idempotência é inválida.',
                ],
            ], 400);
        }

        $idempotencyRequestHash = $idempotencyKey !== ''
            ? $this->buildWithdrawalIdempotencyHash($validated)
            : null;

        $withIdempotencyHeaders = function (JsonResponse $response, bool $replayed = false) use ($idempotencyKey): JsonResponse {
            if ($idempotencyKey === '') {
                return $response;
            }

            $response->headers->set('X-Idempotency-Key', $idempotencyKey);
            if ($replayed) {
                $response->headers->set('X-Idempotency-Replayed', 'true');
            }

            return $response;
        };

        if ($idempotencyKey !== '') {
            $existingWithdrawal = Withdrawal::where('account_id', $accountId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingWithdrawal) {
                if (!empty($existingWithdrawal->idempotency_request_hash)
                    && $existingWithdrawal->idempotency_request_hash !== $idempotencyRequestHash) {
                    return $withIdempotencyHeaders(response()->json([
                        'success' => false,
                        'error' => [
                            'code' => 'idempotency_key_reused_with_different_payload',
                            'message' => 'A mesma chave de idempotência já foi usada com dados diferentes.',
                        ],
                    ], 409));
                }

                return $withIdempotencyHeaders(response()->json([
                    'success' => true,
                    'data' => $existingWithdrawal,
                    'message' => 'Solicitação de saque já recebida anteriormente.',
                ], 200), true);
            }
        }

        // Verify destination has valid payment info (from the request itself)
        $dest = $validated['destination'] ?? [];
        $destHasPix = ($dest['type'] ?? '') === 'pix' && !empty($dest['pix_key']);
        $destHasBank = ($dest['type'] ?? '') === 'bank_account' && !empty($dest['bank_code']);

        if (!$destHasPix && !$destHasBank) {
            // Fallback: check if account has pre-registered payment data
            $account = \App\Models\Account::find($accountId);
            $accountSettings = $account ? ($account->settings ?? []) : [];
            $pixKeys = $accountSettings['pix_keys'] ?? [];
            $legacyPix = $accountSettings['pix']['pix_key'] ?? null;
            $hasBankAccount = !empty($accountSettings['bank_account']['bank_code']);

            if (empty($pixKeys) && empty($legacyPix) && !$hasBankAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Informe uma chave PIX ou dados bancários para o saque.',
                ], 422);
            }
        }

        // Note: is_instant flag is no longer required.
        // Withdrawal fee is applied automatically when enabled in system settings.

        // Get wallet balance
        $wallet = AccountWallet::firstOrCreate(
            ['account_id' => $accountId],
            [
                'currency' => 'BRL',
                'available_balance' => 0,
                'pending_balance' => 0,
                'blocked_balance' => 0,
            ]
        );
        $availableBalance = (float) $wallet->available_balance;

        if ($isAdminUser) {
            $availableBalance = (float) $this->calculateAdminGatewayAvailableBalance((string) $accountId);

            $pendingWithdrawalBalance = (int) Withdrawal::where('account_id', $accountId)
                ->whereIn('status', ['pending', 'pending_approval', 'processing'])
                ->sum('amount');

            $walletUpdates = [];
            if ((int) $wallet->available_balance !== (int) $availableBalance) {
                $walletUpdates['available_balance'] = (int) $availableBalance;
            }
            if ((int) $wallet->pending_balance !== $pendingWithdrawalBalance) {
                $walletUpdates['pending_balance'] = $pendingWithdrawalBalance;
            }

            if ($walletUpdates !== []) {
                $wallet->update($walletUpdates);
                $wallet->refresh();
            }
        }

        $amount = (float) $validated['amount'];

        $minAllowed = $isAdminUser ? 0.0 : (float) SystemSetting::get('withdrawal_min_amount', 1000);
        $maxAllowed = (float) SystemSetting::get('withdrawal_max_amount', 0);

        if ($amount < $minAllowed) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'withdrawal_below_minimum',
                    'message' => 'Valor abaixo do mínimo permitido para saque.',
                    'min_amount' => $minAllowed,
                ],
            ], 422);
        }

        if ($maxAllowed > 0 && $amount > $maxAllowed) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'withdrawal_above_maximum',
                    'message' => 'Valor acima do máximo permitido para saque.',
                    'max_amount' => $maxAllowed,
                ],
            ], 422);
        }

        // Check if has enough balance
        if ($amount > $availableBalance) {
            return response()->json([
                'success' => false,
                'message' => 'Saldo insuficiente para saque',
                'data' => [
                    'requested' => $amount,
                    'available' => $availableBalance,
                ],
            ], 422);
        }

        // Calculate fee dynamically from system settings
        $fee = $isAdminUser ? 0.0 : $this->calculateWithdrawalFee($amount, $validated['method'], $isInstant, $accountId);
        $netAmount = $amount - $fee;

        if ($netAmount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Valor líquido deve ser maior que zero',
            ], 422);
        }

        // ── Withdrawal Security Controls (Fintech-level) ──────────────

        // Daily withdrawal limit per account (default: R$ 50.000,00 = 5000000 cents)
        $dailyLimit = (float) SystemSetting::get('daily_withdrawal_limit', 5000000);
        $dailyTotal = (float) Withdrawal::where('account_id', $accountId)
            ->whereDate('created_at', today())
            ->whereNotIn('status', ['cancelled', 'failed'])
            ->sum('amount');

        if ($dailyLimit > 0 && ($dailyTotal + $amount) > $dailyLimit) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'daily_limit_exceeded',
                    'message' => 'Limite diário de saque excedido.',
                    'daily_limit' => $dailyLimit,
                    'daily_used' => $dailyTotal,
                    'remaining' => max(0, $dailyLimit - $dailyTotal),
                ],
            ], 422);
        }

        $maxPendingWithdrawals = (int) SystemSetting::get('max_pending_withdrawals_per_account', 10);
        if ($maxPendingWithdrawals > 0) {
            $openWithdrawals = Withdrawal::where('account_id', $accountId)
                ->whereIn('status', ['pending', 'pending_approval', 'processing'])
                ->count();

            if ($openWithdrawals >= $maxPendingWithdrawals) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'too_many_open_withdrawals',
                        'message' => 'Limite de saques em aberto atingido para a conta.',
                        'open_withdrawals' => $openWithdrawals,
                        'max_open_withdrawals' => $maxPendingWithdrawals,
                    ],
                ], 429);
            }
        }

        // Large withdrawal threshold — requires admin approval (default: R$ 10.000,00)
        $largeWithdrawalThreshold = (float) SystemSetting::get('large_withdrawal_threshold', 1000000);
        $requiresAdminApproval = $amount >= $largeWithdrawalThreshold;

        // Cooldown: prevent rapid consecutive withdrawals (minimum 60s between)
        $lastWithdrawal = Withdrawal::where('account_id', $accountId)
            ->whereNotIn('status', ['cancelled', 'failed'])
            ->orderBy('created_at', 'desc')
            ->first();

        $cooldownSeconds = max(0, (int) SystemSetting::get('withdrawal_cooldown_seconds', 60));

        if ($cooldownSeconds > 0 && $lastWithdrawal && $lastWithdrawal->created_at->diffInSeconds(now()) < $cooldownSeconds) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'withdrawal_cooldown',
                    'message' => "Aguarde pelo menos {$cooldownSeconds} segundos entre saques.",
                ],
            ], 429);
        }

        try {
            DB::beginTransaction();

            // Lock wallet row to prevent concurrent withdrawals
            $wallet = AccountWallet::lockForUpdate()->where('account_id', $accountId)->first();
            $availableBalance = $wallet ? (float) $wallet->available_balance : 0;

            if ($isAdminUser) {
                $availableBalance = (float) $this->calculateAdminGatewayAvailableBalance((string) $accountId);
                if ($wallet && (int) $wallet->available_balance !== (int) $availableBalance) {
                    $wallet->update(['available_balance' => (int) $availableBalance]);
                    $wallet->refresh();
                }
            }

            // Re-check balance under lock (another withdrawal may have completed)
            if ($amount > $availableBalance) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Saldo insuficiente para saque',
                    'data' => [
                        'requested' => $amount,
                        'available' => $availableBalance,
                    ],
                ], 422);
            }

            // Create withdrawal
            try {
                $withdrawal = Withdrawal::create([
                    'id' => Str::uuid()->toString(),
                    'account_id' => $accountId,
                    'user_id' => $userId,
                    'amount' => $amount,
                    'fee' => $fee,
                    'net_amount' => $netAmount,
                    'status' => $requiresAdminApproval ? 'pending_approval' : 'pending',
                    'method' => $validated['method'],
                    'is_instant' => $isInstant,
                    'destination' => $validated['destination'],
                    'notes' => $validated['notes'] ?? null,
                    'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                    'idempotency_request_hash' => $idempotencyKey !== '' ? $idempotencyRequestHash : null,
                ]);
            } catch (QueryException $exception) {
                if ($idempotencyKey !== '') {
                    $existingWithdrawal = Withdrawal::where('account_id', $accountId)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($existingWithdrawal) {
                        DB::rollBack();

                        if (!empty($existingWithdrawal->idempotency_request_hash)
                            && $existingWithdrawal->idempotency_request_hash !== $idempotencyRequestHash) {
                            return $withIdempotencyHeaders(response()->json([
                                'success' => false,
                                'error' => [
                                    'code' => 'idempotency_key_reused_with_different_payload',
                                    'message' => 'A mesma chave de idempotência já foi usada com dados diferentes.',
                                ],
                            ], 409));
                        }

                        return $withIdempotencyHeaders(response()->json([
                            'success' => true,
                            'data' => $existingWithdrawal,
                            'message' => 'Solicitação de saque já recebida anteriormente.',
                        ], 200), true);
                    }
                }

                throw $exception;
            }

            // Debit from wallet (move to pending)
            if ($wallet) {
                $wallet->decrement('available_balance', $amount);
                $wallet->increment('pending_balance', $amount);
            }

            // Log large withdrawal for admin audit
            if ($requiresAdminApproval) {
                Log::warning('Large withdrawal requires admin approval', [
                    'withdrawal_id' => $withdrawal->id,
                    'account_id' => $accountId,
                    'amount' => $amount,
                    'threshold' => $largeWithdrawalThreshold,
                ]);
            }

            // Auto-save PIX/bank info to account settings for future lookups
            try {
                $account = \App\Models\Account::find($accountId);
                if ($account) {
                    $settings = $account->settings ?? [];
                    $dest = $validated['destination'];

                    if (($dest['type'] ?? '') === 'pix' && !empty($dest['pix_key'])) {
                        $settings['pix'] = [
                            'pix_key' => $dest['pix_key'],
                            'pix_type' => $dest['pix_type'] ?? 'cpf',
                        ];
                    }

                    if (($dest['type'] ?? '') === 'bank_account' && !empty($dest['bank_code'])) {
                        $settings['bank_account'] = [
                            'bank_code' => $dest['bank_code'],
                            'bank_name' => $dest['bank_name'] ?? '',
                            'agency' => $dest['agency'] ?? '',
                            'account_number' => $dest['account_number'] ?? '',
                            'account_type' => $dest['account_type'] ?? 'checking',
                            'holder_name' => $dest['holder_name'] ?? '',
                            'holder_document' => $dest['holder_document'] ?? '',
                        ];
                    }

                    $account->settings = $settings;
                    $account->save();
                }
            } catch (\Exception $e) {
                // Non-critical: don't fail the withdrawal if settings save fails
                \Log::warning('Failed to auto-save payment info to account settings', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
            }

            DB::commit();

            // ── Auto processing ────────────────────────────────────────
            // Large withdrawals NEVER auto-process — they need admin approval first.
            if ($requiresAdminApproval) {
                return $withIdempotencyHeaders(response()->json([
                    'success' => true,
                    'data' => $withdrawal,
                    'message' => 'Saque criado e aguardando aprovação da administração (valor acima do limite).',
                ], 201));
            }

            // Operational policy: process every non-approved withdrawal immediately.
            // This prevents withdrawals from remaining in pending due to stale toggles.
            $shouldProcess = true;

            if ($shouldProcess) {
                $withdrawal->update([
                    'auto_process' => true,
                    'is_instant' => true,
                ]);

                // Process instantly (sync) for fastest UX
                try {
                    $withdrawalService = app(WithdrawalService::class);
                    $result = $withdrawalService->processWithdrawal($withdrawal);

                    Log::info('Withdrawal auto processing result', [
                        'withdrawal_id' => $withdrawal->id,
                        'method'        => $validated['method'],
                        'success'       => $result['success'],
                        'transfer_id'   => $result['transfer_id'] ?? null,
                    ]);

                    $withdrawal->refresh();

                    return $withIdempotencyHeaders(response()->json([
                        'success' => true,
                        'data'    => $withdrawal,
                        'processing' => $result,
                        'message' => $result['success']
                            ? 'Saque enviado via PIX com sucesso'
                            : 'Saque criado, mas o processamento falhou: ' . ($result['error'] ?? ''),
                    ], 201));
                } catch (\Exception $processEx) {
                    Log::error('Withdrawal auto processing exception', [
                        'withdrawal_id' => $withdrawal->id,
                        'error'         => $processEx->getMessage(),
                    ]);

                    // Fallback: queue a recovery processing job so withdrawals do not remain stuck in pending.
                    try {
                        ProcessWithdrawalJob::dispatch($withdrawal->fresh() ?? $withdrawal);

                        Log::warning('Withdrawal queued for recovery processing after auto exception', [
                            'withdrawal_id' => $withdrawal->id,
                        ]);

                        return $withIdempotencyHeaders(response()->json([
                            'success' => true,
                            'data' => $withdrawal->fresh(),
                            'message' => 'Saque criado. O processamento imediato falhou e foi encaminhado para reprocessamento automático.',
                        ], 201));
                    } catch (\Throwable $queueEx) {
                        Log::error('Failed to queue withdrawal recovery job', [
                            'withdrawal_id' => $withdrawal->id,
                            'error' => $queueEx->getMessage(),
                        ]);
                    }
                }
            }

            return $withIdempotencyHeaders(response()->json([
                'success' => true,
                'data' => $withdrawal,
                'message' => 'Solicitação de saque criada com sucesso',
            ], 201));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar solicitação de saque',
            ], 500);
        }
    }

    /**
     * Get a specific withdrawal
     */
    public function show(Request $request, string $withdrawalId): JsonResponse
    {
        $withdrawal = Withdrawal::where('account_id', $request->user()->account_id)
            ->find($withdrawalId);

        if (!$withdrawal) {
            return response()->json([
                'success' => false,
                'message' => 'Saque não encontrado',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $withdrawal,
        ]);
    }

    /**
     * Cancel a pending withdrawal
     */
    public function cancel(Request $request, string $withdrawalId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $withdrawal = Withdrawal::where('account_id', $request->user()->account_id)
            ->find($withdrawalId);

        if (!$withdrawal) {
            return response()->json([
                'success' => false,
                'message' => 'Saque não encontrado',
            ], 404);
        }

        if (!$withdrawal->canBeCancelled()) {
            return response()->json([
                'success' => false,
                'message' => 'Este saque não pode ser cancelado',
            ], 422);
        }

        if ($withdrawal->status === 'processing' && !empty($withdrawal->acquirer_transfer_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Este saque já foi enviado para processamento externo e não pode ser cancelado automaticamente.',
            ], 422);
        }

        try {
            DB::beginTransaction();

            $reason = trim((string) ($validated['reason'] ?? ''));
            if ($reason === '') {
                $reason = 'Cancelado pelo usuário';
            }

            $withdrawal->update([
                'status' => 'cancelled',
                'processed_at' => now(),
                'failure_reason' => $reason,
                'notes' => $reason,
            ]);

            // Return amount to wallet
            $wallet = AccountWallet::where('account_id', $withdrawal->account_id)->first();
            if ($wallet) {
                $wallet->decrement('pending_balance', $withdrawal->amount);
                $wallet->increment('available_balance', $withdrawal->amount);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $withdrawal->fresh(),
                'cancel_reason' => $reason,
                'message' => 'Saque cancelado com sucesso',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erro ao cancelar saque',
            ], 500);
        }
    }

    /**
     * Get withdrawal summary/stats
     */
    public function summary(Request $request): JsonResponse
    {
        $accountId = $request->user()->account_id;
        $isAdminUser = $this->isAdminWithdrawalUser($request->user(), (string) $accountId);

        // Get or create wallet
        $wallet = AccountWallet::firstOrCreate(
            ['account_id' => $accountId],
            [
                'currency' => 'BRL',
                'available_balance' => 0,
                'pending_balance' => 0,
                'blocked_balance' => 0,
            ]
        );

        if ($isAdminUser) {
            $correctBalance = $this->calculateAdminGatewayAvailableBalance((string) $accountId);

            $pendingWithdrawalBalance = (int) Withdrawal::where('account_id', $accountId)
                ->whereIn('status', ['pending', 'pending_approval', 'processing'])
                ->sum('amount');
        } else {
            // Always recalculate correct balance from approved charges with proper fees
            $approvedCharges = Charge::where('account_id', $accountId)
                ->where('status', 'approved')
                ->with('transactions.splits')
                ->get();

            // Pre-load all acquirer configs in a single query (avoids N+1)
            $acquirerConfigs = AcquirerConfig::whereIn(
                'acquirer_key',
                $approvedCharges->pluck('acquirer')->unique()->filter()
            )->get()->keyBy('acquirer_key');
            $customFee = AccountCustomFee::where('account_id', $accountId)->first();

            $totalNet = 0;
            foreach ($approvedCharges as $charge) {
                // Split charges already have net_amount set — no additional fee calculation
                if ($charge->payment_method === 'split') {
                    $totalNet += $charge->net_amount ?? $charge->amount;
                    continue;
                }

                $acquirerConfig = $acquirerConfigs->get($charge->acquirer);
                $customGatewayFee = $customFee?->calculateGatewayFee($charge->amount, $charge->payment_method);
                if ($acquirerConfig && !is_null($customGatewayFee)) {
                    $fee = $customGatewayFee + $acquirerConfig->calculateAcquirerFee($charge->amount);
                } elseif ($acquirerConfig) {
                    $fee = $acquirerConfig->calculateGatewayFee($charge->amount);
                } else {
                    $fee = (int) round($charge->amount * 0.05) + 200;
                }
                $chargeNet = $charge->amount - $fee;

                // Deduct splits sent to OTHER recipients from this charge's net (eager-loaded)
                $transaction = $charge->transactions->first();
                if ($transaction) {
                    $splitsToOthers = (int) $transaction->splits
                        ->where('recipient_id', '!=', $accountId)
                        ->whereIn('status', ['transferred', 'processing', 'pending'])
                        ->sum('net_amount');
                    $chargeNet -= $splitsToOthers;
                }

                $totalNet += max(0, $chargeNet);
            }

            $lockedWithdrawals = (int) Withdrawal::where('account_id', $accountId)
                ->whereNotIn('status', ['cancelled', 'failed'])
                ->sum('amount');

            $pendingWithdrawalBalance = (int) Withdrawal::where('account_id', $accountId)
                ->whereIn('status', ['pending', 'pending_approval', 'processing'])
                ->sum('amount');

            $correctBalance = max(0, $totalNet - $lockedWithdrawals);
        }

        $walletUpdates = [];
        if ((int) $wallet->available_balance !== $correctBalance) {
            $walletUpdates['available_balance'] = $correctBalance;
        }
        if ((int) $wallet->pending_balance !== $pendingWithdrawalBalance) {
            $walletUpdates['pending_balance'] = $pendingWithdrawalBalance;
        }

        if ($walletUpdates !== []) {
            $wallet->update($walletUpdates);
            $wallet->refresh();
        }

        // Calculate stats
        $totalWithdrawn = Withdrawal::where('account_id', $accountId)
            ->where('status', 'completed')
            ->sum('net_amount');

        $pendingAmount = Withdrawal::where('account_id', $accountId)
            ->whereIn('status', ['pending', 'pending_approval', 'processing'])
            ->sum('amount');

        $thisMonthWithdrawn = Withdrawal::where('account_id', $accountId)
            ->where('status', 'completed')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('net_amount');

        $recentWithdrawals = Withdrawal::where('account_id', $accountId)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        // Cobranças pendentes (charges com status pending/processing)
        $pendingCharges = \App\Models\Charge::where('account_id', $accountId)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

        // Saldo bloqueado por chargebacks
        $blockedBalance = (int) $wallet->blocked_balance;

        return response()->json([
            'success' => true,
            'data' => [
                'available_balance' => (int) $wallet->available_balance,
                'pending_balance' => (int) $pendingCharges + $blockedBalance,
                'blocked_balance' => $blockedBalance,
                'total_balance' => (int) $wallet->total_balance,
                'total_withdrawn' => (int) $totalWithdrawn,
                'pending_withdrawals' => (int) $pendingAmount,
                'this_month_withdrawn' => (int) $thisMonthWithdrawn,
                'recent_withdrawals' => $recentWithdrawals,
            ],
        ]);
    }

    /**
     * Admin: List all withdrawals across all accounts
     */
    public function adminList(Request $request): JsonResponse
    {
        try {
            $perPage = min($request->input('per_page', 20), 100);

            $query = Withdrawal::with(['account.users']);

            // Filters
            if ($request->filled('status')) {
                $status = $request->input('status');
                if ($status === 'all') {
                    // No filter — return all statuses
                } else {
                    $query->where('status', $status);
                }
            }

            // Search by customer name or email
            if ($request->filled('search')) {
                $search = addcslashes($request->input('search'), '%_\\');
                $query->where(function ($q) use ($search) {
                    $q->where('id', 'ilike', "%{$search}%")
                      ->orWhereHas('account.users', function ($sub) use ($search) {
                          $sub->where('name', 'ilike', "%{$search}%")
                              ->orWhere('email', 'ilike', "%{$search}%");
                      });
                });
            }

            if ($request->filled('method')) {
                $query->where('method', $request->input('method'));
            }

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->input('date_from'));
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->input('date_to'));
            }

            $withdrawals = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // Transform data to include customer info
            $data = $withdrawals->map(function ($withdrawal) {
                $user = $withdrawal->account?->users?->first();
                return [
                    'id' => $withdrawal->id,
                    'customer_id' => $withdrawal->account_id,
                    'customer_name' => $user ? $user->name : 'N/A',
                    'customer_email' => $user ? $user->email : 'N/A',
                    'amount' => $withdrawal->amount,
                    'fee' => $withdrawal->fee,
                    'net_amount' => $withdrawal->net_amount,
                    'method' => $withdrawal->method,
                    'is_instant' => (bool) $withdrawal->is_instant,
                    'pix_key' => $withdrawal->destination['pix_key'] ?? null,
                    'bank_name' => $withdrawal->destination['bank_name'] ?? null,
                    'bank_account' => $withdrawal->destination['account_number'] ?? null,
                    'bank_agency' => $withdrawal->destination['agency'] ?? null,
                    'status' => $withdrawal->status,
                    'failure_reason' => $withdrawal->failure_reason,
                    'notes' => $withdrawal->notes,
                    'cancel_reason' => $withdrawal->failure_reason ?? $withdrawal->notes,
                    'requested_at' => $withdrawal->created_at->toIso8601String(),
                    'processed_at' => $withdrawal->processed_at?->toIso8601String(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => [
                    'current_page' => $withdrawals->currentPage(),
                    'per_page' => $withdrawals->perPage(),
                    'total' => $withdrawals->total(),
                    'last_page' => $withdrawals->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('WithdrawalController::adminList error', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'data' => [], 'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]]);
        }
    }

    /**
     * Admin: Approve or reject a withdrawal
     */
    public function adminUpdate(Request $request, string $withdrawalId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:approved,rejected,completed'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $withdrawal = Withdrawal::find($withdrawalId);

        if (!$withdrawal) {
            return response()->json([
                'success' => false,
                'message' => 'Saque não encontrado',
            ], 404);
        }

        if (!in_array($withdrawal->status, ['pending', 'processing'])) {
            return response()->json([
                'success' => false,
                'message' => 'Este saque não pode mais ser alterado',
            ], 422);
        }

        try {
            DB::beginTransaction();

            $newStatus = $validated['status'];
            
            if ($newStatus === 'approved' || $newStatus === 'completed') {
                // Process via acquirer PIX OUT (instant)
                if ($withdrawal->status === 'pending') {
                    try {
                        $withdrawalService = app(WithdrawalService::class);
                        $result = $withdrawalService->processWithdrawal($withdrawal);

                        $withdrawal->refresh();

                        if ($result['success']) {
                            $this->logAdminWithdrawalEvent($request, $withdrawal, 'admin_approved', [
                                'processing' => [
                                    'success' => true,
                                    'acquirer' => $result['acquirer'] ?? null,
                                    'transfer_id' => $result['transfer_id'] ?? null,
                                ],
                            ]);
                            DB::commit();
                            return response()->json([
                                'success' => true,
                                'data'    => $withdrawal,
                                'processing' => $result,
                                'message' => 'Saque aprovado e enviado para processamento via PIX OUT',
                            ]);
                        }
                        // If PIX OUT failed, the service already marked it as failed
                    } catch (\Exception $e) {
                        Log::error('Admin withdrawal processing error', [
                            'withdrawal_id' => $withdrawal->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Fallback: mark as completed manually
                $withdrawal->update([
                    'status' => 'completed',
                    'processed_at' => now(),
                ]);

                // Deduct from pending balance
                $wallet = AccountWallet::where('account_id', $withdrawal->account_id)->first();
                if ($wallet) {
                    $wallet->decrement('pending_balance', $withdrawal->amount);
                }

                $this->logAdminWithdrawalEvent($request, $withdrawal, 'admin_completed_manually');
            } elseif ($newStatus === 'rejected') {
                // Reject and return funds to available balance
                $reason = $validated['notes'] ?? 'Rejeitado pela administração';
                $withdrawal->update([
                    'status' => 'cancelled',
                    'processed_at' => now(),
                    'failure_reason' => $reason,
                    'notes' => $reason,
                ]);

                // Return amount to available balance
                $wallet = AccountWallet::where('account_id', $withdrawal->account_id)->first();
                if ($wallet) {
                    $wallet->decrement('pending_balance', $withdrawal->amount);
                    $wallet->increment('available_balance', $withdrawal->amount);
                }

                $this->logAdminWithdrawalEvent($request, $withdrawal, 'admin_rejected', [
                    'reason' => $reason,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $withdrawal->fresh(),
                'message' => $newStatus === 'rejected' 
                    ? 'Saque rejeitado e valor devolvido' 
                    : 'Saque aprovado com sucesso',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar saque',
            ], 500);
        }
    }

    /**
     * Admin: show a specific withdrawal with enriched context.
     */
    public function adminShow(Request $request, string $withdrawalId): JsonResponse
    {
        $withdrawal = Withdrawal::with(['account.users'])->find($withdrawalId);

        if (!$withdrawal) {
            return response()->json([
                'success' => false,
                'message' => 'Saque não encontrado',
            ], 404);
        }

        $user = $withdrawal->account?->users?->first();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $withdrawal->id,
                'account_id' => $withdrawal->account_id,
                'customer_name' => $user?->name,
                'customer_email' => $user?->email,
                'amount' => (int) $withdrawal->amount,
                'fee' => (int) $withdrawal->fee,
                'net_amount' => (int) $withdrawal->net_amount,
                'status' => $withdrawal->status,
                'method' => $withdrawal->method,
                'is_instant' => (bool) $withdrawal->is_instant,
                'destination' => $withdrawal->destination,
                'notes' => $withdrawal->notes,
                'failure_reason' => $withdrawal->failure_reason,
                'acquirer' => $withdrawal->acquirer,
                'acquirer_transfer_id' => $withdrawal->acquirer_transfer_id,
                'requested_at' => $withdrawal->created_at?->toIso8601String(),
                'processed_at' => $withdrawal->processed_at?->toIso8601String(),
                'updated_at' => $withdrawal->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Admin: cancel a withdrawal with mandatory reason and audit trail.
     */
    public function adminCancel(Request $request, string $withdrawalId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $withdrawal = Withdrawal::find($withdrawalId);

        if (!$withdrawal) {
            return response()->json([
                'success' => false,
                'message' => 'Saque não encontrado',
            ], 404);
        }

        $result = app(WithdrawalService::class)->adminCancelWithdrawal($withdrawal, $validated['reason']);

        if (!($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Erro ao cancelar saque administrativamente',
                'error' => [
                    'code' => $result['error_code'] ?? 'admin_withdrawal_cancellation_failed',
                ],
                'data' => $result['data'] ?? null,
            ], $result['status'] ?? 500);
        }

        $withdrawal = $result['withdrawal'] ?? $withdrawal->fresh();

        $this->logAdminWithdrawalEvent($request, $withdrawal, 'admin_cancelled', [
            'reason' => $validated['reason'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $withdrawal,
            'cancel_reason' => $withdrawal->failure_reason ?? $withdrawal->notes,
            'message' => $result['message'] ?? 'Saque cancelado pela administração com devolução de saldo',
        ]);
    }

    /**
     * Admin: retrieve operational timeline (audit + access) for a withdrawal.
     */
    public function adminLogs(Request $request, string $withdrawalId): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 25), 100);
        $page = max((int) $request->input('page', 1), 1);

        $withdrawal = Withdrawal::find($withdrawalId);

        if (!$withdrawal) {
            return response()->json([
                'success' => false,
                'message' => 'Saque não encontrado',
            ], 404);
        }

        $auditEvents = AuditLog::query()
            ->where('entity_type', 'withdrawal')
            ->where('entity_id', $withdrawalId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function (AuditLog $log) {
                return [
                    'source' => 'audit_log',
                    'event' => $log->action,
                    'created_at' => optional($log->created_at)->toIso8601String(),
                    'actor_user_id' => $log->user_id,
                    'ip_address' => $log->ip_address,
                    'metadata' => $log->metadata,
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                ];
            });

        $accessEvents = DB::table('admin_access_logs')
            ->where('endpoint', 'like', "%admin/withdrawals/{$withdrawalId}%")
            ->orderByDesc('accessed_at')
            ->limit(200)
            ->get()
            ->map(function ($log) {
                return [
                    'source' => 'admin_access_log',
                    'event' => 'endpoint_access',
                    'created_at' => optional($log->accessed_at)->toIso8601String(),
                    'actor_user_id' => $log->user_id,
                    'ip_address' => $log->ip_address,
                    'metadata' => [
                        'method' => $log->method,
                        'endpoint' => $log->endpoint,
                        'response_status' => $log->response_status,
                        'response_time_ms' => $log->response_time_ms,
                    ],
                ];
            });

        $timeline = $auditEvents
            ->concat($accessEvents)
            ->sortByDesc('created_at')
            ->values();

        $total = $timeline->count();
        $items = $timeline->forPage($page, $perPage)->values();

        return response()->json([
            'success' => true,
            'data' => [
                'withdrawal_id' => $withdrawalId,
                'events' => $items,
            ],
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / max(1, $perPage)),
            ],
        ]);
    }

    /**
     * Get withdrawal settings (public endpoint for users)
     * Returns custom fees if set by admin, otherwise returns global gateway fees.
     */
    public function getSettings(): JsonResponse
    {
        try {
            return $this->buildSettingsResponse();
        } catch (\Throwable $e) {
            Log::error('WithdrawalController::getSettings failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'internal_error', 'message' => 'Erro ao carregar configurações de saque'],
            ], 500);
        }
    }

    private function buildSettingsResponse(): JsonResponse
    {
        try {
            $settings = SystemSetting::getByGroup('withdrawals');
        } catch (\Throwable $e) {
            $settings = [];
        }

        // Global (gateway) defaults
        $pixFee   = (int) ($settings['standard_withdrawal_fee_pix'] ?? 0);
        $bankFee  = (int) ($settings['standard_withdrawal_fee_bank'] ?? 200);
        $minAmount = (int) ($settings['withdrawal_min_amount'] ?? 1000);
        $instantEnabled  = (bool) ($settings['instant_withdrawal_enabled'] ?? false);
        $instantFeeType  = $settings['instant_withdrawal_fee_type'] ?? 'percentage';
        $instantFeeValue = (float) ($settings['instant_withdrawal_fee_value'] ?? 2.5);

        $isCustom = false;

        // Get active acquirer gateway fee (defaults)
        try {
            $activeAcquirer = \App\Models\AcquirerConfig::where('enabled', true)
                ->orderBy('priority')
                ->first();
        } catch (\Throwable $e) {
            $activeAcquirer = null;
        }
        $gatewayFeePercentage = (float) ($activeAcquirer?->gateway_fee_percentage ?? 5.00);
        $gatewayFeeFixed = (float) ($activeAcquirer?->gateway_fee_fixed ?? 2.00);

        // Per-method custom transaction fees (null = use default)
        $customPixFeePercent = null;
        $customPixFeeFixed = null;
        $customCreditCardFeePercent = null;
        $customCreditCardFeeFixed = null;
        $customBoletoFeePercent = null;
        $customBoletoFeeFixed = null;

        // Check for per-account custom fees set by admin
        $isAdminUser = false;
        try {
            $user = request()->user();
        } catch (\Throwable $e) {
            $user = null;
        }

        if ($user) {
            $isAdminUser = $this->isAdminWithdrawalUser($user, (string) $user->account_id);
        }

        if ($user && $user->account_id) {
            try {
                $custom = AccountCustomFee::where('account_id', $user->account_id)->first();
            } catch (\Throwable $e) {
                $custom = null;
            }
            if ($custom) {
                $isCustom = true;

                // Withdrawal fees
                $customPixWdFee = $custom->getWithdrawalFeeCents('pix');
                if (!is_null($customPixWdFee)) {
                    $pixFee = $customPixWdFee;
                }
                $customBankWdFee = $custom->getWithdrawalFeeCents('bank_transfer');
                if (!is_null($customBankWdFee)) {
                    $bankFee = $customBankWdFee;
                }
                if (!is_null($custom->instant_withdrawal_fee_percent)) {
                    $instantFeeType  = 'percentage';
                    $instantFeeValue = (float) $custom->instant_withdrawal_fee_percent;
                }
                $customMin = $custom->getMinWithdrawalCents();
                if (!is_null($customMin)) {
                    $minAmount = $customMin;
                }

                // Transaction fees — override gateway display with custom values
                if (!is_null($custom->pix_fee_percent) || !is_null($custom->pix_fee_fixed)) {
                    $gatewayFeePercentage = (float) ($custom->pix_fee_percent ?? 0);
                    $gatewayFeeFixed = (float) ($custom->pix_fee_fixed ?? 0);
                }
                $customPixFeePercent = is_null($custom->pix_fee_percent) ? null : (float) $custom->pix_fee_percent;
                $customPixFeeFixed = is_null($custom->pix_fee_fixed) ? null : (float) $custom->pix_fee_fixed;
                $customCreditCardFeePercent = is_null($custom->credit_card_fee_percent) ? null : (float) $custom->credit_card_fee_percent;
                $customCreditCardFeeFixed = is_null($custom->credit_card_fee_fixed) ? null : (float) $custom->credit_card_fee_fixed;
                $customBoletoFeePercent = is_null($custom->boleto_fee_percent) ? null : (float) $custom->boleto_fee_percent;
                $customBoletoFeeFixed = is_null($custom->boleto_fee_fixed) ? null : (float) $custom->boleto_fee_fixed;
            }
        }

        if ($isAdminUser) {
            $pixFee = 0;
            $bankFee = 0;
            $minAmount = 0;
            $instantEnabled = false;
            $instantFeeType = 'fixed';
            $instantFeeValue = 0.0;

            $isCustom = false;
            $gatewayFeePercentage = 0.0;
            $gatewayFeeFixed = 0.0;
            $customPixFeePercent = null;
            $customPixFeeFixed = null;
            $customCreditCardFeePercent = null;
            $customCreditCardFeeFixed = null;
            $customBoletoFeePercent = null;
            $customBoletoFeeFixed = null;
        }

        $autoWithdrawalEnabled = false;
        $autoWithdrawalMinAmount = 0;
        $withdrawalsEnabled = true;
        $withdrawalProcessingEnabled = true;
        $dailyWithdrawalLimit = 5000000;
        $largeWithdrawalThreshold = 1000000;
        $cooldownSeconds = 60;
        $maxPendingWithdrawals = 10;
        try {
            $autoWithdrawalEnabled = (bool) SystemSetting::get('auto_withdrawal_enabled', false);
            $autoWithdrawalMinAmount = (int) SystemSetting::get('auto_withdrawal_min_amount', 0);
            $withdrawalsEnabled = (bool) SystemSetting::get('withdrawals_enabled', true);
            $withdrawalProcessingEnabled = (bool) SystemSetting::get('withdrawal_processing_enabled', true);
            $dailyWithdrawalLimit = (int) SystemSetting::get('daily_withdrawal_limit', 5000000);
            $largeWithdrawalThreshold = (int) SystemSetting::get('large_withdrawal_threshold', 1000000);
            $cooldownSeconds = (int) SystemSetting::get('withdrawal_cooldown_seconds', 60);
            $maxPendingWithdrawals = (int) SystemSetting::get('max_pending_withdrawals_per_account', 10);
        } catch (\Throwable $e) {
            // system_settings table may not exist
        }

        return response()->json([
            'success' => true,
            'data' => [
                'instant_withdrawal_enabled' => $instantEnabled,
                'instant_withdrawal_fee_type' => $instantFeeType,
                'instant_withdrawal_fee_value' => $instantFeeValue,
                'auto_withdrawal_enabled' => $autoWithdrawalEnabled,
                'auto_withdrawal_min_amount' => $autoWithdrawalMinAmount,
                'standard_withdrawal_fee_pix' => $pixFee,
                'standard_withdrawal_fee_bank' => $bankFee,
                'withdrawal_min_amount' => $minAmount,
                'withdrawal_max_amount' => (int) ($settings['withdrawal_max_amount'] ?? 0),
                'withdrawals_enabled' => $withdrawalsEnabled,
                'withdrawal_processing_enabled' => $withdrawalProcessingEnabled,
                'daily_withdrawal_limit' => $dailyWithdrawalLimit,
                'large_withdrawal_threshold' => $largeWithdrawalThreshold,
                'withdrawal_cooldown_seconds' => $cooldownSeconds,
                'max_pending_withdrawals_per_account' => $maxPendingWithdrawals,
                'is_custom' => $isCustom,
                'gateway_fee_percentage' => $gatewayFeePercentage,
                'gateway_fee_fixed' => $gatewayFeeFixed,
                'custom_pix_fee_percent' => $customPixFeePercent,
                'custom_pix_fee_fixed' => $customPixFeeFixed,
                'custom_credit_card_fee_percent' => $customCreditCardFeePercent,
                'custom_credit_card_fee_fixed' => $customCreditCardFeeFixed,
                'custom_boleto_fee_percent' => $customBoletoFeePercent,
                'custom_boleto_fee_fixed' => $customBoletoFeeFixed,
            ],
        ]);
    }

    private function isAdminWithdrawalUser(mixed $user, ?string $accountId = null, mixed $ownerUser = null): bool
    {
        if ($user && method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return true;
        }

        if (($user?->type ?? null) !== 'api_key') {
            return false;
        }

        $resolvedOwner = $ownerUser;
        if (!$resolvedOwner && !empty($accountId)) {
            $resolvedOwner = \App\Models\User::where('account_id', $accountId)->first();
        }

        return (bool) ($resolvedOwner && method_exists($resolvedOwner, 'isAdmin') && $resolvedOwner->isAdmin());
    }

    private function calculateAdminGatewayAvailableBalance(string $accountId): int
    {
        try {
            $demoAccountIds = Account::where('is_demo', true)->pluck('id');
        } catch (\Throwable $e) {
            $demoAccountIds = collect();
        }

        $approvedChargesQuery = Charge::where('status', 'approved')
            ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds));

        try {
            $acquirerConfigs = AcquirerConfig::query()
                ->get()
                ->keyBy(fn (AcquirerConfig $config) => $this->normalizeAcquirerKey((string) $config->acquirer_key));
        } catch (\Throwable $e) {
            $acquirerConfigs = collect();
        }

        $chargeFees = (int) ((clone $approvedChargesQuery)->sum('fee_amount') ?? 0);
        $chargeAcquirerCosts = $this->estimateChargeAcquirerCostsForGatewayProfit((clone $approvedChargesQuery), $acquirerConfigs);

        $withdrawalFees = (int) (Withdrawal::whereNotIn('status', ['failed', 'cancelled'])
            ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
            ->sum('fee') ?? 0);

        $gatewayProfit = max(0, ($chargeFees - $chargeAcquirerCosts) + $withdrawalFees);

        $lockedWithdrawals = (int) Withdrawal::where('account_id', $accountId)
            ->whereNotIn('status', ['cancelled', 'failed'])
            ->sum('amount');

        return max(0, $gatewayProfit - $lockedWithdrawals);
    }

    private function estimateChargeAcquirerCostsForGatewayProfit($chargesQuery, $acquirerConfigs): int
    {
        $grouped = $chargesQuery
            ->selectRaw('acquirer, COUNT(*) as tx_count, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('acquirer')
            ->get();

        $totalCosts = 0;

        foreach ($grouped as $row) {
            $acquirerKey = $this->normalizeAcquirerKey((string) ($row->acquirer ?? ''));
            $config = $acquirerConfigs->get($acquirerKey);

            if (!$config) {
                continue;
            }

            $amountCents = (int) ($row->total_amount ?? 0);
            $txCount = (int) ($row->tx_count ?? 0);

            $percentageCost = (int) round($amountCents * ((float) ($config->acquirer_fee_percentage ?? 0) / 100));
            $fixedCost = ((int) round((float) ($config->acquirer_fee_fixed ?? 0) * 100)) * $txCount;

            $totalCosts += max(0, $percentageCost + $fixedCost);
        }

        return $totalCosts;
    }

    private function normalizeAcquirerKey(string $acquirer): string
    {
        $normalized = strtolower(trim($acquirer));
        return $normalized !== '' ? $normalized : 'unknown';
    }

    private function buildWithdrawalIdempotencyHash(array $validated): string
    {
        $payload = [
            'amount' => (float) ($validated['amount'] ?? 0),
            'method' => (string) ($validated['method'] ?? ''),
            'is_instant' => (bool) ($validated['is_instant'] ?? false),
            'destination' => $validated['destination'] ?? [],
            'notes' => isset($validated['notes']) ? trim((string) $validated['notes']) : null,
        ];

        $normalized = $this->normalizeIdempotencyPayload($payload);

        return hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function normalizeIdempotencyPayload(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }

            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeIdempotencyPayload($item);
            }

            return $value;
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_float($value)) {
            return (float) number_format($value, 8, '.', '');
        }

        return $value;
    }

    /**
     * Calculate withdrawal fee based on custom fees (per-account) or system settings.
     */
    private function calculateWithdrawalFee(float $amount, string $method, bool $isInstant, ?string $accountId = null): float
    {
        $resolvedAccountId = $accountId ?? (request()->user()?->account_id);
        $custom = $resolvedAccountId
            ? AccountCustomFee::where('account_id', $resolvedAccountId)->first()
            : null;

        // Base fee
        if ($method === 'bank_transfer') {
            $customFeeCents = $custom?->getWithdrawalFeeCents('bank_transfer');
            $baseFee = !is_null($customFeeCents)
                ? (float) $customFeeCents
                : (float) SystemSetting::get('standard_withdrawal_fee_bank', 200);
        } else {
            $customFeeCents = $custom?->getWithdrawalFeeCents('pix');
            $baseFee = !is_null($customFeeCents)
                ? (float) $customFeeCents
                : (float) SystemSetting::get('standard_withdrawal_fee_pix', 0);
        }

        // Add withdrawal fee (formerly "instant fee") — applies to ALL withdrawals when enabled
        $withdrawalFeeEnabled = (bool) SystemSetting::get('instant_withdrawal_enabled', false);
        if ($withdrawalFeeEnabled) {
            if (!is_null($custom?->instant_withdrawal_fee_percent)) {
                $withdrawalFee = round($amount * ((float) $custom->instant_withdrawal_fee_percent / 100));
            } else {
                $feeType = SystemSetting::get('instant_withdrawal_fee_type', 'percentage');
                $feeValue = (float) SystemSetting::get('instant_withdrawal_fee_value', 2.5);

                $withdrawalFee = $feeType === 'percentage'
                    ? round($amount * ($feeValue / 100))
                    : $feeValue;
            }

            $baseFee += $withdrawalFee;
        }

        return $baseFee;
    }

    /**
     * Admin: Manually trigger processing of a pending withdrawal via PIX OUT.
     */
    public function adminProcess(Request $request, string $withdrawalId): JsonResponse
    {
        $withdrawal = Withdrawal::find($withdrawalId);

        if (!$withdrawal) {
            return response()->json([
                'success' => false,
                'message' => 'Saque não encontrado',
            ], 404);
        }

        $isRecoverableProcessing = $withdrawal->status === 'processing' && empty($withdrawal->acquirer_transfer_id);

        if ($withdrawal->status !== 'pending' && !$isRecoverableProcessing) {
            return response()->json([
                'success' => false,
                'message' => "Saque não está em estado processável manualmente (status={$withdrawal->status})",
            ], 422);
        }

        $approvalCheck = $this->approvalService->requireApproval($request, [
            'action_key' => 'admin.withdrawal.process_single',
            'resource_type' => 'withdrawal',
            'resource_id' => (string) $withdrawal->id,
            'request_reason' => 'Processamento manual de saque',
            'payload' => [
                'amount' => (int) $withdrawal->amount,
                'method' => (string) $withdrawal->method,
                'status' => (string) $withdrawal->status,
            ],
            'expires_minutes' => 20,
        ]);

        if (!($approvalCheck['approved'] ?? false)) {
            return response()->json($approvalCheck['response'], $approvalCheck['http_status'] ?? 202);
        }

        try {
            $withdrawalService = app(WithdrawalService::class);
            $result = $withdrawalService->processWithdrawal($withdrawal);

            $withdrawal->refresh();
            $this->logAdminWithdrawalEvent(
                $request,
                $withdrawal,
                $result['success'] ? 'admin_process_triggered' : 'admin_process_failed',
                [
                    'processing' => [
                        'success' => (bool) ($result['success'] ?? false),
                        'error' => $result['error'] ?? null,
                        'acquirer' => $result['acquirer'] ?? null,
                        'transfer_id' => $result['transfer_id'] ?? null,
                    ],
                ]
            );

            return response()->json([
                'success' => $result['success'],
                'data'    => $withdrawal->fresh(),
                'processing' => $result,
                'message' => $result['success']
                    ? 'Saque enviado para processamento via PIX OUT'
                    : 'Falha ao processar saque: ' . ($result['error'] ?? ''),
            ], $result['success'] ? 200 : 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar saque: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin: Trigger batch processing of all auto-enabled pending withdrawals.
     */
    public function adminProcessAll(Request $request): JsonResponse
    {
        $approvalCheck = $this->approvalService->requireApproval($request, [
            'action_key' => 'admin.withdrawal.process_all',
            'resource_type' => 'withdrawal_batch',
            'resource_id' => 'global',
            'request_reason' => 'Processamento em lote de saques pendentes',
            'payload' => [
                'requested_at' => now()->toIso8601String(),
            ],
            'expires_minutes' => 10,
        ]);

        if (!($approvalCheck['approved'] ?? false)) {
            return response()->json($approvalCheck['response'], $approvalCheck['http_status'] ?? 202);
        }

        try {
            $withdrawalService = app(WithdrawalService::class);
            $results = $withdrawalService->processAutoWithdrawals();

            return response()->json([
                'success' => true,
                'data'    => $results,
                'message' => "Processados: {$results['processed']}, Sucesso: {$results['success']}, Falhas: {$results['failed']}",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar saques: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function logAdminWithdrawalEvent(Request $request, Withdrawal $withdrawal, string $action, array $context = []): void
    {
        try {
            AuditLog::create([
                'id' => Str::uuid()->toString(),
                'user_id' => $request->user()?->id,
                'account_id' => $withdrawal->account_id,
                'action' => $action,
                'entity_type' => 'withdrawal',
                'entity_id' => $withdrawal->id,
                'old_values' => null,
                'new_values' => [
                    'status' => $withdrawal->status,
                    'processed_at' => optional($withdrawal->processed_at)->toIso8601String(),
                    'failure_reason' => $withdrawal->failure_reason,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => array_merge([
                    'module' => 'admin_withdrawals',
                    'method' => $request->method(),
                    'path' => $request->path(),
                ], $context),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write withdrawal admin audit log', [
                'withdrawal_id' => $withdrawal->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
