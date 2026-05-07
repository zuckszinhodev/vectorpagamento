<?php

namespace App\Console\Commands;

use App\Models\AccountWallet;
use App\Models\Withdrawal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MarkWithdrawalAsPaidCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'withdrawal:mark-paid 
                            {withdrawal_id : ID do saque} 
                            {account_id : ID da conta}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Marca um saque como pago e deduz o valor da conta sem realmente processar o saque';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $withdrawalId = $this->argument('withdrawal_id');
        $accountId = $this->argument('account_id');

        // Buscar o saque
        $withdrawal = Withdrawal::find($withdrawalId);

        if (!$withdrawal) {
            $this->error("Saque com ID {$withdrawalId} não encontrado.");
            return Command::FAILURE;
        }

        // Verificar se a conta corresponde
        if ($withdrawal->account_id !== $accountId) {
            $this->error("O saque {$withdrawalId} não pertence à conta {$accountId}.");
            $this->info("Conta do saque: {$withdrawal->account_id}");
            return Command::FAILURE;
        }

        // Mostrar informações do saque
        $this->info("Saque encontrado:");
        $this->table(
            ['Campo', 'Valor'],
            [
                ['ID', $withdrawal->id],
                ['Conta', $withdrawal->account_id],
                ['Valor', 'R$ ' . number_format($withdrawal->amount, 2, ',', '.')],
                ['Valor Líquido', 'R$ ' . number_format($withdrawal->net_amount, 2, ',', '.')],
                ['Taxa', 'R$ ' . number_format($withdrawal->fee, 2, ',', '.')],
                ['Status Atual', $withdrawal->status],
                ['Método', $withdrawal->method],
            ]
        );

        // Confirmar ação
        if (!$this->confirm('Deseja marcar este saque como pago e deduzir o valor da conta?')) {
            $this->info('Operação cancelada.');
            return Command::SUCCESS;
        }

        try {
            DB::beginTransaction();

            // Marcar saque como completado
            $withdrawal->update([
                'status' => 'completed',
                'processed_at' => now(),
                'notes' => 'Marcado como pago manualmente via comando administrativo',
            ]);

            // Buscar carteira da conta
            $wallet = AccountWallet::where('account_id', $accountId)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                throw new \Exception("Carteira não encontrada para a conta {$accountId}");
            }

            $this->info("\nSaldo atual da carteira:");
            $this->table(
                ['Campo', 'Valor'],
                [
                    ['Saldo Disponível', 'R$ ' . number_format($wallet->available_balance / 100, 2, ',', '.')],
                    ['Saldo Pendente', 'R$ ' . number_format($wallet->pending_balance / 100, 2, ',', '.')],
                ]
            );

            // Deduzir do saldo pendente (o valor já estava reservado)
            $amountCents = (int) $withdrawal->amount;
            $wallet->decrement('pending_balance', $amountCents);

            $wallet->refresh();

            DB::commit();

            $this->info("\n✓ Saque marcado como pago com sucesso!");
            $this->info("✓ Valor deduzido do saldo pendente: R$ " . number_format($amountCents / 100, 2, ',', '.'));
            
            $this->info("\nNovo saldo da carteira:");
            $this->table(
                ['Campo', 'Valor'],
                [
                    ['Saldo Disponível', 'R$ ' . number_format($wallet->available_balance / 100, 2, ',', '.')],
                    ['Saldo Pendente', 'R$ ' . number_format($wallet->pending_balance / 100, 2, ',', '.')],
                ]
            );

            Log::info('Saque marcado como pago manualmente', [
                'withdrawal_id' => $withdrawalId,
                'account_id' => $accountId,
                'amount' => $amountCents,
                'command_user' => $this->option('env') ?? 'console',
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->error("\n✗ Erro ao processar operação: " . $e->getMessage());
            
            Log::error('Erro ao marcar saque como pago manualmente', [
                'withdrawal_id' => $withdrawalId,
                'account_id' => $accountId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}
