<?php

namespace App\Domain\Fraud\Services;

use App\Models\Charge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SellerFraudRiskService
{
    public function assess(?string $accountId): array
    {
        try {
            if (!$accountId) {
                return $this->summarize([]);
            }
            $since = now()->subDays(30);
            $rows = Charge::where('account_id', $accountId)->where('created_at', '>=', $since)
                ->select('status', 'payment_method')->selectRaw('COUNT(*) AS total')
                ->groupBy('status', 'payment_method')->get();
            $metrics = ['total' => 0, 'settled' => 0, 'chargebacks' => 0, 'refunds' => 0, 'card_attempts' => 0, 'card_declines' => 0];
            foreach ($rows as $row) {
                $n = (int) $row->total;
                $metrics['total'] += $n;
                if (in_array($row->status, ['approved', 'refunded', 'partially_refunded', 'chargeback'], true)) $metrics['settled'] += $n;
                if ($row->status === 'chargeback') $metrics['chargebacks'] += $n;
                if (in_array($row->status, ['refunded', 'partially_refunded'], true)) $metrics['refunds'] += $n;
                if (in_array($row->payment_method, ['credit_card', 'debit_card', 'card'], true)
                    && in_array($row->status, ['approved', 'refunded', 'partially_refunded', 'chargeback', 'declined'], true)) {
                    $metrics['card_attempts'] += $n;
                    if ($row->status === 'declined') $metrics['card_declines'] += $n;
                }
            }
            $metrics['flagged'] = Charge::where('account_id', $accountId)->where('created_at', '>=', $since)
                ->whereExists(function ($query) use ($since) {
                    $query->select(DB::raw(1))->from('risk_scores')->whereColumn('risk_scores.charge_id', 'charges.id')
                        ->where('risk_scores.created_at', '>=', $since)->whereIn('risk_scores.risk_level', ['high', 'critical']);
                })->count();
            return $this->summarize($metrics);
        } catch (\Throwable $e) {
            Log::warning('Seller fraud assessment unavailable', ['account_id' => $accountId, 'error' => $e->getMessage()]);
            return ['level' => 'unavailable', 'signals' => ['Não foi possível consultar os dados de fraude.'], 'window_days' => 30];
        }
    }

    public function summarize(array $metrics): array
    {
        $m = array_merge(['total' => 0, 'settled' => 0, 'chargebacks' => 0, 'refunds' => 0, 'card_attempts' => 0, 'card_declines' => 0, 'flagged' => 0], $metrics);
        $signals = [];
        $high = false;
        if ($m['chargebacks'] > 0) {
            $signals[] = $m['chargebacks'].' cobrança(s) com chargeback no período.';
            $high = $m['chargebacks'] >= 3 && $m['chargebacks'] / max(1, $m['settled']) >= 0.02;
        }
        if ($m['flagged'] > 0) {
            $signals[] = $m['flagged'].' cobrança(s) com análise antifraude de risco alto ou crítico.';
            $high = true;
        }
        if ($m['settled'] >= 20 && $m['refunds'] >= 5 && $m['refunds'] / $m['settled'] >= 0.2) {
            $signals[] = 'Reembolsos em pelo menos 20% das cobranças pagas (mínimo de 5).';
        }
        if ($m['card_attempts'] >= 20 && $m['card_declines'] / $m['card_attempts'] >= 0.5) {
            $signals[] = 'Pelo menos 50% das tentativas de cartão foram recusadas (mínimo de 20 tentativas).';
        }
        $level = $high ? 'high' : ($signals ? 'attention' : ($m['settled'] >= 20 ? 'low' : 'insufficient'));
        return ['level' => $level, 'signals' => $signals, 'window_days' => 30, 'metrics' => $m];
    }
}
