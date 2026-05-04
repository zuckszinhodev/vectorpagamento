<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = \App\Models\User::where('email', 'vectorpagamento@gmail.com')->first();
if (!$user) {
    echo "USER_NOT_FOUND\n";
    exit(1);
}

$account = $user->account;
if (!$account) {
    echo "ACCOUNT_NOT_FOUND\n";
    exit(1);
}

$wallet = \App\Models\AccountWallet::where('account_id', $account->id)->first();

echo "USER_ID={$user->id}\n";
echo "ACCOUNT_ID={$account->id}\n";

if ($wallet) {
    echo "AVAILABLE={$wallet->available_balance} PENDING={$wallet->pending_balance} BLOCKED={$wallet->blocked_balance}\n";
} else {
    echo "WALLET_NOT_FOUND\n";
}
