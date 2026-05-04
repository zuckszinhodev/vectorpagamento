<?php

namespace App\Domain\Auth\Services;

use App\Domain\Auth\Entities\User;
use App\Domain\Account\Entities\Account;
use App\Domain\Account\Entities\AccountWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            // Create account
            $account = Account::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'type' => 'individual',
                'is_active' => true,
                'kyc_status' => 'pending',
            ]);

            // Create wallet
            AccountWallet::create([
                'account_id' => $account->id,
                'available_balance' => 0,
                'pending_balance' => 0,
                'blocked_balance' => 0,
                'currency' => 'BRL',
            ]);

            // Create user
            $user = new User();
            $user->account_id = $account->id;
            $user->role_id = $this->getOwnerRoleId();
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->password = Hash::make($data['password']);
            $user->is_active = true;
            $user->save();

            // Generate token
            $token = auth()->login($user);

            return [
                'user' => $user->fresh('account', 'role'),
                'account' => $account,
                'token' => $token,
            ];
        });
    }

    private function getOwnerRoleId(): string
    {
        return DB::table('roles')->where('name', 'owner')->value('id');
    }
}
