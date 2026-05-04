<?php

namespace App\Console\Commands;

use App\Models\AccountWallet;
use App\Models\Affiliate;
use App\Models\Split;
use App\Models\Transaction;
use App\Services\SplitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixSplitCommand extends Command
{
    protected $signature = 'split:fix
        {split_id : ID do split a verificar/corrigir}
        {--force : Processar mesmo se já foi transferido}
        {--dry-run : Mostrar o que seria feito sem executar}';

    protected $description = 'Diagnostica e corrige problemas com um split específico. Mostra status, destinatário e wallet.';

    public function __construct(
        private SplitService $splitService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $splitId = $this->argument('split_id');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');

        $this->info("🔍 Buscando split: {$splitId}");
        $this->newLine();

        // 1. Find the split
        $split = Split::find($splitId);

        if (!$split) {
            $this->error("❌ Split não encontrado: {$splitId}");
            return Command::FAILURE;
        }

        // 2. Show split details
        $this->showSplitDetails($split);

        // 3. Show transaction details
        $this->showTransactionDetails($split);

        // 4. Show recipient details
        $this->showRecipientDetails($split);

        // 5. Diagnose issues
        $issues = $this->diagnoseIssues($split);

        if (empty($issues)) {
            $this->info("✅ Nenhum problema detectado com este split.");

            if ($split->status === 'transferred') {
                $this->info("   O split já foi processado e transferido.");
                return Command::SUCCESS;
            }
        } else {
            $this->newLine();
            $this->warn("⚠️  Problemas detectados:");
            foreach ($issues as $issue) {
                $this->line("   • {$issue}");
            }
        }

        // 6. Offer to fix if pending
        if ($split->status === 'pending' || $split->status === 'failed') {
            $this->newLine();

            if ($dryRun) {
                $this->info("🔄 [DRY-RUN] Comando processaria este split e creditaria a wallet do destinatário.");
                return Command::SUCCESS;
            }

            if (!$this->confirm("Deseja processar este split agora?")) {
                return Command::SUCCESS;
            }

            return $this->processSplit($split);
        }

        if ($split->status === 'transferred' && $force) {
            $this->newLine();
            $this->warn("⚠️  Split já foi transferido, mas --force está ativo.");

            if ($dryRun) {
                $this->info("🔄 [DRY-RUN] Comando creditaria novamente a wallet (CUIDADO: duplicação!).");
                return Command::SUCCESS;
            }

            if (!$this->confirm("ATENÇÃO: Isso pode causar DUPLICAÇÃO. Tem certeza?", false)) {
                return Command::SUCCESS;
            }

            return $this->forceCredit($split);
        }

        return Command::SUCCESS;
    }

    private function showSplitDetails(Split $split): void
    {
        $this->info("📋 DETALHES DO SPLIT");
        $this->table(
            ['Campo', 'Valor'],
            [
                ['ID', $split->id],
                ['Transaction ID', $split->transaction_id],
                ['Recipient ID', $split->recipient_id],
                ['Recipient Type', $split->recipient_type],
                ['Tipo', $split->type],
                ['Valor Bruto', 'R$ ' . number_format($split->amount / 100, 2, ',', '.')],
                ['Taxa', 'R$ ' . number_format($split->fee_amount / 100, 2, ',', '.')],
                ['Valor Líquido', 'R$ ' . number_format($split->net_amount / 100, 2, ',', '.')],
                ['Percentual', $split->percentage ? "{$split->percentage}%" : 'N/A'],
                ['Status', $this->formatStatus($split->status)],
                ['Transferido em', $split->transferred_at ?? 'Não transferido'],
                ['Criado em', $split->created_at],
                ['Atualizado em', $split->updated_at],
            ]
        );
    }

    private function showTransactionDetails(Split $split): void
    {
        $this->newLine();
        $this->info("💳 TRANSAÇÃO ASSOCIADA");

        $transaction = Transaction::find($split->transaction_id);

        if (!$transaction) {
            $this->error("   ❌ Transação não encontrada: {$split->transaction_id}");
            return;
        }

        $charge = $transaction->charge;

        $this->table(
            ['Campo', 'Valor'],
            [
                ['Transaction ID', $transaction->id],
                ['Charge ID', $transaction->charge_id ?? 'N/A'],
                ['Account ID', $transaction->account_id ?? ($charge->account_id ?? 'N/A')],
                ['Valor', 'R$ ' . number_format(($transaction->amount ?? $charge->amount ?? 0) / 100, 2, ',', '.')],
                ['Status Transação', $transaction->status ?? 'N/A'],
                ['Status Charge', $charge->status ?? 'N/A'],
                ['Acquirer', $charge->acquirer ?? 'N/A'],
            ]
        );
    }

    private function showRecipientDetails(Split $split): void
    {
        $this->newLine();
        $this->info("👤 DESTINATÁRIO");

        if ($split->recipient_type === 'account') {
            $wallet = AccountWallet::where('account_id', $split->recipient_id)->first();

            if (!$wallet) {
                $this->error("   ❌ Wallet não encontrada para account: {$split->recipient_id}");
                return;
            }

            $this->table(
                ['Campo', 'Valor'],
                [
                    ['Tipo', 'Account'],
                    ['Account ID', $split->recipient_id],
                    ['Wallet ID', $wallet->id],
                    ['Saldo Disponível', 'R$ ' . number_format($wallet->available_balance / 100, 2, ',', '.')],
                    ['Saldo Pendente', 'R$ ' . number_format(($wallet->pending_balance ?? 0) / 100, 2, ',', '.')],
                ]
            );
        } elseif ($split->recipient_type === 'affiliate') {
            $affiliate = Affiliate::find($split->recipient_id);

            if (!$affiliate) {
                $this->error("   ❌ Afiliado não encontrado: {$split->recipient_id}");
                return;
            }

            $this->table(
                ['Campo', 'Valor'],
                [
                    ['Tipo', 'Affiliate'],
                    ['Affiliate ID', $affiliate->id],
                    ['Nome', $affiliate->name ?? 'N/A'],
                    ['Email', $affiliate->email ?? 'N/A'],
                    ['Account ID do Afiliado', $affiliate->affiliate_account_id ?? '❌ NÃO CONFIGURADO'],
                    ['Status Afiliado', $affiliate->status ?? 'N/A'],
                ]
            );

            if ($affiliate->affiliate_account_id) {
                $wallet = AccountWallet::where('account_id', $affiliate->affiliate_account_id)->first();

                if ($wallet) {
                    $this->info("   💰 Wallet do Afiliado:");
                    $this->table(
                        ['Campo', 'Valor'],
                        [
                            ['Wallet ID', $wallet->id],
                            ['Account ID', $wallet->account_id],
                            ['Saldo Disponível', 'R$ ' . number_format($wallet->available_balance / 100, 2, ',', '.')],
                        ]
                    );
                } else {
                    $this->error("   ❌ Wallet não encontrada para affiliate_account_id: {$affiliate->affiliate_account_id}");
                }
            } else {
                $this->error("   ❌ Afiliado não possui affiliate_account_id configurado - o split não pode ser processado!");
            }
        }
    }

    private function diagnoseIssues(Split $split): array
    {
        $issues = [];

        // Check status
        if ($split->status === 'failed') {
            $issues[] = "Split está com status 'failed' - pode ter ocorrido erro no processamento";
        }

        if ($split->status === 'processing') {
            $issues[] = "Split está com status 'processing' - pode estar travado";
        }

        // Check transaction
        $transaction = Transaction::find($split->transaction_id);
        if (!$transaction) {
            $issues[] = "Transação não encontrada";
        } else {
            $charge = $transaction->charge;
            if ($charge && $charge->status !== 'paid') {
                $issues[] = "Charge não está como 'paid' (status: {$charge->status})";
            }
        }

        // Check recipient wallet
        if ($split->recipient_type === 'account') {
            $wallet = AccountWallet::where('account_id', $split->recipient_id)->first();
            if (!$wallet) {
                $issues[] = "Wallet do destinatário não existe (account_id: {$split->recipient_id})";
            }
        } elseif ($split->recipient_type === 'affiliate') {
            $affiliate = Affiliate::find($split->recipient_id);
            if (!$affiliate) {
                $issues[] = "Afiliado não encontrado";
            } elseif (!$affiliate->affiliate_account_id) {
                $issues[] = "Afiliado não possui affiliate_account_id configurado";
            } else {
                $wallet = AccountWallet::where('account_id', $affiliate->affiliate_account_id)->first();
                if (!$wallet) {
                    $issues[] = "Wallet do afiliado não existe (account_id: {$affiliate->affiliate_account_id})";
                }
            }
        }

        return $issues;
    }

    private function processSplit(Split $split): int
    {
        $this->info("🔄 Processando split...");

        try {
            $this->splitService->processSplit($split);

            $split->refresh();

            $this->newLine();
            $this->info("✅ Split processado com sucesso!");
            $this->info("   Status: {$this->formatStatus($split->status)}");
            $this->info("   Transferido em: {$split->transferred_at}");

            Log::info('Split processado manualmente via comando', [
                'split_id' => $split->id,
                'net_amount' => $split->net_amount,
                'recipient_id' => $split->recipient_id,
                'recipient_type' => $split->recipient_type,
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Erro ao processar split: {$e->getMessage()}");
            Log::error('Erro ao processar split via comando', [
                'split_id' => $split->id,
                'error' => $e->getMessage(),
            ]);
            return Command::FAILURE;
        }
    }

    private function forceCredit(Split $split): int
    {
        $this->info("🔄 Creditando wallet manualmente (FORCE)...");

        try {
            DB::transaction(function () use ($split) {
                $wallet = null;

                if ($split->recipient_type === 'account') {
                    $wallet = AccountWallet::where('account_id', $split->recipient_id)
                        ->lockForUpdate()
                        ->first();
                } elseif ($split->recipient_type === 'affiliate') {
                    $affiliate = Affiliate::find($split->recipient_id);
                    if ($affiliate && $affiliate->affiliate_account_id) {
                        $wallet = AccountWallet::where('account_id', $affiliate->affiliate_account_id)
                            ->lockForUpdate()
                            ->first();
                    }
                }

                if (!$wallet) {
                    throw new \Exception("Wallet do destinatário não encontrada");
                }

                $oldBalance = $wallet->available_balance;
                $wallet->increment('available_balance', $split->net_amount);
                $newBalance = $wallet->fresh()->available_balance;

                $this->info("   💰 Saldo anterior: R$ " . number_format($oldBalance / 100, 2, ',', '.'));
                $this->info("   💰 Saldo novo: R$ " . number_format($newBalance / 100, 2, ',', '.'));
            });

            Log::warning('Split creditado manualmente via FORCE', [
                'split_id' => $split->id,
                'net_amount' => $split->net_amount,
                'recipient_id' => $split->recipient_id,
            ]);

            $this->newLine();
            $this->info("✅ Crédito realizado com sucesso!");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Erro ao creditar: {$e->getMessage()}");
            return Command::FAILURE;
        }
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
