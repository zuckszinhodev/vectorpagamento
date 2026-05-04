<?php

namespace App\Console\Commands;

use App\Models\Charge;
use App\Models\SplitRule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixDuplicateSplitRulesCommand extends Command
{
    protected $signature = 'split:fix-duplicates
        {--email= : Filtrar por email do usuário}
        {--account= : Filtrar por account_id}
        {--dry-run : Mostrar o que seria feito sem executar}';

    protected $description = 'Remove regras de split duplicadas (mesmos destinatários) e corrige charges de split com taxa incorreta.';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $email = $this->option('email');
        $accountId = $this->option('account');

        if ($email) {
            $user = \App\Models\User::where('email', $email)->first();
            if (!$user) {
                $this->error("❌ Usuário não encontrado: {$email}");
                return Command::FAILURE;
            }
            $accountId = $user->account_id;
            $this->info("📧 Conta: {$email} ({$accountId})");
        }

        // 1. Fix duplicate split rules
        $this->info('');
        $this->info('═══ REGRAS DE SPLIT DUPLICADAS ═══');

        $query = SplitRule::query();
        if ($accountId) {
            $query->where('account_id', $accountId);
        }

        $allRules = $query->orderBy('created_at', 'asc')->get();
        $grouped = [];

        foreach ($allRules as $rule) {
            $recipientKey = collect($rule->recipients ?? [])
                ->pluck('recipient_id')
                ->sort()
                ->values()
                ->implode(',');

            $key = $rule->account_id . '|' . $recipientKey;

            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $rule;
        }

        $removedRules = 0;
        foreach ($grouped as $key => $rules) {
            if (count($rules) <= 1) {
                continue;
            }

            $keep = $rules[0]; // Keep the oldest
            $duplicates = array_slice($rules, 1);

            $this->warn("📋 Regra mantida: {$keep->name} ({$keep->id})");
            foreach ($duplicates as $dup) {
                $this->line("   🗑️  Duplicada: {$dup->name} ({$dup->id}) - criada em {$dup->created_at}");

                if (!$dryRun) {
                    $dup->delete();
                }
                $removedRules++;
            }
        }

        $this->info("   Regras removidas: {$removedRules}");

        // 2. Fix split charges with wrong fee_amount
        $this->info('');
        $this->info('═══ CHARGES DE SPLIT COM TAXA INCORRETA ═══');

        $splitChargesQuery = Charge::where('payment_method', 'split')
            ->where('fee_amount', '>', 0);

        if ($accountId) {
            $splitChargesQuery->where('account_id', $accountId);
        }

        $badCharges = $splitChargesQuery->get();
        $fixedCharges = 0;

        foreach ($badCharges as $charge) {
            $this->line("   🔧 Charge {$charge->id}: fee_amount={$charge->fee_amount} → 0");
            if (!$dryRun) {
                $charge->update(['fee_amount' => 0]);
            }
            $fixedCharges++;
        }

        $this->info("   Charges corrigidas: {$fixedCharges}");

        // Summary
        $this->info('');
        $this->info('═══ RESUMO ═══');
        $this->table(['Ação', 'Quantidade'], [
            ['Regras duplicadas removidas', $removedRules],
            ['Charges split com taxa corrigida', $fixedCharges],
        ]);

        if ($dryRun) {
            $this->warn('⚠️  Execute sem --dry-run para aplicar.');
        }

        return Command::SUCCESS;
    }
}
