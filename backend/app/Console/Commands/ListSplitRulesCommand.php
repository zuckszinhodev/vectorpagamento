<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ListSplitRulesCommand extends Command
{
    protected $signature = 'split:rules
        {--account= : ID da conta do produtor}
        {--all : Mostrar todas as regras}';

    protected $description = 'Lista regras de split configuradas';

    public function handle(): int
    {
        $accountId = $this->option('account');
        $showAll = $this->option('all');

        $query = DB::table('split_rules');

        if ($accountId) {
            $query->where('account_id', $accountId);
            $this->info("🔍 Regras de split para account: {$accountId}");
        } elseif (!$showAll) {
            $this->info("🔍 Mostrando últimas 20 regras (use --all para ver todas)");
            $query->limit(20);
        }

        $rules = $query->orderByDesc('created_at')->get();

        if ($rules->isEmpty()) {
            $this->warn("⚠️ Nenhuma regra de split encontrada.");
            return Command::SUCCESS;
        }

        $this->info("📋 Total: {$rules->count()} regras");
        $this->newLine();

        foreach ($rules as $rule) {
            $this->info("═══════════════════════════════════════");
            $this->info("📌 Regra: {$rule->name}");
            $this->table(
                ['Campo', 'Valor'],
                [
                    ['ID', $rule->id],
                    ['Account ID', $rule->account_id],
                    ['Produto', $rule->product_id ?? 'Qualquer'],
                    ['Checkout', $rule->checkout_id ?? 'Qualquer'],
                    ['Padrão?', $rule->is_default ? '✅ Sim' : 'Não'],
                    ['Ativa?', $rule->is_active ? '✅ Sim' : '❌ Não'],
                    ['Prioridade', $rule->priority ?? 0],
                    ['Auto-processar?', $rule->auto_process ? '✅ Sim' : 'Não'],
                    ['Delay (horas)', $rule->transfer_delay_hours ?? 0],
                    ['Valor mínimo', 'R$ ' . number_format(($rule->minimum_amount ?? 0) / 100, 2, ',', '.')],
                    ['Criada em', $rule->created_at],
                ]
            );

            // Parse recipients
            $recipients = json_decode($rule->recipients ?? '[]', true);
            if (!empty($recipients)) {
                $this->info("👥 Destinatários ({$this->countRecipients($recipients)}):");
                foreach ($recipients as $r) {
                    $type = $r['type'] ?? 'percentage';
                    $value = $r['value'] ?? 0;
                    $recipientId = $r['recipient_id'] ?? 'N/A';
                    $recipientType = $r['recipient_type'] ?? 'account';
                    $desc = $r['description'] ?? '';

                    if ($type === 'percentage') {
                        $this->line("   • {$recipientId} ({$recipientType}) - {$value}% {$desc}");
                    } else {
                        $this->line("   • {$recipientId} ({$recipientType}) - R$ " . number_format($value / 100, 2, ',', '.') . " {$desc}");
                    }

                    // Show recipient name
                    $account = DB::table('accounts')->where('id', $recipientId)->first();
                    if ($account) {
                        $this->line("     └─ {$account->name} ({$account->email})");
                    }
                }
            } else {
                $this->warn("   ⚠️ Nenhum destinatário configurado!");
            }

            $this->newLine();
        }

        return Command::SUCCESS;
    }

    private function countRecipients($recipients): int
    {
        return is_array($recipients) ? count($recipients) : 0;
    }
}
