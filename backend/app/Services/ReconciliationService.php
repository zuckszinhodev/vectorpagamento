<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Charge;
use App\Models\Reconciliation;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReconciliationService
{
    /**
     * Run reconciliation for a given period and optional account.
     */
    public function reconcile(
        string $type,
        \DateTimeInterface $periodStart,
        \DateTimeInterface $periodEnd,
        ?string $accountId = null,
        ?string $userId = null,
    ): Reconciliation {
        $reconciliation = Reconciliation::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $accountId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'type' => $type,
            'status' => Reconciliation::STATUS_PROCESSING,
            'reconciled_by' => $userId,
        ]);

        try {
            $results = $this->calculate($periodStart, $periodEnd, $accountId);
            $discrepancies = $this->findDiscrepancies($periodStart, $periodEnd, $accountId);

            $reconciliation->update([
                'status' => Reconciliation::STATUS_COMPLETED,
                'total_charges' => $results['total_charges'],
                'total_amount' => $results['total_amount'],
                'total_fees' => $results['total_fees'],
                'total_net' => $results['total_net'],
                'total_refunds' => $results['total_refunds'],
                'total_chargebacks' => $results['total_chargebacks'],
                'discrepancies' => $discrepancies,
                'summary' => $results['summary'],
                'reconciled_at' => now(),
            ]);

            Log::info('Reconciliation completed', [
                'reconciliation_id' => $reconciliation->id,
                'type' => $type,
                'period' => [$periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')],
                'discrepancies_count' => count($discrepancies),
            ]);
        } catch (\Exception $e) {
            $reconciliation->update(['status' => Reconciliation::STATUS_FAILED]);
            Log::error('Reconciliation failed', [
                'reconciliation_id' => $reconciliation->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $reconciliation;
    }

    /**
     * Calculate totals for a period.
     */
    private function calculate(
        \DateTimeInterface $periodStart,
        \DateTimeInterface $periodEnd,
        ?string $accountId,
    ): array {
        $chargeQuery = Charge::whereBetween('created_at', [$periodStart, $periodEnd]);
        if ($accountId) {
            $chargeQuery->where('account_id', $accountId);
        }

        $charges = $chargeQuery->get();

        $paid = $charges->where('status', 'paid');
        $refunded = $charges->where('status', 'refunded');
        $chargedBack = $charges->where('status', 'chargeback');

        $totalAmount = $paid->sum('amount') + $refunded->sum('amount') + $chargedBack->sum('amount');
        $totalFees = $paid->sum('fee_amount');
        $totalRefunds = $refunded->sum('amount');
        $totalChargebacks = $chargedBack->sum('amount');

        // By payment method
        $byMethod = $charges->whereIn('status', ['paid', 'refunded', 'chargeback'])
            ->groupBy('payment_method')
            ->map(fn($group) => [
                'count' => $group->count(),
                'amount' => $group->sum('amount'),
            ])->toArray();

        // By status
        $byStatus = $charges->groupBy('status')
            ->map(fn($group) => $group->count())
            ->toArray();

        return [
            'total_charges' => $charges->count(),
            'total_amount' => $totalAmount,
            'total_fees' => $totalFees,
            'total_net' => $totalAmount - $totalFees - $totalRefunds - $totalChargebacks,
            'total_refunds' => $totalRefunds,
            'total_chargebacks' => $totalChargebacks,
            'summary' => [
                'by_payment_method' => $byMethod,
                'by_status' => $byStatus,
                'paid_count' => $paid->count(),
                'refund_count' => $refunded->count(),
                'chargeback_count' => $chargedBack->count(),
                'conversion_rate' => $charges->count() > 0
                    ? round(($paid->count() / $charges->count()) * 100, 2)
                    : 0,
            ],
        ];
    }

    /**
     * Find discrepancies: charges paid but no transaction,
     * wallet balance mismatches, etc.
     */
    private function findDiscrepancies(
        \DateTimeInterface $periodStart,
        \DateTimeInterface $periodEnd,
        ?string $accountId,
    ): array {
        $discrepancies = [];

        // 1. Charges marked as paid but no successful transaction
        $query = Charge::where('status', 'paid')
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->whereDoesntHave('transactions', function ($q) {
                $q->where('status', 'approved');
            });

        if ($accountId) {
            $query->where('account_id', $accountId);
        }

        $orphanCharges = $query->pluck('id')->toArray();
        if (!empty($orphanCharges)) {
            $discrepancies[] = [
                'type' => 'paid_without_transaction',
                'description' => 'Cobranças pagas sem transação aprovada correspondente',
                'count' => count($orphanCharges),
                'charge_ids' => array_slice($orphanCharges, 0, 50),
            ];
        }

        // 2. Transactions approved but charge not marked as paid
        $txQuery = DB::table('transactions')
            ->join('charges', 'transactions.charge_id', '=', 'charges.id')
            ->where('transactions.status', 'approved')
            ->where('charges.status', '!=', 'paid')
            ->whereBetween('transactions.created_at', [$periodStart, $periodEnd]);

        if ($accountId) {
            $txQuery->where('charges.account_id', $accountId);
        }

        $mismatchedTx = $txQuery->pluck('transactions.id')->toArray();
        if (!empty($mismatchedTx)) {
            $discrepancies[] = [
                'type' => 'approved_tx_unpaid_charge',
                'description' => 'Transações aprovadas com cobrança não marcada como paga',
                'count' => count($mismatchedTx),
                'transaction_ids' => array_slice($mismatchedTx, 0, 50),
            ];
        }

        return $discrepancies;
    }

    /**
     * Daily automated reconciliation (all accounts).
     */
    public function runDailyReconciliation(): void
    {
        $yesterday = now()->subDay()->startOfDay();
        $endOfYesterday = now()->subDay()->endOfDay();

        // Global reconciliation
        $this->reconcile(
            Reconciliation::TYPE_DAILY,
            $yesterday,
            $endOfYesterday,
        );

        Log::info('Daily reconciliation completed', [
            'period' => $yesterday->format('Y-m-d'),
        ]);
    }
}
