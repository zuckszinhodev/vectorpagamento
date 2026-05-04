<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\Transaction;
use App\Models\Product;
use App\Models\Checkout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * Get analytics data (cached for 90 seconds)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;
            $period = $request->input('period', '30d');
            $compareWith = $request->input('compare', 'previous');

            $cacheKey = "analytics:{$accountId}:{$period}:{$compareWith}";
            $data = Cache::remember($cacheKey, 90, function () use ($accountId, $period, $compareWith) {
                return $this->buildAnalyticsData($accountId, $period, $compareWith);
            });

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AnalyticsController::index error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Build analytics data (extracted for caching)
     */
    private function buildAnalyticsData(string $accountId, string $period, string $compareWith): array
    {

        // Calculate date ranges
        $endDate = now();
        $startDate = match ($period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
            default => now()->subDays(30),
        };

        $previousStartDate = $startDate->copy()->sub($endDate->diff($startDate));
        $previousEndDate = $startDate->copy();

        // Current period data
        $currentData = $this->getPeriodData($accountId, $startDate, $endDate);
        $previousData = $compareWith === 'previous' 
            ? $this->getPeriodData($accountId, $previousStartDate, $previousEndDate)
            : null;

        // Daily breakdown
        $dailyData = $this->getDailyBreakdown($accountId, $startDate, $endDate);

        // Top products
        $topProducts = Product::where('account_id', $accountId)
            ->orderBy('sales_count', 'desc')
            ->limit(5)
            ->get(['id', 'name', 'price', 'sales_count'])
            ->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => $p->price,
                'sales_count' => $p->sales_count,
                'total_revenue' => (float) ($p->price * $p->sales_count),
            ]);

        // Conversion funnel
        $funnel = $this->getConversionFunnel($accountId, $startDate, $endDate);

        return [
                'summary' => [
                    'current' => $currentData,
                    'previous' => $previousData,
                    'changes' => $previousData ? [
                        'revenue' => $this->calculateChange($currentData['revenue'], $previousData['revenue']),
                        'sales' => $this->calculateChange($currentData['sales'], $previousData['sales']),
                        'average_ticket' => $this->calculateChange($currentData['average_ticket'], $previousData['average_ticket']),
                        'conversion_rate' => $this->calculateChange($currentData['conversion_rate'], $previousData['conversion_rate']),
                    ] : null,
                ],
                'daily_breakdown' => $dailyData,
                'top_products' => $topProducts,
                'funnel' => $funnel,
                'period' => [
                    'start' => $startDate->toISOString(),
                    'end' => $endDate->toISOString(),
                ],
            ];
    }

    /**
     * Get period aggregated data
     */
    private function getPeriodData(string $accountId, $startDate, $endDate): array
    {
        $charges = Charge::where('account_id', $accountId)
            ->whereBetween('created_at', [$startDate, $endDate]);

        $paidCharges = (clone $charges)->where('status', 'approved');

        $revenue = (float) $paidCharges->sum('amount');
        $sales = $paidCharges->count();
        $averageTicket = $sales > 0 ? $revenue / $sales : 0;

        $totalAttempts = (clone $charges)->count();
        $conversionRate = $totalAttempts > 0 ? ($sales / $totalAttempts) * 100 : 0;

        $refunds = (float) (clone $charges)->where('status', 'refunded')->sum('amount');

        return [
            'revenue' => round($revenue, 2),
            'sales' => $sales,
            'average_ticket' => round($averageTicket, 2),
            'conversion_rate' => round($conversionRate, 2),
            'refunds' => round($refunds, 2),
            'total_attempts' => $totalAttempts,
        ];
    }

    /**
     * Get daily breakdown
     */
    private function getDailyBreakdown(string $accountId, $startDate, $endDate): array
    {
        $data = Charge::where('account_id', $accountId)
            ->where('status', 'approved')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw("DATE(created_at) as date, SUM(amount) as revenue, COUNT(*) as sales")
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $data->map(fn($item) => [
            'date' => $item->date,
            'revenue' => (float) $item->revenue,
            'sales' => (int) $item->sales,
        ])->toArray();
    }

    /**
     * Get conversion funnel
     */
    private function getConversionFunnel(string $accountId, $startDate, $endDate): array
    {
        // Get checkout views (would need tracking, using estimates for now)
        $checkoutsCount = Checkout::where('account_id', $accountId)->count();
        $totalAttempts = Charge::where('account_id', $accountId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $pendingCount = Charge::where('account_id', $accountId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('status', 'pending')
            ->count();

        $paidCount = Charge::where('account_id', $accountId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('status', 'approved')
            ->count();

        // Estimated views (10x checkouts created)
        $estimatedViews = $checkoutsCount * 10;

        return [
            ['stage' => 'Visualizações', 'count' => $estimatedViews, 'rate' => 100],
            ['stage' => 'Iniciaram Checkout', 'count' => $totalAttempts, 'rate' => $estimatedViews > 0 ? round(($totalAttempts / $estimatedViews) * 100, 1) : 0],
            ['stage' => 'Pagamento Pendente', 'count' => $pendingCount, 'rate' => $totalAttempts > 0 ? round(($pendingCount / $totalAttempts) * 100, 1) : 0],
            ['stage' => 'Pagamento Confirmado', 'count' => $paidCount, 'rate' => $totalAttempts > 0 ? round(($paidCount / $totalAttempts) * 100, 1) : 0],
        ];
    }

    /**
     * Calculate percentage change
     */
    private function calculateChange(float $current, float $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        return round((($current - $previous) / $previous) * 100, 2);
    }
}
