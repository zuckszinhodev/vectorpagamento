<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountWallet;
use App\Models\AcquirerConfig;
use App\Models\AccountCustomFee;
use App\Models\Charge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            try {
                $customFee = AccountCustomFee::where('account_id', $user->account_id)->first();
            } catch (\Throwable $e) {
                $customFee = null;
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

                $acquirerConfig = $acquirerConfigs->get($charge->acquirer);
                if ($acquirerConfig) {
                    try {
                        $customGatewayFee = $customFee?->calculateGatewayFee($charge->amount, $charge->payment_method);
                        if (!is_null($customGatewayFee)) {
                            $fee = $customGatewayFee + $acquirerConfig->calculateAcquirerFee($charge->amount);
                        } else {
                            $fee = $acquirerConfig->calculateGatewayFee($charge->amount);
                        }
                    } catch (\Throwable $e) {
                        $fee = (int) round($charge->amount * 0.05) + 200;
                    }
                } else {
                    $fee = (int) round($charge->amount * 0.05) + 200;
                }
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

            $correctBalance = max(0, $totalNet - (int) $lockedWithdrawals);

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
                'platform_name'    => $customization['platform_name'] ?? 'GatePro',
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
