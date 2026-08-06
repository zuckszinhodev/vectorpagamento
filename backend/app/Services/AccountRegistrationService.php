<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\WelcomeMail;
use App\Models\Account;
use App\Models\AccountWallet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AccountRegistrationService
{
    public function __construct(
        private readonly AccountNotificationService $notifications,
    ) {
    }

    /**
     * Create a new account with user and wallet atomically.
     *
     * @param array{name: string, email: string, password: ?string, google_id: ?string} $data
     * @return array{account: Account, user: User}
     *
     * @throws \RuntimeException
     */
    public function createAccount(array $data): array
    {
        $account = null;
        $user = null;

        DB::beginTransaction();
        try {
            $document = 'TEMP' . time() . random_int(100000, 999999);

            $account = Account::create([
                'id' => Str::uuid()->toString(),
                'name' => $data['name'],
                'document' => $document,
                'document_type' => 'cpf',
                'email' => $data['email'],
                'status' => 'pending',
            ]);

            $user = new User();
            $user->id = Str::uuid()->toString();
            $user->account_id = $account->id;
            $user->email = $data['email'];
            $user->password = Hash::make($data['password'] ?? Str::random(32));
            $user->name = $data['name'];
            $user->kyc_status = 'pending';

            if (!empty($data['google_id'])) {
                $user->google_id = $data['google_id'];
            }

            $user->save();

            AccountWallet::create([
                'id' => Str::uuid()->toString(),
                'account_id' => $account->id,
                'available_balance' => 0,
                'pending_balance' => 0,
                'blocked_balance' => 0,
                'currency' => 'BRL',
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AccountRegistrationService: registration failed', [
                'error' => $e->getMessage(),
                'email' => $data['email'],
            ]);
            throw new \RuntimeException('Falha ao criar conta. Tente novamente.');
        }

        $this->sendWelcomeEmail($user);
        $this->notifications->kycPending($user);

        return ['account' => $account, 'user' => $user];
    }

    private function sendWelcomeEmail(User $user): void
    {
        try {
            Mail::to($user->email)->send(new WelcomeMail($user));
        } catch (\Exception $e) {
            Log::error('AccountRegistrationService: failed to send welcome email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
