<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\AccountWallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TestWalletSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'test2@test.com')->first();
        
        if (!$user) {
            $this->command->info('User not found');
            return;
        }

        $wallet = AccountWallet::firstOrCreate(
            ['account_id' => $user->account_id],
            [
                'id' => Str::uuid()->toString(),
                'account_id' => $user->account_id,
                'currency' => 'BRL',
                'available_balance' => 500000, // R$ 5.000,00
                'pending_balance' => 0,
                'blocked_balance' => 0,
            ]
        );

        if (!$wallet->wasRecentlyCreated) {
            $wallet->update([
                'available_balance' => 500000,
                'pending_balance' => 0,
                'blocked_balance' => 0,
            ]);
        }

        $this->command->info('Wallet created/updated with R$ 5.000,00 balance');
    }
}
