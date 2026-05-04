<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WhiterAccountSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Check if account already exists
        $existingAccount = DB::table('accounts')->where('email', 'contatowhiter@gmail.com')->first();
        
        if ($existingAccount) {
            // Get owner role
            $ownerRole = DB::table('roles')->where('slug', 'owner')->first();

            // Update existing user's role, password and KYC status
            DB::table('users')
                ->where('account_id', $existingAccount->id)
                ->update([
                    'role_id' => $ownerRole?->id,
                    'password' => Hash::make('Lucas209_@'),
                    'kyc_status' => 'approved',
                    'kyc_submitted_at' => $now,
                    'kyc_approved_at' => $now,
                    'kyc_rejection_reason' => null,
                    'email_verified_at' => $now,
                    'updated_at' => $now,
                ]);
            
            // Also update account status to active
            DB::table('accounts')
                ->where('id', $existingAccount->id)
                ->update([
                    'status' => 'active',
                    'updated_at' => $now,
                ]);

            $this->command->info('✅ Conta contatowhiter@gmail.com atualizada com KYC verificado!');
            return;
        }

        $accountId = Str::uuid()->toString();
        $userId = Str::uuid()->toString();

        // Get owner role
        $ownerRole = DB::table('roles')->where('slug', 'owner')->first();

        // Create account
        DB::table('accounts')->insert([
            'id' => $accountId,
            'name' => 'Whiter Account',
            'document' => '00000000000', // CPF placeholder - update as needed
            'document_type' => 'cpf',
            'email' => 'contatowhiter@gmail.com',
            'phone' => null,
            'status' => 'active',
            'settings' => '{}',
            'metadata' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Create user with verified KYC
        DB::table('users')->insert([
            'id' => $userId,
            'account_id' => $accountId,
            'role_id' => $ownerRole?->id,
            'email' => 'contatowhiter@gmail.com',
            'password' => Hash::make('Lucas209_@'),
            'name' => 'Lucas',
            'phone' => null,
            'email_verified_at' => $now,
            'mfa_enabled' => false,
            'mfa_secret' => null,
            'kyc_status' => 'approved',
            'kyc_submitted_at' => $now,
            'kyc_approved_at' => $now,
            'kyc_rejection_reason' => null,
            'last_login_at' => null,
            'last_login_ip' => null,
            'remember_token' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->command->info('✅ Conta contatowhiter@gmail.com criada com KYC verificado!');
    }
}
