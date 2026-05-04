<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FindByIdCommand extends Command
{
    protected $signature = 'debug:find-id {id : UUID para buscar em todas as tabelas}';

    protected $description = 'Busca um UUID em todas as tabelas principais do sistema';

    public function handle(): int
    {
        $id = $this->argument('id');

        $this->info("🔍 Buscando ID: {$id}");
        $this->newLine();

        $tables = [
            'splits' => ['id', 'transaction_id', 'recipient_id'],
            'transactions' => ['id', 'charge_id', 'account_id'],
            'charges' => ['id', 'account_id', 'checkout_id', 'product_id', 'affiliate_id'],
            'accounts' => ['id'],
            'users' => ['id', 'account_id'],
            'affiliates' => ['id', 'account_id', 'affiliate_account_id'],
            'split_rules' => ['id', 'account_id', 'product_id', 'checkout_id'],
            'account_wallets' => ['id', 'account_id'],
            'products' => ['id', 'account_id'],
            'checkouts' => ['id', 'account_id', 'product_id'],
            'withdrawals' => ['id', 'account_id'],
        ];

        $found = false;

        foreach ($tables as $table => $columns) {
            if (!$this->tableExists($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (!$this->columnExists($table, $column)) {
                    continue;
                }

                $record = DB::table($table)->where($column, $id)->first();

                if ($record) {
                    $found = true;
                    $this->info("✅ Encontrado na tabela: {$table} (coluna: {$column})");
                    $this->newLine();

                    $data = collect((array) $record)->map(function ($value, $key) {
                        if (is_array($value) || is_object($value)) {
                            return [ucfirst($key), json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)];
                        }
                        if (is_numeric($value) && in_array($key, ['amount', 'net_amount', 'fee_amount', 'available_balance', 'pending_balance', 'price', 'total'])) {
                            return [ucfirst($key), 'R$ ' . number_format($value / 100, 2, ',', '.') . " ({$value})"];
                        }
                        return [ucfirst($key), $value ?? 'NULL'];
                    })->values()->toArray();

                    $this->table(['Campo', 'Valor'], $data);
                    $this->newLine();

                    // Show related data
                    $this->showRelatedData($table, $record);
                }
            }
        }

        if (!$found) {
            $this->error("❌ ID não encontrado em nenhuma tabela principal.");
            $this->newLine();
            $this->info("💡 Sugestões:");
            $this->line("   • Verifique se o ID está correto");
            $this->line("   • O registro pode ter sido deletado");
            $this->line("   • Pode ser um ID de outro sistema/ambiente");
        }

        return $found ? Command::SUCCESS : Command::FAILURE;
    }

    private function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            return DB::getSchemaBuilder()->hasColumn($table, $column);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function showRelatedData(string $table, object $record): void
    {
        switch ($table) {
            case 'charges':
                // Show splits for this charge
                $transaction = DB::table('transactions')->where('charge_id', $record->id)->first();
                if ($transaction) {
                    $this->info("📋 Transação relacionada:");
                    $this->line("   ID: {$transaction->id}");
                    $this->line("   Status: {$transaction->status}");

                    $splits = DB::table('splits')->where('transaction_id', $transaction->id)->get();
                    if ($splits->isNotEmpty()) {
                        $this->newLine();
                        $this->info("💰 Splits desta cobrança ({$splits->count()}):");
                        foreach ($splits as $split) {
                            $amount = number_format($split->net_amount / 100, 2, ',', '.');
                            $this->line("   • {$split->id} - {$split->recipient_type} - R$ {$amount} - {$split->status}");
                        }
                    } else {
                        $this->warn("   ⚠️ Nenhum split encontrado para esta cobrança!");
                    }
                } else {
                    $this->warn("   ⚠️ Nenhuma transação encontrada para esta cobrança!");
                }
                break;

            case 'transactions':
                $splits = DB::table('splits')->where('transaction_id', $record->id)->get();
                if ($splits->isNotEmpty()) {
                    $this->info("💰 Splits desta transação ({$splits->count()}):");
                    foreach ($splits as $split) {
                        $amount = number_format($split->net_amount / 100, 2, ',', '.');
                        $this->line("   • {$split->id} - {$split->recipient_type} - R$ {$amount} - {$split->status}");
                    }
                } else {
                    $this->warn("   ⚠️ Nenhum split encontrado!");
                }
                break;

            case 'accounts':
                $wallet = DB::table('account_wallets')->where('account_id', $record->id)->first();
                if ($wallet) {
                    $balance = number_format($wallet->available_balance / 100, 2, ',', '.');
                    $this->info("💳 Wallet: R$ {$balance} disponível");
                }

                // Show charges for this account
                $charges = DB::table('charges')
                    ->where('account_id', $record->id)
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get();

                if ($charges->isNotEmpty()) {
                    $this->newLine();
                    $this->info("📋 Últimas cobranças desta conta ({$charges->count()}):");
                    foreach ($charges as $charge) {
                        $amount = number_format($charge->amount / 100, 2, ',', '.');
                        $this->line("   • {$charge->id} | {$charge->status} | R$ {$amount} | {$charge->created_at}");
                        
                        // Check if has splits
                        $transaction = DB::table('transactions')->where('charge_id', $charge->id)->first();
                        if ($transaction) {
                            $splitCount = DB::table('splits')->where('transaction_id', $transaction->id)->count();
                            if ($splitCount > 0) {
                                $this->line("     └─ {$splitCount} split(s)");
                            } else {
                                $this->warn("     └─ ⚠️ Sem splits!");
                            }
                        } else {
                            $this->warn("     └─ ⚠️ Sem transação!");
                        }
                    }
                } else {
                    $this->warn("   ⚠️ Nenhuma cobrança encontrada para esta conta!");
                }

                // Check if this account is an affiliate recipient
                $affiliateSplits = DB::table('splits')
                    ->where('recipient_id', $record->id)
                    ->where('recipient_type', 'account')
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get();

                if ($affiliateSplits->isNotEmpty()) {
                    $this->newLine();
                    $this->info("💰 Splits recebidos como destinatário ({$affiliateSplits->count()}):");
                    foreach ($affiliateSplits as $split) {
                        $amount = number_format($split->net_amount / 100, 2, ',', '.');
                        $this->line("   • {$split->id} | {$split->status} | R$ {$amount}");
                    }
                }

                // Check if this account is linked to an affiliate
                $affiliate = null;
                if ($this->columnExists('affiliates', 'affiliate_account_id')) {
                    $affiliate = DB::table('affiliates')->where('affiliate_account_id', $record->id)->first();
                } elseif ($this->columnExists('affiliates', 'user_id')) {
                    // Find user for this account, then check affiliates
                    $user = DB::table('users')->where('account_id', $record->id)->first();
                    if ($user) {
                        $affiliate = DB::table('affiliates')->where('user_id', $user->id)->first();
                    }
                }
                
                if ($affiliate) {
                    $this->newLine();
                    $this->info("🤝 Esta conta está vinculada ao afiliado:");
                    $this->line("   ID: {$affiliate->id}");
                    $this->line("   Nome: " . ($affiliate->name ?? 'N/A'));
                    
                    // Check affiliate splits
                    $affSplits = DB::table('splits')
                        ->where('recipient_id', $affiliate->id)
                        ->where('recipient_type', 'affiliate')
                        ->get();
                    if ($affSplits->isNotEmpty()) {
                        $total = $affSplits->sum('net_amount');
                        $this->line("   Splits como afiliado: {$affSplits->count()} (R$ " . number_format($total/100,2,',','.') . ")");
                    }
                }
                break;

            case 'affiliates':
                if (!empty($record->affiliate_account_id)) {
                    $wallet = DB::table('account_wallets')->where('account_id', $record->affiliate_account_id)->first();
                    if ($wallet) {
                        $balance = number_format($wallet->available_balance / 100, 2, ',', '.');
                        $this->info("💳 Wallet do afiliado: R$ {$balance} disponível");
                    } else {
                        $this->warn("   ⚠️ Afiliado sem wallet!");
                    }
                } else {
                    $this->warn("   ⚠️ Afiliado sem affiliate_account_id configurado!");
                }
                break;
        }
    }
}
