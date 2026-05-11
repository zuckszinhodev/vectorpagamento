<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\Product;
use App\Models\Affiliate;
use App\Models\Checkout;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Format date column in a DB-agnostic way (MySQL + PostgreSQL).
     */
    private const ALLOWED_DATE_COLUMNS = ['paid_at', 'created_at', 'updated_at'];

    private const ALLOWED_DATE_FORMATS = [
        'YYYY-MM-DD HH24:00',
        'YYYY-MM-DD',
        'IYYY-IW',
        'YYYY-MM',
    ];

    private function dateFormat(string $column, string $format): string
    {
        // Whitelist column and format to prevent SQL injection
        if (!in_array($column, self::ALLOWED_DATE_COLUMNS, true)) {
            $column = 'paid_at';
        }
        if (!in_array($format, self::ALLOWED_DATE_FORMATS, true)) {
            $format = 'YYYY-MM-DD';
        }

        $driver = config('database.default');
        $connection = config("database.connections.{$driver}.driver", 'mysql');

        if ($connection === 'pgsql') {
            return "to_char({$column}, '{$format}')";
        }

        $mysqlFormat = str_replace(
            ['YYYY', 'MM', 'DD', 'HH24', 'IYYY', 'IW'],
            ['%Y', '%m', '%d', '%H', '%x', '%v'],
            $format
        );

        return "DATE_FORMAT({$column}, '{$mysqlFormat}')";
    }

    /**
     * Get dashboard summary
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;
            $period = $request->query('period', '30d');

            $dateFrom = null;
            $dateTo = null;

            if ($request->filled('date_from') && $request->filled('date_to')) {
                try {
                    $dateFrom = Carbon::parse($request->query('date_from'))->startOfDay();
                    $dateTo = Carbon::parse($request->query('date_to'))->endOfDay();
                } catch (\Exception $e) {
                    return response()->json(['success' => false, 'message' => 'Período inválido'], 422);
                }

                if ($dateTo->lt($dateFrom)) {
                    return response()->json(['success' => false, 'message' => 'date_to deve ser posterior ou igual a date_from'], 422);
                }

                $period = 'custom';
            } else {
                $dateFrom = $this->getDateFromPeriod($period)->startOfDay();
                $dateTo = Carbon::now()->endOfDay();
            }

            // Cache dashboard data for 60 seconds — fast repeat loads, still near-realtime
            $cacheKey = "dashboard:{$accountId}:{$period}:{$dateFrom->toDateString()}:{$dateTo->toDateString()}";
            $data = Cache::remember($cacheKey, 60, function () use ($accountId, $period, $dateFrom, $dateTo) {
                return $this->buildDashboardData($accountId, $period, $dateFrom, $dateTo);
            });

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ReportController::dashboard error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Build dashboard data (extracted for caching)
     */
