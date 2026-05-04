<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ListSplitsCommand extends Command
{
    protected $signature = 'split:list
        {--account= : ID da conta (recipient)}
        {--charge= : ID da cobrança}
        {--status= : Filtrar por status (pending, processing, transferred, failed)}
        {--full-id : Mostrar IDs completos}
        {--limit=50 : Limite de resultados}';

    protected $description = 'Lista splits por conta, cobrança ou status';

    public function handle(): int
    {
        $accountId = $this->option('account');
        $chargeId = $this->option('charge');
        $status = $this->option('status');
        $limit = (int) $this->option('limit');

        $query = DB::table('splits');

        if ($accountId) {
            $query->where('recipient_id', $accountId);
            $this->info("🔍 Splits para account: {$accountId}");
        }

        if ($chargeId) {
            $transaction = DB::table('transactions')->where('charge_id', $chargeId)->first();
            if (!$transaction) {
                $this->error("❌ Charge não encontrada: {$chargeId}");
                return Command::FAILURE;
            }
            $query->where('transaction_id', $transaction->id);
            $this->info("🔍 Splits para charge: {$chargeId}");
        }

        if ($status) {
            $query->where('status', $status);
            $this->info("🔍 Filtro status: {$status}");
        }

        $splits = $query->orderByDesc('created_at')->limit($limit)->get();

        if ($splits->isEmpty()) {
            $this->warn("⚠️ Nenhum split encontrado.");
            
            // Show general stats
            $this->newLine();
            $this->info("📊 Estatísticas gerais de splits:");
            $stats = DB::table('splits')
                ->selectRaw('status, COUNT(*) as total, SUM(net_amount) as sum_amount')
                ->groupBy('status')
                ->get();

            foreach ($stats as $stat) {
                $amount = number_format($stat->sum_amount / 100, 2, ',', '.');
                $this->line("   {$stat->status}: {$stat->total} splits (R$ {$amount})");
            }

            return Command::SUCCESS;
        }

        $this->info("📋 Total: {$splits->count()} splits");
        $this->newLine();

        $fullId = $this->option('full-id');

        $rows = $splits->map(function ($split) use ($fullId) {
            return [
                $fullId ? $split->id : substr($split->id, 0, 8) . '...',
                $split->recipient_type,
                $fullId ? $split->recipient_id : substr($split->recipient_id, 0, 8) . '...',
                'R$ ' . number_format($split->net_amount / 100, 2, ',', '.'),
                $this->formatStatus($split->status),
                $split->created_at,
            ];
        })->toArray();

        $this->table(
            ['ID', 'Tipo', 'Recipient', 'Valor Líquido', 'Status', 'Criado em'],
            $rows
        );

        // Summary
        $totalAmount = $splits->sum('net_amount');
        $this->newLine();
        $this->info("💰 Total: R$ " . number_format($totalAmount / 100, 2, ',', '.'));

        // Status breakdown
        $byStatus = $splits->groupBy('status');
        foreach ($byStatus as $st => $group) {
            $stAmount = $group->sum('net_amount');
            $this->line("   {$this->formatStatus($st)}: {$group->count()} splits (R$ " . number_format($stAmount / 100, 2, ',', '.') . ")");
        }

        // Show unique recipients
        $this->newLine();
        $this->info("👥 Destinatários únicos:");
        $recipients = $splits->groupBy('recipient_id');
        foreach ($recipients as $recipientId => $recipientSplits) {
            $total = $recipientSplits->sum('net_amount');
            $type = $recipientSplits->first()->recipient_type;
            $this->line("   • {$recipientId} ({$type}) - {$recipientSplits->count()} splits - R$ " . number_format($total / 100, 2, ',', '.'));
        }

        return Command::SUCCESS;
    }

    private function formatStatus(string $status): string
    {
        return match ($status) {
            'pending' => '⏳ pending',
            'processing' => '🔄 processing',
            'transferred' => '✅ transferred',
            'failed' => '❌ failed',
            default => $status,
        };
    }
}
