<?php

namespace App\Services;

use App\Models\AcquirerConfig;
use App\Models\Charge;
use App\Models\SystemSetting;
use App\Models\Withdrawal;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Único lugar que decide quanto o seller paga por venda.
 * A taxa do sistema é o preço; a taxa da adquirente é custo e só entra no cálculo do lucro.
 */
class SystemFeeService
{
    public const METHODS = ['pix', 'credit_card', 'boleto'];

    /**
     * @return array{percentage: float, fixed: float}|null Fixo em reais; null quando o método nunca foi configurado
     */
    public function rate(?string $paymentMethod): ?array
    {
        $method = strtolower(trim((string) $paymentMethod));
        $method = $method === 'card' ? 'credit_card' : $method;

        if (!in_array($method, self::METHODS, true)) {
            return null;
        }

        $percentage = SystemSetting::get("system_fee_{$method}_percentage");
        $fixed = SystemSetting::get("system_fee_{$method}_fixed");

        if (is_null($percentage) && is_null($fixed)) {
            return null;
        }

        return ['percentage' => (float) $percentage, 'fixed' => (float) $fixed];
    }

    /**
     * Taxa em centavos. Sem taxa do sistema configurada, mantém a taxa antiga do gateway na adquirente.
     */
    public function calculate(int $amountCents, ?string $paymentMethod, ?AcquirerConfig $acquirer = null, int $installments = 1): int
    {
        $rate = $this->rate($paymentMethod);

        if ($rate) {
            $fee = (int) round($amountCents * ($rate['percentage'] / 100)) + (int) round($rate['fixed'] * 100);
        } elseif ($acquirer) {
            $fee = $acquirer->calculateGatewayFee($amountCents, $paymentMethod, $installments);
        } else {
            $fee = (int) round($amountCents * 0.05) + 200;
        }

        return min($fee, $amountCents);
    }

    /**
     * Cobrança já creditada mantém a taxa gravada: mudar o preço hoje não reescreve vendas antigas.
     */
    public function forCharge(Charge $charge, ?AcquirerConfig $acquirer = null): int
    {
        if ((int) ($charge->net_amount ?? 0) > 0) {
            return (int) $charge->fee_amount;
        }

        return $this->calculate((int) $charge->amount, $charge->payment_method, $acquirer, (int) ($charge->installments ?? 1));
    }

    /**
     * Receita, custo e lucro. Vendas sem custo gravado usam a taxa atual da adquirente.
     */
    public function profitReport(?Carbon $from = null, ?Collection $excludeAccountIds = null): array
    {
        $configs = AcquirerConfig::all()->keyBy('acquirer_key');
        $exclude = $excludeAccountIds ?? collect();
        $byAcquirer = [];

        $charges = Charge::where('status', 'approved')
            ->where('payment_method', '!=', 'split')
            ->when($from, fn ($q) => $q->where('paid_at', '>=', $from))
            ->when($exclude->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $exclude))
            ->selectRaw('acquirer, COUNT(*) as tx_count, COALESCE(SUM(amount), 0) as volume, COALESCE(SUM(fee_amount), 0) as fees')
            ->selectRaw('COALESCE(SUM(acquirer_fee_amount), 0) as stored_cost')
            ->selectRaw('COALESCE(SUM(CASE WHEN acquirer_fee_amount IS NULL THEN amount ELSE 0 END), 0) as estimate_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN acquirer_fee_amount IS NULL THEN 1 ELSE 0 END), 0) as estimate_count')
            ->groupBy('acquirer')
            ->get();

        foreach ($charges as $row) {
            $key = AcquirerConfig::normalizeAcquirerKey((string) $row->acquirer);
            $config = $configs->get($key);
            $estimated = $config
                ? (int) round((int) $row->estimate_amount * ((float) $config->acquirer_fee_percentage / 100))
                    + (int) round((float) $config->acquirer_fee_fixed * 100) * (int) $row->estimate_count
                : 0;

            $this->addToReport($byAcquirer, $key, $config, [
                'transactions' => (int) $row->tx_count,
                'volume' => (int) $row->volume,
                'fee_revenue' => (int) $row->fees,
                'acquirer_cost' => (int) $row->stored_cost + $estimated,
            ]);
        }

        $withdrawals = Withdrawal::where('status', 'completed')
            ->when($from, fn ($q) => $q->where('processed_at', '>=', $from))
            ->when($exclude->isNotEmpty(), fn ($q) => $q->whereNotIn('account_id', $exclude))
            ->selectRaw('acquirer, COUNT(*) as tx_count, COALESCE(SUM(fee), 0) as fees, COALESCE(SUM(net_amount), 0) as paid_out')
            ->groupBy('acquirer')
            ->get();

        foreach ($withdrawals as $row) {
            $key = AcquirerConfig::normalizeAcquirerKey((string) $row->acquirer);
            $config = $configs->get($key);
            $cost = $config
                ? (int) round((int) $row->paid_out * ((float) $config->acquirer_fee_pix_out_percentage / 100))
                    + (int) round((float) $config->acquirer_fee_pix_out_fixed * 100) * (int) $row->tx_count
                : 0;

            $this->addToReport($byAcquirer, $key, $config, [
                'withdrawals' => (int) $row->tx_count,
                'withdrawal_fee_revenue' => (int) $row->fees,
                'withdrawal_cost' => $cost,
            ]);
        }

        $rows = collect($byAcquirer)->map(function (array $row) {
            $row['profit'] = $row['fee_revenue'] + $row['withdrawal_fee_revenue'] - $row['acquirer_cost'] - $row['withdrawal_cost'];

            return $row;
        })->sortByDesc('profit')->values();

        $totals = [];
        foreach (['transactions', 'volume', 'fee_revenue', 'acquirer_cost', 'withdrawals', 'withdrawal_fee_revenue', 'withdrawal_cost', 'profit'] as $field) {
            $totals[$field] = (int) $rows->sum($field);
        }

        return ['totals' => $totals, 'acquirers' => $rows->all()];
    }

    private function addToReport(array &$report, string $key, ?AcquirerConfig $config, array $values): void
    {
        $report[$key] ??= [
            'acquirer' => $key,
            'name' => $config?->display_name ?: ($key !== '' ? $key : 'Sem adquirente'),
            'transactions' => 0,
            'volume' => 0,
            'fee_revenue' => 0,
            'acquirer_cost' => 0,
            'withdrawals' => 0,
            'withdrawal_fee_revenue' => 0,
            'withdrawal_cost' => 0,
        ];

        foreach ($values as $field => $value) {
            $report[$key][$field] += $value;
        }
    }
}
