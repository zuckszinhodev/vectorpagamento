<?php declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountAcquirer;
use App\Models\AccountWallet;
use App\Models\User;
use App\Models\Charge;
use App\Models\Chargeback;
use App\Models\Transaction;
use App\Models\Withdrawal;
use App\Models\KycSubmission;
use App\Models\AcquirerConfig;
use App\Models\AdminActionApproval;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Domain\Acquirer\Entities\Acquirer as AcquirerEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin Dashboard Controller (slim)
 *
 * Only dashboard-specific endpoints remain here.
 * Customer management  -> CustomerAdminController
 * Acquirer management  -> AcquirerAdminController
 * System config / webhooks -> SystemConfigAdminController
 */
class AdminController extends Controller
{
    public function operationsPulse(): JsonResponse
    {
        try {
            try {
                $demoAccountIds = Account::where('is_demo', true)->pluck('id');
            } catch (\Throwable $e) {
                $demoAccountIds = collect();
            }

            $paymentsEnabled = (bool) SystemSetting::get('payments_enabled', true);
            $withdrawalsEnabled = (bool) SystemSetting::get('withdrawals_enabled', true);
            $withdrawalProcessingEnabled = (bool) SystemSetting::get('withdrawal_processing_enabled', true);
            $autoWithdrawalEnabled = (bool) SystemSetting::get('auto_withdrawal_enabled', false);

            $pendingWithdrawalsQuery = Withdrawal::whereIn('status', ['pending', 'pending_approval', 'processing'])
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds));

            $pendingWithdrawals = (int) (clone $pendingWithdrawalsQuery)->count();
            $pendingWithdrawalsAmount = (int) (clone $pendingWithdrawalsQuery)->sum('amount');

            $pendingApprovals = 0;
            try {
                $pendingApprovals = (int) AdminActionApproval::where('status', 'pending')->count();
            } catch (\Throwable $e) {
                $pendingApprovals = 0;
            }

            $pendingKyc = 0;
            try {
                $pendingKycSubmissions = KycSubmission::whereIn('status', ['pending', 'under_review'])->count();
                $usersWithSubmittedKyc = User::whereIn('kyc_status', ['submitted', 'under_review'])->count();
                $pendingKyc = (int) max($pendingKycSubmissions, $usersWithSubmittedKyc);
            } catch (\Throwable $e) {
                $pendingKyc = 0;
            }

            $criticalAlerts = [];
            if (!$paymentsEnabled) {
                $criticalAlerts[] = 'Pagamentos estao pausados globalmente';
            }
            if (!$withdrawalsEnabled) {
                $criticalAlerts[] = 'Novos saques estao desativados';
            }
            if (!$withdrawalProcessingEnabled) {
                $criticalAlerts[] = 'Processamento de saques estah pausado';
            }
            if ($pendingApprovals > 20) {
                $criticalAlerts[] = 'Fila de aprovacoes acima de 20 itens';
            }
            if ($pendingWithdrawals > 25) {
                $criticalAlerts[] = 'Fila de saques acima de 25 itens';
            }

            $healthScore = 100;
            if (!$paymentsEnabled) {
                $healthScore -= 35;
            }
            if (!$withdrawalsEnabled) {
                $healthScore -= 20;
            }
            if (!$withdrawalProcessingEnabled) {
                $healthScore -= 25;
            }
            if ($pendingApprovals > 10) {
                $healthScore -= min(20, ($pendingApprovals - 10));
            }
            if ($pendingWithdrawals > 10) {
                $healthScore -= min(20, ($pendingWithdrawals - 10));
            }
            if ($pendingKyc > 25) {
                $healthScore -= min(15, (int) floor(($pendingKyc - 25) / 2));
            }

            $healthScore = max(0, min(100, $healthScore));