private function buildDashboardData(string $accountId, string $period, Carbon $dateFrom, Carbon $dateTo): array
    {
        $dateFrom = $dateFrom->copy()->startOfDay();
        $dateTo = $dateTo->copy()->endOfDay();

        if ($dateTo->lt($dateFrom)) {
            $dateTo = $dateFrom->copy()->endOfDay();
        }

        $dateFromRaw = $dateFrom->format('Y-m-d H:i:s');
        $dateToRaw = $dateTo->format('Y-m-d H:i:s');

        $dateFromObj = $dateFrom->copy();
        $dateToObj = $dateTo->copy();

        $diffDays = $dateFromObj->diffInDays($dateToObj) + 1;
        $previousDateFrom = $dateFromObj->copy()->subDays($diffDays)->startOfDay();
        $previousDateTo = $dateFromObj->copy()->subDay()->endOfDay();

        // Consolidate: get all stats in 2 queries instead of 12+
        $currentStats = DB::selectOne(" 
            SELECT
                COALESCE(SUM(CASE WHEN status IN ('approved','paid') AND paid_at BETWEEN ? AND ? THEN amount END), 0) as revenue,
                COALESCE(COUNT(CASE WHEN status IN ('approved','paid') AND paid_at BETWEEN ? AND ? THEN 1 END), 0) as sales,
                COALESCE(SUM(CASE WHEN status IN ('pending','processing') AND created_at BETWEEN ? AND ? THEN amount END), 0) as pending_amount,
                COALESCE(COUNT(CASE WHEN created_at BETWEEN ? AND ? THEN 1 END), 0) as total_charges,
                COALESCE(COUNT(CASE WHEN status IN ('approved','paid') AND created_at BETWEEN ? AND ? THEN 1 END), 0) as approved_charges
            FROM charges WHERE account_id = ?
        ", [$dateFromRaw, $dateToRaw, $dateFromRaw, $dateToRaw, $dateFromRaw, $dateToRaw, $dateFromRaw, $dateToRaw, $dateFromRaw, $dateToRaw, $accountId]);

        $totalRevenue = (float) $currentStats->revenue;
        $totalSales = (int) $currentStats->sales;
        $pendingCharges = (float) $currentStats->pending_amount;
        $totalCharges = (int) $currentStats->total_charges;
        $approvedCharges = (int) $currentStats->approved_charges;

        $prevStats = DB::selectOne("
            SELECT
                COALESCE(SUM(CASE WHEN status IN ('approved','paid') THEN amount END), 0) as revenue,
                COALESCE(COUNT(CASE WHEN status IN ('approved','paid') THEN 1 END), 0) as sales,
                COUNT(*) as total_charges,
                COALESCE(COUNT(CASE WHEN status IN ('approved','paid') THEN 1 END), 0) as approved_charges
            FROM charges
            WHERE account_id = ? AND created_at BETWEEN ? AND ?
        ", [$accountId, $previousDateFrom->format('Y-m-d H:i:s'), $previousDateTo->format('Y-m-d H:i:s')]);
        $previousRevenue = (float) $prevStats->revenue;
        $previousSales = (int) $prevStats->sales;
        $previousTotalCharges = (int) $prevStats->total_charges;
        $previousApprovedCharges = (int) $prevStats->approved_charges;

        $revenueChange = $previousRevenue > 0
            ? (($totalRevenue - $previousRevenue) / $previousRevenue) * 100
            : ($totalRevenue > 0 ? 100 : 0);

        $salesChange = $previousSales > 0
            ? (($totalSales - $previousSales) / $previousSales) * 100
            : ($totalSales > 0 ? 100 : 0);

        $approvalRate = $totalCharges > 0 ? ($approvedCharges / $totalCharges) * 100 : 0;
        $previousApprovalRate = $previousTotalCharges > 0 ? ($previousApprovedCharges / $previousTotalCharges) * 100 : 0;
        $approvalRateChange = $previousApprovalRate > 0
            ? $approvalRate - $previousApprovalRate
            : ($approvalRate > 0 ? $approvalRate : 0);

        // Conversion rate = same as approval rate (approved/total attempts)
        $conversionRate = $approvalRate;
        $previousConversionRate = $previousApprovalRate;
        $conversionRateChange = $approvalRateChange;

        // Product stats
        $topProducts = Charge::where('charges.account_id', $accountId)
            ->where('charges.status', 'approved')
            ->whereBetween('charges.paid_at', [$dateFromRaw, $dateToRaw])
            ->whereNotNull('charges.product_id')
            ->join('products', 'charges.product_id', '=', 'products.id')
            ->select('products.id', 'products.name', DB::raw('COUNT(*) as sales'), DB::raw('SUM(charges.amount) as revenue'))
            ->groupBy('products.id', 'products.name')
            ->orderBy('revenue', 'desc')
            ->limit(5)
            ->get();

        // Payment method distribution
        $paymentMethods = Charge::where('account_id', $accountId)
            ->where('status', 'approved')
            ->whereBetween('paid_at', [$dateFromRaw, $dateToRaw])
            ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
            ->groupBy('payment_method')
            ->get();

        // Checkout-only revenue (sales through Vector Pagamentos checkout)
        $checkoutStats = Charge::where('account_id', $accountId)
            ->whereNotNull('checkout_id')
            ->where('status', 'approved')
            ->whereBetween('paid_at', [$dateFromRaw, $dateToRaw])
            ->selectRaw('COALESCE(SUM(amount), 0) as revenue, COUNT(*) as count')
            ->first();

        $checkoutRevenue = (float) ($checkoutStats->revenue ?? 0);
        $checkoutCount = (int) ($checkoutStats->count ?? 0);

        // Member area revenue (sales of products linked to courses)
        $memberAreaStats = Charge::where('charges.account_id', $accountId)
            ->whereNotNull('charges.product_id')
            ->join('products', 'charges.product_id', '=', 'products.id')
            ->join('courses', 'courses.product_id', '=', 'products.id')
            ->where('charges.status', 'approved')
            ->whereBetween('charges.paid_at', [$dateFromRaw, $dateToRaw])
            ->selectRaw('COALESCE(SUM(charges.amount), 0) as revenue, COUNT(*) as count')
            ->first();

        $memberAreaRevenue = (float) ($memberAreaStats->revenue ?? 0);
        $memberAreaCount = (int) ($memberAreaStats->count ?? 0);

        // Revenue for chart - group by hour for detailed view
        $hourlyRevenue = Charge::where('account_id', $accountId)
            ->where('status', 'approved')
            ->whereBetween('paid_at', [$dateFromRaw, $dateToRaw])
            ->selectRaw($this->dateFormat('paid_at', 'YYYY-MM-DD HH24:00') . " as datetime, SUM(amount) as revenue, COUNT(*) as sales")
            ->groupByRaw($this->dateFormat('paid_at', 'YYYY-MM-DD HH24:00'))
            ->orderBy('datetime')
            ->get();

        // Daily revenue for summary
        $dailyRevenue = Charge::where('account_id', $accountId)
            ->where('status', 'approved')
            ->whereBetween('paid_at', [$dateFromRaw, $dateToRaw])
            ->selectRaw($this->dateFormat('paid_at', 'YYYY-MM-DD') . " as date, SUM(amount) as revenue, COUNT(*) as sales")
            ->groupByRaw($this->dateFormat('paid_at', 'YYYY-MM-DD'))
            ->orderBy('date')
            ->get();

        // Chargeback alerts (open chargebacks)
        $chargebackAlerts = \App\Models\Chargeback::where('account_id', $accountId)
            ->whereIn('status', ['open', 'evidence_sent', 'under_review'])
            ->count();

        // Payment method revenue breakdown (últimas 24 horas)
        $last24Hours = Carbon::now()->subHours(24);
        $pixRevenue = Charge::where('account_id', $accountId)
            ->where('status', 'approved')
            ->where('payment_method', 'pix')
            ->where('paid_at', '>=', $last24Hours)
            ->sum('amount');

        $cardRevenue = Charge::where('account_id', $accountId)
            ->where('status', 'approved')
            ->where('payment_method', 'credit_card')
            ->where('paid_at', '>=', $last24Hours)
            ->sum('amount');

        $boletoRevenue = Charge::where('account_id', $accountId)
            ->where('status', 'approved')
            ->where('payment_method', 'boleto')
            ->where('paid_at', '>=', $last24Hours)
            ->sum('amount');

        return [
                'summary' => [
                    'total_revenue' => $totalRevenue,
                    'revenue_change' => round($revenueChange, 2),
                    'total_sales' => $totalSales,
                    'sales_change' => round($salesChange, 2),
                    'pending_amount' => $pendingCharges,
                    'average_ticket' => $totalSales > 0 ? round($totalRevenue / $totalSales) : 0,
                    'approval_rate' => round($approvalRate, 1),
                    'approval_rate_change' => round($approvalRateChange, 1),
                    'conversion_rate' => round($conversionRate, 1),
                    'conversion_rate_change' => round($conversionRateChange, 1),
                ],
                'chargeback_alerts' => $chargebackAlerts,
                'pix_revenue' => $pixRevenue,
                'card_revenue' => $cardRevenue,
                'boleto_revenue' => $boletoRevenue,
                'checkout_revenue' => $checkoutRevenue,
                'checkout_count' => $checkoutCount,
                'member_area_revenue' => $memberAreaRevenue,
                'member_area_count' => $memberAreaCount,
                'top_products' => $topProducts,
                'payment_methods' => $paymentMethods,
                'daily_revenue' => $dailyRevenue,
                'hourly_revenue' => $hourlyRevenue,
                'period' => $period,
            ];
    }

    /**
     * Get transactions report
     */
    public function transactions(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $query = Charge::where('account_id', $accountId);

            if ($request->query('status')) {
                $query->where('status', $request->query('status'));
            }

            if ($request->query('payment_method')) {
                $query->where('payment_method', $request->query('payment_method'));
            }

            if ($request->query('date_from')) {
                $query->whereDate('created_at', '>=', $request->query('date_from'));
            }

            if ($request->query('date_to')) {
                $query->whereDate('created_at', '<=', $request->query('date_to'));
            }

            $perPage = min((int) $request->query('per_page', 50), 100);
            $charges = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $charges->items(),
                'meta' => [
                    'current_page' => $charges->currentPage(),
                    'per_page' => $charges->perPage(),
                    'total' => $charges->total(),
                    'last_page' => $charges->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ReportController::transactions error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Get revenue report
     */
    public function revenue(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;
            $period = $request->query('period', '30d');
            $dateFrom = $this->getDateFromPeriod($period);
            $groupBy = $request->query('group_by', 'day');

            $dateFormat = match($groupBy) {
                'hour' => 'YYYY-MM-DD HH24:00',
                'day' => 'YYYY-MM-DD',
                'week' => 'IYYY-IW',
                'month' => 'YYYY-MM',
                default => 'YYYY-MM-DD',
            };

            $dateFormatExpr = $this->dateFormat('paid_at', $dateFormat);

            $data = Charge::where('account_id', $accountId)
                ->where('status', 'approved')
                ->where('paid_at', '>=', $dateFrom)
                ->select(
                    DB::raw("{$dateFormatExpr} as period"),
                    DB::raw('SUM(amount) as revenue'),
                    DB::raw('COUNT(*) as transactions'),
                    DB::raw('AVG(amount) as average_ticket')
                )
                ->groupBy(DB::raw($dateFormatExpr))
                ->orderBy('period')
                ->get();

            $totals = [
                'total_revenue' => $data->sum('revenue'),
                'total_transactions' => $data->sum('transactions'),
                'average_ticket' => $data->avg('average_ticket'),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'series' => $data,
                    'totals' => $totals,
                    'period' => $period,
                    'group_by' => $groupBy,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ReportController::revenue error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Get products report
     */
    public function products(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;
            $period = $request->query('period', '30d');
            $dateFrom = $this->getDateFromPeriod($period);

            $products = Product::where('products.account_id', $accountId)
                ->leftJoin('charges', function ($join) use ($dateFrom) {
                    $join->on('products.id', '=', 'charges.product_id')
                        ->where('charges.status', '=', 'approved')
                        ->where('charges.paid_at', '>=', $dateFrom);
                })
                ->select(
                    'products.id',
                    'products.name',
                    'products.price',
                    'products.is_active',
                    DB::raw('COUNT(charges.id) as sales'),
                    DB::raw('COALESCE(SUM(charges.amount), 0) as revenue')
                )
                ->groupBy('products.id', 'products.name', 'products.price', 'products.is_active')
                ->orderBy('revenue', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $products->items(),
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'last_page' => $products->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ReportController::products error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Get affiliates report
     */
    public function affiliates(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;
            $period = $request->query('period', '30d');
            $dateFrom = $this->getDateFromPeriod($period);

            $affiliates = Affiliate::where('affiliates.account_id', $accountId)
                ->leftJoin('charges', function ($join) use ($dateFrom) {
                    $join->on('affiliates.id', '=', 'charges.affiliate_id')
                        ->where('charges.status', '=', 'approved')
                        ->where('charges.paid_at', '>=', $dateFrom);
                })
                ->select(
                    'affiliates.id',
                    'affiliates.name',
                    'affiliates.email',
                    'affiliates.code',
                    'affiliates.commission_type',
                    'affiliates.commission_value',
                    'affiliates.status',
                    DB::raw('COUNT(charges.id) as sales'),
                    DB::raw('COALESCE(SUM(charges.amount), 0) as revenue')
                )
                ->groupBy(
                    'affiliates.id', 'affiliates.name', 'affiliates.email', 
                    'affiliates.code', 'affiliates.commission_type', 
                    'affiliates.commission_value', 'affiliates.status'
                )
                ->orderBy('revenue', 'desc')
                ->paginate(20);

            // Calculate commissions
            $data = $affiliates->through(function ($affiliate) {
                $commission = 0;
                if ($affiliate->commission_type === 'percentage') {
                    $commission = ($affiliate->revenue * $affiliate->commission_value) / 100;
                } else {
                    $commission = $affiliate->sales * $affiliate->commission_value;
                }
                $affiliate->commission_earned = (int) $commission;
                return $affiliate;
            });

            return response()->json([
                'success' => true,
                'data' => $data->items(),
                'meta' => [
                    'current_page' => $affiliates->currentPage(),
                    'per_page' => $affiliates->perPage(),
                    'total' => $affiliates->total(),
                    'last_page' => $affiliates->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ReportController::affiliates error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Export report data
     */
    public function export(Request $request, string $type): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;
            $format = $request->query('format', 'csv');
            $period = $request->query('period', '30d');
            $dateFrom = $this->getDateFromPeriod($period);

            $data = match($type) {
                'transactions' => Charge::where('account_id', $accountId)
                    ->where('created_at', '>=', $dateFrom)
                    ->get(),
                'products' => Product::where('account_id', $accountId)->get(),
                'affiliates' => Affiliate::where('account_id', $accountId)->get(),
                default => collect([]),
            };

            // In a real implementation, this would generate actual CSV/Excel files
            return response()->json([
                'success' => true,
                'data' => [
                    'type' => $type,
                    'format' => $format,
                    'count' => $data->count(),
                    'download_url' => url("/api/v1/reports/download/{$type}"),
                    'expires_at' => now()->addHours(1)->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ReportController::export error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Generate a report asynchronously (dispatches job)
     */
    public function generate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'type' => 'required|string|in:charges,transactions,splits,refunds,affiliates',
                'format' => 'sometimes|string|in:csv,json',
                'filters' => 'sometimes|array',
                'filters.start_date' => 'sometimes|date',
                'filters.end_date' => 'sometimes|date',
                'filters.status' => 'sometimes|string',
            ]);

            $accountId = $request->user()->account_id;
            $format = $validated['format'] ?? 'csv';
            $filters = $validated['filters'] ?? [
                'start_date' => now()->subDays(30)->toDateString(),
                'end_date' => now()->toDateString(),
            ];

            \App\Infrastructure\Jobs\GenerateReportJob::dispatch(
                $accountId,
                $validated['type'],
                $filters,
                $format
            );

            return response()->json([
                'success' => true,
                'message' => 'Relatório sendo gerado. Você será notificado quando estiver pronto.',
                'data' => [
                    'type' => $validated['type'],
                    'format' => $format,
                    'status' => 'processing',
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ReportController::generate error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Download a generated report file
     */
    public function download(Request $request, string $filename): JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        try {
            $accountId = $request->user()->account_id;
            $filename = basename($filename);

            if (!preg_match('/^[\w\-\.]+$/', $filename)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nome de arquivo inválido',
                ], 400);
            }

            $path = "reports/{$accountId}/{$filename}";

            if (!\Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Relatório não encontrado ou expirado',
                ], 404);
            }

            return response()->download(
                storage_path("app/{$path}"),
                $filename,
                ['Content-Type' => str_ends_with($filename, '.json') ? 'application/json' : 'text/csv']
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ReportController::download error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Calculate date from period string
     */
    private function getDateFromPeriod(string $period): \DateTime
    {
        return match($period) {
            '24h' => now()->subHours(24),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
            default => now()->subDays(30),
        };
    }

    /**
     * Get previous period start date for comparison
     */
    private function getPreviousPeriodDate(string $period, \DateTime $currentStart): \DateTime
    {
        $diff = now()->diff($currentStart);
        $days = $diff->days;
        
        return (clone $currentStart)->modify("-{$days} days");
    }
}
