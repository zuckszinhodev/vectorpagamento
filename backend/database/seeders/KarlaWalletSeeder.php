<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Account;
use App\Models\AccountWallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class KarlaWalletSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'karla.42542650@educa.rj.gov.br';
        
        // Tenta encontrar pela tabela accounts diretamente
        $account = DB::table('accounts')->where('email', $email)->first();
        
        if (!$account) {
            // Tenta pelo user
            $user = User::where('email', $email)->first();
            if ($user) {
                $accountId = $user->account_id;
                $this->command->info("Encontrado via User: account_id = {$accountId}");
            } else {
                $this->command->error("Usuário/Conta {$email} não encontrado");
                return;
            }
        } else {
            $accountId = $account->id;
            $this->command->info("Encontrado via Account: account_id = {$accountId}");
        }

        $wallet = AccountWallet::where('account_id', $accountId)->first();

        if (!$wallet) {
            $this->command->error('Carteira não encontrada para account_id: ' . $accountId);
            return;
        }

        $oldBalance = $wallet->available_balance;
        $newBalance = 5182; // R$ 51,82
        
        // Atualização direta no banco
        DB::table('account_wallets')
            ->where('id', $wallet->id)
            ->update(['available_balance' => $newBalance]);

        $this->command->info("Wallet ID: {$wallet->id}");
        $this->command->info("Saldo anterior: R$ " . number_format($oldBalance / 100, 2, ',', '.'));
        $this->command->info("Saldo novo: R$ " . number_format($newBalance / 100, 2, ',', '.'));
        $this->command->info("Atualização concluída!");
    }
}
