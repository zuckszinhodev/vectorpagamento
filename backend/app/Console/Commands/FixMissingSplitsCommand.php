<?php

namespace App\Console\Commands;

use App\Models\Charge;
use App\Models\Split;
use App\Models\Transaction;
use App\Services\SplitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FixMissingSplitsCommand extends Command
{
    protected $signature = 'split:fix-missing
        {--email= : Filtrar por email do usuário que criou as charges}
        {--account= : Filtrar por account_id}
        {--dry-run : Mostrar o que seria feito sem executar}';

    protected $description = 'Encontra charges aprovadas que tinham split_rules no metadata mas nunca geraram splits, e os processa retroativamente.';

    public function __construct(
        private SplitService $splitService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $email = $this->option('email');
        $accountId = $this->option('account');

        $this->info($dryRun ? '🔍 [DRY-RUN] Buscando charges com split_rules não processados...' : '🔧 Corrigindo splits não processados...');
        $this->newLine();

        // Find approved charges that have split_rules in metadata
        $query = Charge::where('status', 'approved');

        if ($accountId) {
            $query->where('account_id', $accountId);
        }

        if ($email) {
            // Find account by user email
            $user = \App\Models\User::where('email', $email)->first();
            if (!$user) {
                $this->error("❌ Usuário não encontrado: {$email}");
                return Command::FAILURE;
            }
            $query->where('account_id', $user->account_id);
            $this->info("📧 Filtrando por usuário: {$email} (account: {$user->account_id})");
        }

        $charges = $query->get();
        $fixed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($charges as $charge) {
            $metadata = $charge->metadata ?? [];
            $splitRules = $metadata['split_rules'] ?? null;

            if (empty($splitRules) || !is_array($splitRules)) {
                continue;
            }

            // Check if splits already exist for this charge
            $transaction = Transaction::where('charge_id', $charge->id)->first();
            if (!$transaction) {
                $this->warn("⚠️  Charge {$charge->id} — sem transação associada, pulando");
                $skipped++;
                continue;
            }

            $existingSplits = Split::where('transaction_id', $transaction->id)->exists();
            if ($existingSplits) {
                $this->line("   ✅ Charge {$charge->id} — splits já existem, pulando");
                $skipped++;
                continue;
            }

            // Show what we found
            $totalRecipients = count($splitRules);
            $totalSplitAmount = 0;
            foreach ($splitRules as $rule) {
                if (($rule['type'] ?? 'fixed') === 'percentage') {
                    $totalSplitAmount += (int) round($charge->amount * (($rule['percentage'] ?? 0) / 100));
                } else {
                    $totalSplitAmount += (int) ($rule['amount'] ?? 0);
                }
            }

            $this->info("📋 Charge {$charge->id}");
            $this->line("   Valor: R$ " . number_format($charge->amount / 100, 2, ',', '.'));
            $this->line("   Pago em: {$charge->paid_at}");
            $this->line("   Destinatários: {$totalRecipients}");
            $this->line("   Total split: R$ " . number_format($totalSplitAmount / 100, 2, ',', '.'));

            foreach ($splitRules as $i => $rule) {
                $rid = $rule['recipient_id'] ?? '?';
                $type = $rule['type'] ?? 'fixed';
                $amt = $type === 'percentage'
                    ? ($rule['percentage'] ?? 0) . '%'
                    : 'R$ ' . number_format(($rule['amount'] ?? 0) / 100, 2, ',', '.');
                $this->line("   → Destinatário #{$i}: {$rid} ({$type}: {$amt})");
            }

            if ($dryRun) {
                $this->info("   🔄 [DRY-RUN] Seria processado");
                $fixed++;
                continue;
            }

            // Process the splits
            try {
                $result = $this->splitService->createSplitsForCharge($charge);
                $count = count($result);
                $this->info("   ✅ {$count} splits criados e processados!");
                $fixed++;

                foreach ($result as $split) {
                    $splitData = is_array($split) ? $split : $split->toArray();
                    $this->line("      Split {$splitData['id']}: R$ " . number_format(($splitData['net_amount'] ?? 0) / 100, 2, ',', '.') . " → {$splitData['recipient_id']} ({$splitData['status']})");
                }
            } catch (\Exception $e) {
                $this->error("   ❌ Erro: {$e->getMessage()}");
                Log::error('FixMissingSplits error', [
                    'charge_id' => $charge->id,
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }

            $this->newLine();
        }

        $this->newLine();
        $this->info("═══════════════════════════════════════");
        $this->info("📊 RESUMO");
        $this->table(['Métrica', 'Valor'], [
            ['Charges encontradas', $charges->count()],
            ['Splits criados/corrigidos', $fixed],
            ['Pulados (já existentes)', $skipped],
            ['Erros', $errors],
        ]);

        if ($dryRun && $fixed > 0) {
            $this->warn("⚠️  Execute sem --dry-run para aplicar as correções.");
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