            $state = 'healthy';
            if (!empty($criticalAlerts) || $healthScore < 65) {
                $state = 'critical';
            } elseif ($healthScore < 85 || $pendingApprovals > 10 || $pendingWithdrawals > 10) {
                $state = 'attention';
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'health_score' => $healthScore,
                    'state' => $state,
                    'queues' => [
                        'pending_withdrawals' => $pendingWithdrawals,
                        'pending_withdrawals_amount' => $pendingWithdrawalsAmount,
                        'pending_approvals' => $pendingApprovals,
                        'pending_kyc' => $pendingKyc,
                    ],
                    'switches' => [
                        'payments_enabled' => $paymentsEnabled,
                        'withdrawals_enabled' => $withdrawalsEnabled,
                        'withdrawal_processing_enabled' => $withdrawalProcessingEnabled,
                        'auto_withdrawal_enabled' => $autoWithdrawalEnabled,
                    ],
                    'critical_alerts' => $criticalAlerts,
                    'updated_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AdminController::operationsPulse error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'health_score' => 0,
                    'state' => 'critical',
                    'queues' => [
                        'pending_withdrawals' => 0,
                        'pending_withdrawals_amount' => 0,
                        'pending_approvals' => 0,
                        'pending_kyc' => 0,
                    ],
                    'switches' => [
                        'payments_enabled' => false,
                        'withdrawals_enabled' => false,
                        'withdrawal_processing_enabled' => false,
                        'auto_withdrawal_enabled' => false,
                    ],
                    'critical_alerts' => ['Nao foi possivel carregar o pulso operacional.'],
                    'updated_at' => now()->toIso8601String(),
                ],
            ]);
        }
    }

    /**
     * Get system-wide statistics for admin dashboard
     */
    public function systemStats(): JsonResponse
    {
        try {
            $today = now()->startOfDay();
            $thisMonthStart = now()->startOfMonth();
            $lastMonthStart = now()->subMonth()->startOfMonth();
            $lastMonthEnd = now()->subMonth()->endOfMonth();

            // Exclude demo accounts from admin totals (graceful if column missing)
            try {
                $demoAccountIds = Account::where('is_demo', true)->pluck('id');
            } catch (\Throwable $e) {
                $demoAccountIds = collect();
            }

            // Count active users (exclude demo)
            $totalCustomers = User::when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))->count();

            // Count active sellers (users with at least 1 approved charge)
            $activeSellers = Account::whereHas('charges', function ($q) {
                $q->where('status', 'approved');
            })->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $demoAccountIds))->count();

            // Transactions today (exclude demo)
            $transactionsToday = Charge::whereDate('created_at', $today)
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->count();

            // ===== FINANCIAL METRICS =====
            // Total revenue (all approved charges, exclude demo)
            $totalRevenue = Charge::where('status', 'approved')
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->sum('amount');

            // Gateway profit = sum of all transaction fees + all withdrawal fees
            $chargeFees = Charge::where('status', 'approved')
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->sum('fee_amount') ?? 0;

            $withdrawalFees = \App\Models\Withdrawal::whereNotIn('status', ['failed', 'cancelled'])
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->sum('fee') ?? 0;

            $gatewayProfit = $chargeFees + $withdrawalFees;
            $totalFees = $chargeFees;

            // Seller payout = total net amount withdrawn by sellers (completed withdrawals)
            $sellerPayout = \App\Models\Withdrawal::where('status', 'completed')
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->sum('net_amount');

            // Pending amount = charges waiting for payment
            $pendingAmount = Charge::whereIn('status', ['pending', 'processing', 'chargeback'])
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->sum('amount');

            // ===== TRANSACTION COUNTS =====
            // Paid transactions count
            $paidTransactions = Charge::where('status', 'approved')
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->count();

            // Pending transactions count (inclui chargebacks ativos)
            $pendingTransactions = Charge::whereIn('status', ['pending', 'processing', 'chargeback'])
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->count();

            // Volume today (sum of approved transactions, exclude demo)
            $volumeToday = Charge::whereDate('created_at', $today)
                ->where('status', 'approved')
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->sum('amount');

            // ===== GROWTH METRICS =====
            // Revenue growth (compare this month vs last month, exclude demo)
            $thisMonthRevenue = Charge::where('created_at', '>=', $thisMonthStart)
                ->where('status', 'approved')
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->sum('amount');

            $lastMonthRevenue = Charge::whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])
                ->where('status', 'approved')
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->sum('amount');

            $revenueGrowth = $lastMonthRevenue > 0
                ? round((($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1)
                : ($thisMonthRevenue > 0 ? 100 : 0);

            // Gateway profit growth (include both charge and withdrawal fees)
            $thisMonthChargeProfit = Charge::where('created_at', '>=', $thisMonthStart)
                ->where('status', 'approved')
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->sum('fee_amount') ?? 0;

            $thisMonthWithdrawalProfit = \App\Models\Withdrawal::whereNotIn('status', ['failed', 'cancelled'])
                ->where('created_at', '>=', $thisMonthStart)
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->sum('fee') ?? 0;

            $thisMonthProfit = $thisMonthChargeProfit + $thisMonthWithdrawalProfit;

            $lastMonthChargeProfit = Charge::whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])
                ->where('status', 'approved')
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->sum('fee_amount') ?? 0;

            $lastMonthWithdrawalProfit = \App\Models\Withdrawal::whereNotIn('status', ['failed', 'cancelled'])
                ->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->sum('fee') ?? 0;

            $lastMonthProfit = $lastMonthChargeProfit + $lastMonthWithdrawalProfit;

            $profitGrowth = $lastMonthProfit > 0
                ? round((($thisMonthProfit - $lastMonthProfit) / $lastMonthProfit) * 100, 1)
                : ($thisMonthProfit > 0 ? 100 : 0);

            // Payout growth baseado em saques concluídos
            $thisMonthPayout = Withdrawal::where('status', 'completed')
                ->where('created_at', '>=', $thisMonthStart)
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->sum('net_amount') ?? 0;

            $lastMonthPayout = Withdrawal::where('status', 'completed')
                ->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->sum('net_amount') ?? 0;

            $payoutGrowth = $lastMonthPayout > 0
                ? round((($thisMonthPayout - $lastMonthPayout) / $lastMonthPayout) * 100, 1)
                : ($thisMonthPayout > 0 ? 100 : 0);

            // Pending KYC
            $pendingKyc = 0;
            try {
                $pendingKycSubmissions = KycSubmission::whereIn('status', ['pending', 'under_review'])->count();
                $usersWithSubmittedKyc = User::whereIn('kyc_status', ['submitted', 'under_review'])->count();
                $pendingKyc = max($pendingKycSubmissions, $usersWithSubmittedKyc);
            } catch (\Throwable $e) {
                // kyc_submissions table or kyc_status column may not exist
            }

            // Active acquirers (from database)
            $activeAcquirers = 0;
            try {
                $activeAcquirers = AcquirerConfig::where('enabled', true)->count();
            } catch (\Throwable $e) {
                // acquirer_configs table may not exist
            }

            // Success rate - calculate from last 30 days for accuracy
            $thirtyDaysAgo = now()->subDays(30)->startOfDay();
            $totalTransactions = Charge::where('created_at', '>=', $thirtyDaysAgo)
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->count();
            $successfulTransactions = Charge::where('created_at', '>=', $thirtyDaysAgo)
                ->where('status', 'approved')
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->count();

            $successRate = $totalTransactions > 0
                ? round(($successfulTransactions / $totalTransactions) * 100, 1)
                : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    // Main financial metrics
                    'total_revenue' => $totalRevenue,
                    'gateway_profit' => $gatewayProfit,
                    'gateway_fees' => $totalFees,
                    'seller_payout' => $sellerPayout,
                    'pending_amount' => $pendingAmount,
                    // Growth percentages
                    'revenue_change' => $revenueGrowth,
                    'profit_change' => $profitGrowth,
                    'payout_change' => $payoutGrowth,
                    'pending_change' => 0,
                    // Transaction counts
                    'paid_transactions' => $paidTransactions,
                    'pending_transactions' => $pendingTransactions,
                    'transactions_today' => $transactionsToday,
                    // User/seller counts
                    'total_customers' => $totalCustomers,
                    'active_sellers' => $activeSellers,
                    // Other metrics
                    'pending_kyc' => $pendingKyc,
                    'active_acquirers' => $activeAcquirers,
                    'volume_today' => $volumeToday,
                    'revenue_growth' => $revenueGrowth,
                    'success_rate' => $successRate,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AdminController::systemStats error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_revenue' => 0,
                    'gateway_profit' => 0,
                    'gateway_fees' => 0,
                    'seller_payout' => 0,
                    'pending_amount' => 0,
                    'revenue_change' => 0,
                    'profit_change' => 0,
                    'payout_change' => 0,
                    'pending_change' => 0,
                    'paid_transactions' => 0,
                    'pending_transactions' => 0,
                    'total_customers' => 0,
                    'active_sellers' => 0,
                    'pending_kyc' => 0,
                    'active_acquirers' => 0,
                    'transactions_today' => 0,
                    'volume_today' => 0,
                    'revenue_growth' => 0,
                    'success_rate' => 0,
                ],
            ]);
        }
    }

    /**
     * Get recent system activities for admin dashboard
     */
    public function recentActivities(Request $request): JsonResponse
    {
        try {
            $limit = min($request->input('limit', 10), 50);
            $activities = collect();

            // Novos clientes registrados
            $newUsers = User::with(['account'])
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => 'user_' . $user->id,
                        'type' => 'user_registered',
                        'action' => 'Novo cliente registrado',
                        'user' => $user->name,
                        'email' => $user->email,
                        'timestamp' => $user->created_at,
                        'status' => 'success',
                    ];
                });
            $activities = $activities->merge($newUsers);

            // Documentos KYC aprovados/enviados
            try {
                $kycSubmissions = KycSubmission::with(['user'])
                    ->orderBy('updated_at', 'desc')
                    ->limit($limit)
                    ->get()
                    ->map(function ($submission) {
                        $action = match ($submission->status) {
                            'approved' => 'Documento KYC aprovado',
                            'rejected' => 'Documento KYC rejeitado',
                            'under_review' => 'Documento KYC em análise',
                            default => 'Novo documento KYC enviado',
                        };
                        $status = match ($submission->status) {
                            'approved' => 'success',
                            'rejected' => 'error',
                            default => 'info',
                        };
                        return [
                            'id' => 'kyc_' . $submission->id,
                            'type' => 'kyc_' . $submission->status,
                            'action' => $action,
                            'user' => $submission->user?->name ?? 'Usuário',
                            'email' => $submission->user?->email,
                            'timestamp' => $submission->updated_at,
                            'status' => $status,
                        ];
                    });
                $activities = $activities->merge($kycSubmissions);
            } catch (\Throwable $e) {
                // KYC table may not exist
            }

            // Transações processadas
            $charges = Charge::with(['account.users'])
                ->whereIn('status', ['approved', 'paid', 'failed'])
                ->orderBy('updated_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($charge) {
                    $user = $charge->account?->users?->first();
                    $action = match ($charge->status) {
                        'approved', 'paid' => 'Transação processada',
                        'failed' => 'Transação falhou',
                        default => 'Transação atualizada',
                    };
                    $status = in_array($charge->status, ['approved', 'paid']) ? 'success' : 'error';
                    return [
                        'id' => 'charge_' . $charge->id,
                        'type' => 'transaction_' . $charge->status,
                        'action' => $action,
                        'user' => $user?->name ?? ($charge->customer_email ?? 'Cliente'),
                        'email' => $user?->email ?? $charge->customer_email,
                        'amount' => $charge->amount,
                        'timestamp' => $charge->updated_at,
                        'status' => $status,
                    ];
                });
            $activities = $activities->merge($charges);

            // Ordenar por timestamp e pegar os mais recentes
            $sortedActivities = $activities
                ->filter(fn ($activity) => $activity['timestamp'] !== null)
                ->sortByDesc('timestamp')
                ->take($limit)
                ->map(function ($activity) {
                    $activity['time'] = $this->formatTimeAgo($activity['timestamp']);
                    $activity['timestamp'] = $activity['timestamp']->toIso8601String();
                    return $activity;
                })
                ->values();

            return response()->json([
                'success' => true,
                'data' => $sortedActivities,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AdminController::recentActivities error', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'data' => []]);
        }
    }

    /**
     * Format timestamp as human readable time ago
     */
    private function formatTimeAgo($timestamp): string
    {
        $diff = now()->diff($timestamp);

        if ($diff->y > 0) {
            return 'há ' . $diff->y . ' ' . ($diff->y === 1 ? 'ano' : 'anos');
        }
        if ($diff->m > 0) {
            return 'há ' . $diff->m . ' ' . ($diff->m === 1 ? 'mês' : 'meses');
        }
        if ($diff->d > 0) {
            return 'há ' . $diff->d . ' ' . ($diff->d === 1 ? 'dia' : 'dias');
        }
        if ($diff->h > 0) {
            return 'há ' . $diff->h . ' ' . ($diff->h === 1 ? 'hora' : 'horas');
        }
        if ($diff->i > 0) {
            return 'há ' . $diff->i . ' ' . ($diff->i === 1 ? 'minuto' : 'minutos');
        }
        return 'agora mesmo';
    }

    /**
     * Get revenue chart data for admin dashboard
     */
    public function revenueChart(Request $request): JsonResponse
    {
        try {
            $period = $request->input('period', 'month');

            // Exclude demo accounts
            try {
                $demoAccountIds = Account::where('is_demo', true)->pluck('id');
            } catch (\Throwable $e) {
                $demoAccountIds = collect();
            }

            $labels = [];
            $values = [];

            switch ($period) {
                case 'today':
                    // Hourly data for last 24h in 6-hour intervals
                    for ($hour = 0; $hour < 24; $hour += 6) {
                        $start = now()->subHours(24)->startOfHour()->addHours($hour);
                        $end = $start->copy()->addHours(6);

                        $labels[] = sprintf('%02dh', $start->hour);
                        $values[] = Charge::where('status', 'approved')
                            ->whereBetween('paid_at', [$start, $end])
                            ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                            ->sum('amount');
                    }
                    break;

                case 'week':
                    // Daily data for last 7 days
                    for ($i = 6; $i >= 0; $i--) {
                        $date = now()->subDays($i);
                        $labels[] = $date->format('d/m');
                        $values[] = Charge::where('status', 'approved')
                            ->whereDate('paid_at', $date)
                            ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                            ->sum('amount');
                    }
                    break;

                case 'month':
                    // Daily data for last 30 days
                    for ($i = 29; $i >= 0; $i--) {
                        $date = now()->subDays($i);
                        $labels[] = $date->format('d/m');
                        $values[] = Charge::where('status', 'approved')
                            ->whereDate('paid_at', $date)
                            ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                            ->sum('amount');
                    }
                    break;

                case 'year':
                    // Monthly data for current year
                    $months = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
                    for ($month = 1; $month <= 12; $month++) {
                        $start = now()->startOfYear()->addMonths($month - 1);
                        $end = $start->copy()->endOfMonth();

                        $labels[] = $months[$month - 1];
                        $values[] = Charge::where('status', 'approved')
                            ->whereBetween('paid_at', [$start, $end])
                            ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                            ->sum('amount');
                    }
                    break;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'labels' => $labels,
                    'values' => $values,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AdminController::revenueChart error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => true,
                'data' => ['labels' => [], 'values' => []],
            ]);
        }
    }

    /**
     * Get payment methods statistics for admin dashboard
     */
    public function paymentMethodsStats(Request $request): JsonResponse
    {
        try {
            $period = $request->input('period', 'month');

            // Exclude demo accounts
            try {
                $demoAccountIds = Account::where('is_demo', true)->pluck('id');
            } catch (\Throwable $e) {
                $demoAccountIds = collect();
            }

            // Determine date range based on period
            $startDate = match ($period) {
                'today' => now()->startOfDay(),
                'week' => now()->subDays(7)->startOfDay(),
                'month' => now()->startOfMonth(),
                'year' => now()->startOfYear(),
                default => now()->startOfMonth(),
            };

            // Get payment method stats
            $stats = Charge::where('status', 'approved')
                ->where('paid_at', '>=', $startDate)
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->selectRaw('payment_method, COUNT(*) as count, SUM(amount) as amount')
                ->groupBy('payment_method')
                ->get();

            $total = $stats->sum('amount');

            $methods = $stats->map(function ($item) use ($total) {
                $methodName = match (strtolower($item->payment_method ?? 'other')) {
                    'pix' => 'PIX',
                    'credit_card', 'card', 'cartao' => 'Cartão de Crédito',
                    'boleto' => 'Boleto',
                    'debit_card' => 'Cartão de Débito',
                    default => ucfirst($item->payment_method ?? 'Outros'),
                };

                return [
                    'method' => $methodName,
                    'count' => (int) $item->count,
                    'amount' => (int) $item->amount,
                    'percentage' => $total > 0 ? round(($item->amount / $total) * 100, 0) : 0,
                ];
            })->sortByDesc('amount')->values();

            // If no data, return default structure
            if ($methods->isEmpty()) {
                $methods = collect([
                    ['method' => 'PIX', 'count' => 0, 'amount' => 0, 'percentage' => 0],
                    ['method' => 'Cartão de Crédito', 'count' => 0, 'amount' => 0, 'percentage' => 0],
                    ['method' => 'Boleto', 'count' => 0, 'amount' => 0, 'percentage' => 0],
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $methods,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AdminController::paymentMethodsStats error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'internal_error', 'message' => 'Erro ao carregar estatísticas de métodos de pagamento'],
                'data' => [
                    ['method' => 'PIX', 'count' => 0, 'amount' => 0, 'percentage' => 0],
                    ['method' => 'Cartão de Crédito', 'count' => 0, 'amount' => 0, 'percentage' => 0],
                    ['method' => 'Boleto', 'count' => 0, 'amount' => 0, 'percentage' => 0],
                ],
            ], 500);
        }
    }

    /**
     * SLA view by acquirer/route with latency and fallback signals
     */
    public function acquirerSla(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'period' => ['sometimes', 'in:24h,7d,30d,90d'],
            ]);

            $period = $validated['period'] ?? '30d';
            $startDate = $this->resolveWindowStart($period);

            try {
                $demoAccountIds = Account::where('is_demo', true)->pluck('id');
            } catch (\Throwable $e) {
                $demoAccountIds = collect();
            }

            $charges = Charge::query()
                ->select(['id', 'account_id', 'acquirer', 'payment_method', 'status', 'created_at', 'paid_at', 'updated_at'])
                ->where('created_at', '>=', $startDate)
                ->whereNotNull('acquirer')
                ->where('acquirer', '!=', '')
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds))
                ->get();

            if ($charges->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'period' => $period,
                        'window_start' => $startDate->toIso8601String(),
                        'generated_at' => now()->toIso8601String(),
                        'overall' => [
                            'total_transactions' => 0,
                            'approval_rate' => 0,
                            'fallback_rate' => 0,
                        ],
                        'acquirers' => [],
                        'routes' => [],
                    ],
                ]);
            }

            $chargeIds = $charges->pluck('id')->values();

            $routeAttempts = Transaction::query()
                ->whereIn('charge_id', $chargeIds)
                ->select('charge_id')
                ->selectRaw('COUNT(*) as attempts')
                ->selectRaw('COUNT(DISTINCT acquirer_id) as distinct_acquirers')
                ->groupBy('charge_id')
                ->get()
                ->keyBy('charge_id');

            $acquirerSlugById = AcquirerEntity::query()
                ->pluck('slug', 'id')
                ->map(fn ($slug) => $this->normalizeAcquirer((string) $slug))
                ->all();

            $primaryAcquirerByAccount = [];
            $accountIds = $charges->pluck('account_id')->filter()->unique()->values();
            if ($accountIds->isNotEmpty()) {
                $accountAcquirers = AccountAcquirer::query()
                    ->whereIn('account_id', $accountIds)
                    ->where('is_active', true)
                    ->orderBy('account_id')
                    ->orderBy('priority')
                    ->get(['account_id', 'acquirer_id', 'priority']);

                foreach ($accountAcquirers as $accountAcquirer) {
                    $accountId = (string) $accountAcquirer->account_id;
                    if (isset($primaryAcquirerByAccount[$accountId])) {
                        continue;
                    }

                    $acquirerId = (string) $accountAcquirer->acquirer_id;
                    $primarySlug = $acquirerSlugById[$acquirerId] ?? null;
                    if ($primarySlug) {
                        $primaryAcquirerByAccount[$accountId] = $primarySlug;
                    }
                }
            }

            $routeBuckets = [];
            $acquirerBuckets = [];

            foreach ($charges as $charge) {
                $acquirer = $this->normalizeAcquirer((string) $charge->acquirer);
                $paymentMethod = $this->normalizePaymentMethod((string) ($charge->payment_method ?? 'unknown'));
                $routeKey = $acquirer . '::' . $paymentMethod;
                $isApproved = in_array((string) $charge->status, ['approved', 'paid'], true);

                if (!isset($routeBuckets[$routeKey])) {
                    $routeBuckets[$routeKey] = [
                        'acquirer' => $acquirer,
                        'payment_method' => $paymentMethod,
                        'total_transactions' => 0,
                        'approved_transactions' => 0,
                        'fallback_count' => 0,
                        'latencies' => [],
                    ];
                }

                if (!isset($acquirerBuckets[$acquirer])) {
                    $acquirerBuckets[$acquirer] = [
                        'acquirer' => $acquirer,
                        'total_transactions' => 0,
                        'approved_transactions' => 0,
                        'fallback_count' => 0,
                        'latencies' => [],
                    ];
                }

                $routeBuckets[$routeKey]['total_transactions']++;
                $acquirerBuckets[$acquirer]['total_transactions']++;

                if ($isApproved) {
                    $routeBuckets[$routeKey]['approved_transactions']++;
                    $acquirerBuckets[$acquirer]['approved_transactions']++;
                }

                $latencyMs = $this->resolveLatencyMs($charge);
                if (!is_null($latencyMs)) {
                    $routeBuckets[$routeKey]['latencies'][] = $latencyMs;
                    $acquirerBuckets[$acquirer]['latencies'][] = $latencyMs;
                }

                $routeMeta = $routeAttempts->get($charge->id);
                $fallbackTriggered = false;

                if ($routeMeta && (int) $routeMeta->distinct_acquirers > 1) {
                    $fallbackTriggered = true;
                } else {
                    $accountPrimary = $primaryAcquirerByAccount[(string) $charge->account_id] ?? null;
                    if ($accountPrimary && $accountPrimary !== $acquirer) {
                        $fallbackTriggered = true;
                    }
                }

                if ($fallbackTriggered) {
                    $routeBuckets[$routeKey]['fallback_count']++;
                    $acquirerBuckets[$acquirer]['fallback_count']++;
                }
            }

            $formatBucket = function (array $bucket, bool $withPaymentMethod = true): array {
                $total = (int) ($bucket['total_transactions'] ?? 0);
                $approved = (int) ($bucket['approved_transactions'] ?? 0);
                $fallback = (int) ($bucket['fallback_count'] ?? 0);
                $approvalRate = $total > 0 ? round(($approved / $total) * 100, 2) : 0;
                $fallbackRate = $total > 0 ? round(($fallback / $total) * 100, 2) : 0;
                $avgLatency = $this->averageValue($bucket['latencies'] ?? []);
                $p95Latency = $this->percentileValue($bucket['latencies'] ?? [], 95);
                $state = $this->resolveSlaState($approvalRate, $fallbackRate, $p95Latency);

                $payload = [
                    'acquirer' => $bucket['acquirer'],
                    'route' => strtoupper($bucket['acquirer']) . ' / ' . strtoupper($bucket['payment_method'] ?? 'all'),
                    'total_transactions' => $total,
                    'approved_transactions' => $approved,
                    'approval_rate' => $approvalRate,
                    'fallback_count' => $fallback,
                    'fallback_rate' => $fallbackRate,
                    'avg_latency_ms' => $avgLatency,
                    'p95_latency_ms' => $p95Latency,
                    'sla_state' => $state,
                ];

                if ($withPaymentMethod) {
                    $payload['payment_method'] = $bucket['payment_method'];
                }

                return $payload;
            };

            $routeRows = collect($routeBuckets)
                ->map(fn ($bucket) => $formatBucket($bucket, true))
                ->sortBy(function (array $row) {
                    $stateRank = $row['sla_state'] === 'critical' ? 0 : ($row['sla_state'] === 'attention' ? 1 : 2);
                    $volumeRank = max(0, 999999999 - (int) ($row['total_transactions'] ?? 0));
                    return sprintf('%d-%010d', $stateRank, $volumeRank);
                })
                ->values();

            $acquirerRows = collect($acquirerBuckets)
                ->map(function (array $bucket) use ($formatBucket) {
                    unset($bucket['payment_method']);
                    return $formatBucket($bucket, false);
                })
                ->sortBy(function (array $row) {
                    $stateRank = $row['sla_state'] === 'critical' ? 0 : ($row['sla_state'] === 'attention' ? 1 : 2);
                    $volumeRank = max(0, 999999999 - (int) ($row['total_transactions'] ?? 0));
                    return sprintf('%d-%010d', $stateRank, $volumeRank);
                })
                ->values();

            $overallTotal = (int) $charges->count();
            $overallApproved = (int) $charges->whereIn('status', ['approved', 'paid'])->count();
            $overallFallback = (int) collect($routeBuckets)->sum('fallback_count');

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => $period,
                    'window_start' => $startDate->toIso8601String(),
                    'generated_at' => now()->toIso8601String(),
                    'overall' => [
                        'total_transactions' => $overallTotal,
                        'approval_rate' => $overallTotal > 0 ? round(($overallApproved / $overallTotal) * 100, 2) : 0,
                        'fallback_rate' => $overallTotal > 0 ? round(($overallFallback / $overallTotal) * 100, 2) : 0,
                    ],
                    'acquirers' => $acquirerRows,
                    'routes' => $routeRows,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('AdminController::acquirerSla error', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Erro ao montar SLA por adquirente'],
            ], 500);
        }
    }

    /**
     * Risk matrix by seller with scoring signals for mass actions
     */
    public function sellerRiskMatrix(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'period' => ['sometimes', 'in:7d,30d,90d'],
                'search' => ['sometimes', 'string', 'max:120'],
                'status' => ['sometimes', 'in:all,active,suspended,blocked'],
                'risk_level' => ['sometimes', 'in:all,low,medium,high'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            ]);

            $period = $validated['period'] ?? '30d';
            $perPage = (int) ($validated['per_page'] ?? 20);
            $page = (int) ($validated['page'] ?? 1);
            $startDate = $this->resolveWindowStart($period);
            $riskLevelFilter = $validated['risk_level'] ?? 'all';

            try {
                $demoAccountIds = Account::where('is_demo', true)->pluck('id');
            } catch (\Throwable $e) {
                $demoAccountIds = collect();
            }

            $usersQuery = User::query()
                ->select(['id', 'account_id', 'name', 'email', 'status', 'kyc_status', 'created_at'])
                ->whereNotNull('account_id')
                ->when($demoAccountIds->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $demoAccountIds));

            if (!empty($validated['status']) && $validated['status'] !== 'all') {
                $usersQuery->where('status', $validated['status']);
            }

            if (!empty($validated['search'])) {
                $searchTerm = '%' . addcslashes($validated['search'], '%_\\') . '%';
                $dbDriver = config('database.default');

                $usersQuery->where(function ($q) use ($searchTerm, $dbDriver) {
                    if ($dbDriver === 'pgsql') {
                        $q->where('name', 'ilike', $searchTerm)
                            ->orWhere('email', 'ilike', $searchTerm)
                            ->orWhere('id', 'ilike', $searchTerm);
                    } else {
                        $q->where('name', 'like', $searchTerm)
                            ->orWhere('email', 'like', $searchTerm)
                            ->orWhere('id', 'like', $searchTerm);
                    }
                });
            }

            $users = $usersQuery->orderBy('created_at', 'desc')->get();
            if ($users->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'meta' => [
                        'current_page' => $page,
                        'per_page' => $perPage,
                        'total' => 0,
                        'last_page' => 1,
                    ],
                    'summary' => [
                        'high' => 0,
                        'medium' => 0,
                        'low' => 0,
                    ],
                ]);
            }

            $accountIds = $users->pluck('account_id')->filter()->unique()->values();

            $accounts = Account::query()
                ->whereIn('id', $accountIds)
                ->select(['id', 'name', 'status'])
                ->get()
                ->keyBy('id');

            $chargeStats = Charge::query()
                ->whereIn('account_id', $accountIds)
                ->where('created_at', '>=', $startDate)
                ->select('account_id')
                ->selectRaw('COUNT(*) as total_charges')
                ->selectRaw("SUM(CASE WHEN status IN ('approved', 'paid') THEN 1 ELSE 0 END) as approved_charges")
                ->selectRaw("SUM(CASE WHEN status IN ('failed', 'declined', 'cancelled') THEN 1 ELSE 0 END) as failed_charges")
                ->selectRaw("SUM(CASE WHEN status IN ('approved', 'paid') THEN amount ELSE 0 END) as approved_volume")
                ->groupBy('account_id')
                ->get()
                ->keyBy('account_id');

            $withdrawalStats = Withdrawal::query()
                ->whereIn('account_id', $accountIds)
                ->where('created_at', '>=', $startDate)
                ->select('account_id')
                ->selectRaw("SUM(CASE WHEN status IN ('pending', 'pending_approval', 'processing') THEN 1 ELSE 0 END) as pending_count")
                ->selectRaw("SUM(CASE WHEN status IN ('pending', 'pending_approval', 'processing') THEN amount ELSE 0 END) as pending_amount")
                ->groupBy('account_id')
                ->get()
                ->keyBy('account_id');

            $chargebackStats = Chargeback::query()
                ->whereIn('account_id', $accountIds)
                ->where('created_at', '>=', $startDate)
                ->select('account_id')
                ->selectRaw('COUNT(*) as total_chargebacks')
                ->selectRaw("SUM(CASE WHEN status IN ('open', 'under_review', 'evidence_sent') THEN 1 ELSE 0 END) as active_chargebacks")
                ->groupBy('account_id')
                ->get()
                ->keyBy('account_id');

            $walletBalances = AccountWallet::query()
                ->whereIn('account_id', $accountIds)
                ->where('currency', 'BRL')
                ->pluck('available_balance', 'account_id');

            $rows = $users->map(function (User $user) use ($accounts, $chargeStats, $withdrawalStats, $chargebackStats, $walletBalances) {
                $accountId = (string) $user->account_id;
                $account = $accounts->get($accountId);

                $charge = $chargeStats->get($accountId);
                $withdrawal = $withdrawalStats->get($accountId);
                $chargeback = $chargebackStats->get($accountId);

                $totalCharges = (int) ($charge->total_charges ?? 0);
                $approvedCharges = (int) ($charge->approved_charges ?? 0);
                $failedCharges = (int) ($charge->failed_charges ?? 0);
                $approvedVolume = (int) ($charge->approved_volume ?? 0);

                $pendingWithdrawals = (int) ($withdrawal->pending_count ?? 0);
                $pendingWithdrawalsAmount = (int) round((float) ($withdrawal->pending_amount ?? 0));

                $totalChargebacks = (int) ($chargeback->total_chargebacks ?? 0);
                $activeChargebacks = (int) ($chargeback->active_chargebacks ?? 0);

                $approvalRate = $totalCharges > 0 ? round(($approvedCharges / $totalCharges) * 100, 2) : 0;
                $chargebackRate = $approvedCharges > 0
                    ? round(($totalChargebacks / max(1, $approvedCharges)) * 100, 2)
                    : ($totalChargebacks > 0 ? 100 : 0);

                $availableBalance = (int) ($walletBalances[$accountId] ?? 0);

                $risk = $this->buildRiskProfile([
                    'total_charges' => $totalCharges,
                    'approval_rate' => $approvalRate,
                    'chargeback_rate' => $chargebackRate,
                    'pending_withdrawals' => $pendingWithdrawals,
                    'pending_withdrawals_amount' => $pendingWithdrawalsAmount,
                    'active_chargebacks' => $activeChargebacks,
                    'available_balance' => $availableBalance,
                ], (string) ($user->status ?? 'active'), (string) ($user->kyc_status ?? 'pending'));

                return [
                    'user_id' => $user->id,
                    'account_id' => $user->account_id,
                    'seller_name' => $user->name,
                    'seller_email' => $user->email,
                    'status' => $user->status ?? 'active',
                    'kyc_status' => $user->kyc_status ?? 'pending',
                    'account_name' => $account?->name,
                    'account_status' => $account?->status,
                    'risk_score' => $risk['score'],
                    'risk_level' => $risk['level'],
                    'risk_signals' => $risk['signals'],
                    'recommended_actions' => $risk['actions'],
                    'metrics' => [
                        'total_charges' => $totalCharges,
                        'approved_charges' => $approvedCharges,
                        'failed_charges' => $failedCharges,
                        'approved_volume' => $approvedVolume,
                        'approval_rate' => $approvalRate,
                        'chargebacks' => $totalChargebacks,
                        'active_chargebacks' => $activeChargebacks,
                        'chargeback_rate' => $chargebackRate,
                        'pending_withdrawals' => $pendingWithdrawals,
                        'pending_withdrawals_amount' => $pendingWithdrawalsAmount,
                        'available_balance' => $availableBalance,
                    ],
                    'created_at' => optional($user->created_at)->toIso8601String(),
                ];
            });

            if ($riskLevelFilter !== 'all') {
                $rows = $rows->where('risk_level', $riskLevelFilter)->values();
            }

            $rows = $rows->sortBy(function (array $row) {
                $scoreRank = max(0, 100 - (int) ($row['risk_score'] ?? 0));
                $pendingRank = max(0, 999999999 - (int) ($row['metrics']['pending_withdrawals_amount'] ?? 0));
                $volumeRank = max(0, 999999999 - (int) ($row['metrics']['approved_volume'] ?? 0));
                return sprintf('%03d-%010d-%010d', $scoreRank, $pendingRank, $volumeRank);
            })->values();

            $total = $rows->count();
            $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
            $page = min($page, $lastPage);
            $paginatedRows = $rows->forPage($page, $perPage)->values();

            return response()->json([
                'success' => true,
                'data' => $paginatedRows,
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => $lastPage,
                ],
                'summary' => [
                    'high' => $rows->where('risk_level', 'high')->count(),
                    'medium' => $rows->where('risk_level', 'medium')->count(),
                    'low' => $rows->where('risk_level', 'low')->count(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('AdminController::sellerRiskMatrix error', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Erro ao montar matriz de risco'],
            ], 500);
        }
    }

    /**
     * Bulk actions on risk matrix rows
     */
    public function sellerRiskBulkAction(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'action' => ['required', 'in:suspend,activate,recalculate_balance,request_kyc_review'],
                'user_ids' => ['required', 'array', 'min:1', 'max:100'],
                'user_ids.*' => ['string'],
                'reason' => ['nullable', 'string', 'max:500'],
            ]);

            $action = $validated['action'];
            $reason = $validated['reason'] ?? null;
            $userIds = collect($validated['user_ids'])->filter()->unique()->values();
            $users = User::whereIn('id', $userIds)->get()->keyBy('id');

            $processed = [];
            $failed = [];

            foreach ($userIds as $userId) {
                $user = $users->get($userId);
                if (!$user) {
                    $failed[] = ['user_id' => $userId, 'reason' => 'Cliente nao encontrado'];
                    continue;
                }

                try {
                    $oldStatus = (string) ($user->status ?? 'active');
                    $oldKycStatus = (string) ($user->kyc_status ?? 'pending');
                    $result = ['user_id' => $user->id, 'action' => $action];
                    $auditOldValues = [
                        'status' => $oldStatus,
                        'kyc_status' => $oldKycStatus,
                    ];
                    $auditNewValues = [];

                    if ($action === 'suspend') {
                        $user->forceFill([
                            'status' => 'suspended',
                            'block_reason' => $reason ?: 'Suspenso via matriz de risco em lote',
                            'blocked_at' => now(),
                        ])->save();

                        cache()->put("user_blocked:{$user->id}", true, now()->addDays(30));
                        $result['status'] = 'suspended';
                        $auditNewValues = [
                            'status' => 'suspended',
                            'block_reason' => $reason ?: 'Suspenso via matriz de risco em lote',
                        ];
                    } elseif ($action === 'activate') {
                        $user->forceFill([
                            'status' => 'active',
                            'block_reason' => null,
                            'blocked_at' => null,
                        ])->save();

                        cache()->forget("user_blocked:{$user->id}");
                        $result['status'] = 'active';
                        $auditNewValues = [
                            'status' => 'active',
                            'block_reason' => null,
                        ];
                    } elseif ($action === 'request_kyc_review') {
                        if (($user->kyc_status ?? 'pending') !== 'approved') {
                            $user->forceFill(['kyc_status' => 'under_review'])->save();
                        }
                        $currentKycStatus = (string) ($user->fresh()->kyc_status ?? 'under_review');
                        $result['kyc_status'] = $currentKycStatus;
                        $auditNewValues = [
                            'status' => (string) ($user->status ?? $oldStatus),
                            'kyc_status' => $currentKycStatus,
                        ];
                    } elseif ($action === 'recalculate_balance') {
                        if (!$user->account_id) {
                            throw new \RuntimeException('Conta nao vinculada ao cliente');
                        }

                        $result['balance'] = $this->recalculateWalletByAccount((string) $user->account_id);
                        $auditOldValues['balance'] = [
                            'previous_balance' => (int) ($result['balance']['previous_balance'] ?? 0),
                        ];
                        $auditNewValues = [
                            'status' => (string) ($user->status ?? $oldStatus),
                            'kyc_status' => (string) ($user->kyc_status ?? $oldKycStatus),
                            'balance' => [
                                'new_balance' => (int) ($result['balance']['new_balance'] ?? 0),
                                'pending_balance' => (int) ($result['balance']['pending_balance'] ?? 0),
                            ],
                        ];
                    }

                    if ($auditNewValues === []) {
                        $auditNewValues = [
                            'status' => (string) ($user->status ?? $oldStatus),
                            'kyc_status' => (string) ($user->kyc_status ?? $oldKycStatus),
                        ];
                    }

                    $this->writeControlTowerAudit(
                        $request,
                        'control_tower_risk_bulk_' . $action,
                        'control_tower_risk_bulk',
                        $user->id,
                        $user->account_id,
                        $auditOldValues,
                        $auditNewValues,
                        [
                            'target_user_id' => $user->id,
                            'action' => $action,
                            'reason' => $reason,
                        ]
                    );

                    $processed[] = $result;
                } catch (\Throwable $e) {
                    $failed[] = ['user_id' => $user->id, 'reason' => $e->getMessage()];
                }
            }

            Log::warning('Admin bulk risk action executed', [
                'admin_id' => $request->user()?->id,
                'action' => $action,
                'requested' => $userIds->count(),
                'processed' => count($processed),
                'failed' => count($failed),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'action' => $action,
                    'requested' => $userIds->count(),
                    'processed' => count($processed),
                    'failed' => count($failed),
                    'results' => $processed,
                    'errors' => $failed,
                ],
                'message' => 'Acao em massa executada',
            ]);
        } catch (\Throwable $e) {
            Log::error('AdminController::sellerRiskBulkAction error', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Erro ao executar acao em massa'],
            ], 500);
        }
    }

    /**
     * Execute predefined incident playbooks from control tower
     */
    public function executeIncidentPlaybook(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'playbook' => ['required', 'in:freeze_withdrawals,freeze_all_money_flows,manual_fallback,recovery_safe,recovery_full'],
                'note' => ['nullable', 'string', 'max:500'],
                'dry_run' => ['sometimes', 'boolean'],
            ]);

            $playbook = $validated['playbook'];
            $note = $validated['note'] ?? null;
            $dryRun = (bool) ($validated['dry_run'] ?? false);

            $playbooks = [
                'freeze_withdrawals' => [
                    'title' => 'Congelar saques e processamento',
                    'settings' => [
                        'withdrawals_enabled' => false,
                        'withdrawal_processing_enabled' => false,
                        'auto_withdrawal_enabled' => false,
                    ],
                ],
                'freeze_all_money_flows' => [
                    'title' => 'Congelar pagamentos e saques',
                    'settings' => [
                        'payments_enabled' => false,
                        'withdrawals_enabled' => false,
                        'withdrawal_processing_enabled' => false,
                        'auto_withdrawal_enabled' => false,
                    ],
                ],
                'manual_fallback' => [
                    'title' => 'Fallback manual com guardrails',
                    'settings' => [
                        'payments_enabled' => true,
                        'withdrawals_enabled' => true,
                        'withdrawal_processing_enabled' => true,
                        'auto_withdrawal_enabled' => false,
                        'auto_approve_limit' => 0,
                    ],
                ],
                'recovery_safe' => [
                    'title' => 'Recuperacao segura',
                    'settings' => [
                        'payments_enabled' => true,
                        'withdrawals_enabled' => true,
                        'withdrawal_processing_enabled' => true,
                        'auto_withdrawal_enabled' => false,
                    ],
                ],
                'recovery_full' => [
                    'title' => 'Recuperacao completa',
                    'settings' => [
                        'payments_enabled' => true,
                        'withdrawals_enabled' => true,
                        'withdrawal_processing_enabled' => true,
                        'auto_withdrawal_enabled' => true,
                    ],
                ],
            ];

            $definition = $playbooks[$playbook] ?? null;
            if (!$definition) {
                return response()->json(['success' => false, 'message' => 'Playbook invalido'], 422);
            }

            $oldSwitches = [
                'payments_enabled' => (bool) SystemSetting::get('payments_enabled', true),
                'withdrawals_enabled' => (bool) SystemSetting::get('withdrawals_enabled', true),
                'withdrawal_processing_enabled' => (bool) SystemSetting::get('withdrawal_processing_enabled', true),
                'auto_withdrawal_enabled' => (bool) SystemSetting::get('auto_withdrawal_enabled', false),
                'auto_approve_limit' => (int) SystemSetting::get('auto_approve_limit', 0),
            ];

            $changes = collect($definition['settings'])
                ->map(function ($value, $key) use ($oldSwitches) {
                    return [
                        'key' => $key,
                        'from' => $oldSwitches[$key] ?? null,
                        'to' => $value,
                    ];
                })
                ->values();

            if ($dryRun) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'playbook' => $playbook,
                        'title' => $definition['title'],
                        'dry_run' => true,
                        'current_switches' => $oldSwitches,
                        'planned_changes' => $changes->all(),
                        'executed_at' => now()->toIso8601String(),
                    ],
                    'message' => 'Simulacao concluida sem alterar configuracoes',
                ]);
            }

            foreach ($definition['settings'] as $key => $value) {
                $existing = SystemSetting::where('key', $key)->first();
                if ($existing) {
                    SystemSetting::set($key, $value);
                    continue;
                }

                $type = is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : 'string');

                SystemSetting::create([
                    'key' => $key,
                    'value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                    'type' => $type,
                    'group' => 'withdrawals',
                    'description' => 'playbook:' . $playbook,
                ]);
            }

            $switches = [
                'payments_enabled' => (bool) SystemSetting::get('payments_enabled', true),
                'withdrawals_enabled' => (bool) SystemSetting::get('withdrawals_enabled', true),
                'withdrawal_processing_enabled' => (bool) SystemSetting::get('withdrawal_processing_enabled', true),
                'auto_withdrawal_enabled' => (bool) SystemSetting::get('auto_withdrawal_enabled', false),
                'auto_approve_limit' => (int) SystemSetting::get('auto_approve_limit', 0),
            ];

            $this->writeControlTowerAudit(
                $request,
                'control_tower_playbook_execute',
                'control_tower_playbook',
                $playbook,
                null,
                $oldSwitches,
                $switches,
                [
                    'playbook' => $playbook,
                    'title' => $definition['title'],
                    'note' => $note,
                    'changes' => $changes->all(),
                ]
            );

            Log::warning('Incident playbook executed from control tower', [
                'admin_id' => $request->user()?->id,
                'playbook' => $playbook,
                'title' => $definition['title'],
                'note' => $note,
                'switches' => $switches,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'playbook' => $playbook,
                    'title' => $definition['title'],
                    'applied_settings' => $definition['settings'],
                    'changes' => $changes->all(),
                    'switches' => $switches,
                    'executed_at' => now()->toIso8601String(),
                ],
                'message' => 'Playbook executado com sucesso',
            ]);
        } catch (\Throwable $e) {
            Log::error('AdminController::executeIncidentPlaybook error', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Erro ao executar playbook'],
            ], 500);
        }
    }

    /**
     * List all charges across the platform for admin
     */
    public function allCharges(Request $request): JsonResponse
    {
        try {
            $perPage = min((int) $request->input('per_page', 20), 100);
            $status = $request->input('status');
            $paymentMethod = $request->input('payment_method');
            $search = $request->input('search');
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
            $acquirer = $request->input('acquirer');

            $query = Charge::with([
                    'product' => fn ($q) => $q->withTrashed()->select('id', 'name'),
                    'account' => fn ($q) => $q->select('id', 'name'),
                ])
                ->orderBy('created_at', 'desc');

            if ($status && $status !== 'all') {
                $query->where('status', $status);
            }

            if ($paymentMethod && $paymentMethod !== 'all') {
                $query->where('payment_method', $paymentMethod);
            }

            if ($acquirer && $acquirer !== 'all') {
                $query->where('acquirer', $acquirer);
            }

            if ($search) {
                $searchTerm = '%' . $search . '%';
                                $dbDriver = config('database.default');
                $query->where(function ($q) use ($searchTerm, $dbDriver) {
                    $q->where('id', 'like', $searchTerm)
                      ->orWhere('external_id', 'like', $searchTerm)
                                            ->when($dbDriver === 'pgsql', function ($q) use ($searchTerm) {
                                                    $q->orWhereRaw("COALESCE(customer->>'name','') ILIKE ?", [$searchTerm])
                                                        ->orWhereRaw("COALESCE(customer->>'email','') ILIKE ?", [$searchTerm]);
                                            }, function ($q) use ($searchTerm) {
                                                    $q->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(customer, '$.name')) LIKE ?", [$searchTerm])
                                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(customer, '$.email')) LIKE ?", [$searchTerm]);
                                            });
                });
            }

            if ($dateFrom) {
                $query->whereDate('created_at', '>=', $dateFrom);
            }

            if ($dateTo) {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            $charges = $query->paginate($perPage);

            $data = $charges->map(function ($charge) {
                $customerData = $charge->customer ?? [];
                $metadata = $charge->metadata ?? [];
                $productName = $charge->product?->name ?? $charge->description ?? ($metadata['products'][0]['name'] ?? null);
                $paymentData = $charge->payment_data ?? [];

                return [
                    'id' => $charge->id,
                    'account_id' => $charge->account_id,
                    'product_id' => $charge->product_id,
                    'checkout_id' => $charge->checkout_id,
                    'affiliate_id' => $charge->affiliate_id,
                    'account_name' => $charge->account?->name ?? '—',
                    'amount' => $charge->amount,
                    'fee_amount' => $charge->fee_amount ?? 0,
                    'net_amount' => $charge->net_amount ?? 0,
                    'status' => $charge->status,
                    'payment_method' => $charge->payment_method,
                    'acquirer' => $charge->acquirer,
                    'installments' => $charge->installments,
                    'product_name' => $productName,
                    'checkout_name' => $charge->checkout?->name,
                    'affiliate_name' => $charge->affiliate?->name,
                    'affiliate_commission' => $metadata['affiliate_commission'] ?? ($metadata['affiliate']['commission'] ?? null),
                    'customer_name' => $customerData['name'] ?? $customerData['nome'] ?? null,
                    'customer_email' => $customerData['email'] ?? null,
                    'customer_document' => $customerData['document'] ?? $customerData['cpf'] ?? $customerData['cnpj'] ?? null,
                    'customer_phone' => $customerData['phone'] ?? $customerData['telefone'] ?? null,
                    'description' => $charge->description,
                    'notes' => $metadata['notes'] ?? null,
                    'metadata' => $metadata,
                    'payment_data' => $paymentData,
                    'external_id' => $charge->external_id,
                    'acquirer_name' => $charge->acquirer,
                    'acquirer_transaction_id' => $charge->acquirer_transaction_id ?? $charge->external_id,
                    'payment_url' => $charge->payment_url,
                    'pix_qr_code' => $charge->pix_qr_code ?? $paymentData['qr_code'] ?? null,
                    'pix_qr_code_url' => $charge->pix_qr_code_url ?? $paymentData['qr_code_url'] ?? null,
                    'pix_emv' => $charge->pix_copy_paste ?? $paymentData['emv'] ?? $paymentData['qr_code_text'] ?? null,
                    'pix_end2end_id' => $charge->pix_end2end_id ?? $paymentData['end2end_id'] ?? null,
                    'boleto_barcode' => $charge->boleto_barcode ?? $paymentData['barcode'] ?? null,
                    'boleto_digitable_line' => $charge->boleto_digitable_line ?? $paymentData['digitable_line'] ?? null,
                    'boleto_url' => $charge->boleto_url ?? $paymentData['boleto_url'] ?? $paymentData['pdf'] ?? null,
                    'card_brand' => $paymentData['card_brand'] ?? null,
                    'card_last_digits' => $paymentData['card_last_digits'] ?? $paymentData['last_digits'] ?? null,
                    'created_at' => $charge->created_at,
                    'paid_at' => $charge->paid_at,
                    'expires_at' => $charge->expires_at,
                    'refunded_amount' => $charge->refunded_amount,
                    'refunded_at' => $charge->refunded_at,
                    'cancelled_at' => $charge->cancelled_at,
                    'cancellation_reason' => $charge->cancellation_reason,
                    'failure_reason' => $charge->failure_reason,
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
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AdminController::allCharges error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Erro ao buscar transações'],
            ], 500);
        }
    }

    private function resolveWindowStart(string $period): Carbon
    {
        return match ($period) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7)->startOfDay(),
            '90d' => now()->subDays(90)->startOfDay(),
            default => now()->subDays(30)->startOfDay(),
        };
    }

    private function normalizeAcquirer(string $acquirer): string
    {
        $normalized = strtolower(trim($acquirer));
        return $normalized !== '' ? $normalized : 'unknown';
    }

    private function normalizePaymentMethod(string $paymentMethod): string
    {
        $normalized = strtolower(trim($paymentMethod));
        if ($normalized === '') {
            return 'unknown';
        }

        return match ($normalized) {
            'credit_card', 'card', 'cartao' => 'credit_card',
            'pix' => 'pix',
            'boleto' => 'boleto',
            default => $normalized,
        };
    }

    private function resolveLatencyMs(Charge $charge): ?int
    {
        if (!$charge->created_at) {
            return null;
        }

        $status = (string) $charge->status;
        $terminalStatuses = ['approved', 'paid', 'failed', 'declined', 'cancelled', 'chargeback', 'refunded'];
        $endAt = $charge->paid_at;

        if (!$endAt && in_array($status, $terminalStatuses, true)) {
            $endAt = $charge->updated_at;
        }

        if (!$endAt) {
            return null;
        }

        $seconds = (int) $charge->created_at->diffInSeconds($endAt);
        return max(0, $seconds * 1000);
    }

    private function averageValue(array $values): ?int
    {
        if (empty($values)) {
            return null;
        }

        return (int) round(array_sum($values) / count($values));
    }

    private function percentileValue(array $values, int $percentile): ?int
    {
        if (empty($values)) {
            return null;
        }

        sort($values);
        $count = count($values);
        $index = (int) ceil(($percentile / 100) * $count) - 1;
        $index = max(0, min($count - 1, $index));

        return (int) $values[$index];
    }

    private function resolveSlaState(float $approvalRate, float $fallbackRate, ?int $p95LatencyMs): string
    {
        $latencyP95 = $p95LatencyMs ?? 0;

        if ($approvalRate < 85 || $fallbackRate >= 20 || $latencyP95 >= 90000) {
            return 'critical';
        }

        if ($approvalRate < 92 || $fallbackRate >= 10 || $latencyP95 >= 45000) {
            return 'attention';
        }

        return 'healthy';
    }

    private function buildRiskProfile(array $metrics, string $status, string $kycStatus): array
    {
        $score = 0;
        $signals = [];

        $approvalRate = (float) ($metrics['approval_rate'] ?? 0);
        $chargebackRate = (float) ($metrics['chargeback_rate'] ?? 0);
        $pendingWithdrawals = (int) ($metrics['pending_withdrawals'] ?? 0);
        $pendingWithdrawalsAmount = (int) ($metrics['pending_withdrawals_amount'] ?? 0);
        $activeChargebacks = (int) ($metrics['active_chargebacks'] ?? 0);
        $availableBalance = (int) ($metrics['available_balance'] ?? 0);
        $totalCharges = (int) ($metrics['total_charges'] ?? 0);

        if (in_array($status, ['blocked', 'suspended'], true)) {
            $score += 45;
            $signals[] = 'Conta com restricao administrativa';
        }

        if ($kycStatus !== 'approved') {
            $score += 18;
            $signals[] = 'KYC pendente ou nao aprovado';
        }

        if ($totalCharges >= 5 && $approvalRate < 80) {
            $score += 24;
            $signals[] = 'Taxa de aprovacao abaixo de 80%';
        } elseif ($totalCharges >= 5 && $approvalRate < 90) {
            $score += 12;
            $signals[] = 'Taxa de aprovacao abaixo de 90%';
        }

        if ($chargebackRate >= 2) {
            $score += 24;
            $signals[] = 'Taxa de chargeback acima de 2%';
        } elseif ($chargebackRate >= 1) {
            $score += 14;
            $signals[] = 'Taxa de chargeback acima de 1%';
        } elseif ($chargebackRate > 0) {
            $score += 8;
            $signals[] = 'Chargebacks recentes detectados';
        }

        if ($activeChargebacks >= 3) {
            $score += 12;
            $signals[] = 'Multiplos chargebacks ativos';
        }

        if ($pendingWithdrawals >= 10) {
            $score += 15;
            $signals[] = 'Fila de saques elevada';
        } elseif ($pendingWithdrawals >= 5) {
            $score += 8;
            $signals[] = 'Fila de saques em atencao';
        }

        if ($pendingWithdrawalsAmount >= 1000000) {
            $score += 10;
            $signals[] = 'Montante pendente de saque elevado';
        }

        if ($availableBalance >= 20000000 && $kycStatus !== 'approved') {
            $score += 8;
            $signals[] = 'Saldo elevado com KYC nao aprovado';
        }

        $score = min(100, $score);

        $level = 'low';
        if ($score >= 70) {
            $level = 'high';
        } elseif ($score >= 40) {
            $level = 'medium';
        }

        $actions = ['monitor'];
        if ($level === 'high') {
            $actions = ['suspend', 'recalculate_balance', 'request_kyc_review'];
        } elseif ($level === 'medium') {
            $actions = ['request_kyc_review', 'recalculate_balance'];
        }

        return [
            'score' => $score,
            'level' => $level,
            'signals' => array_values(array_unique($signals)),
            'actions' => $actions,
        ];
    }

    private function recalculateWalletByAccount(string $accountId): array
    {
        $totalNet = (int) round((float) Charge::where('account_id', $accountId)
            ->where('status', 'approved')
            ->sum(DB::raw('COALESCE(net_amount, amount - COALESCE(fee_amount, 0))')));

        $lockedWithdrawals = (int) round((float) Withdrawal::where('account_id', $accountId)
            ->whereNotIn('status', ['cancelled', 'failed'])
            ->sum('amount'));

        $pendingWithdrawals = (int) round((float) Withdrawal::where('account_id', $accountId)
            ->whereIn('status', ['pending', 'pending_approval', 'processing'])
            ->sum('amount'));

        $correctBalance = max(0, $totalNet - $lockedWithdrawals);

        $wallet = AccountWallet::firstOrCreate(
            ['account_id' => $accountId],
            ['currency' => 'BRL', 'available_balance' => 0, 'pending_balance' => 0, 'blocked_balance' => 0]
        );

        $previousBalance = (int) $wallet->available_balance;

        $wallet->update([
            'available_balance' => $correctBalance,
            'pending_balance' => $pendingWithdrawals,
        ]);

        return [
            'previous_balance' => $previousBalance,
            'new_balance' => $correctBalance,
            'pending_balance' => $pendingWithdrawals,
        ];
    }
}
