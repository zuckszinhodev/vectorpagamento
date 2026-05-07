<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$accountId = '20d4d539-9012-428e-9bc4-5fd2a33c9632';

echo "Buscando todos os saques da conta: {$accountId}\n\n";

$withdrawals = \App\Models\Withdrawal::where('account_id', $accountId)
    ->orderBy('created_at', 'desc')
    ->get();

if ($withdrawals->isEmpty()) {
    echo "Nenhum saque encontrado para esta conta.\n\n";
    
    // Verificar se a conta existe
    $account = \App\Models\Account::find($accountId);
    if ($account) {
        echo "Conta encontrada: {$account->name} ({$account->email})\n";
    } else {
        echo "Conta não encontrada no banco de dados.\n";
    }
    
    exit(0);
}

echo "Total de saques encontrados: " . $withdrawals->count() . "\n\n";

foreach ($withdrawals as $w) {
    echo "ID: {$w->id}\n";
    echo "Valor: R$ " . number_format($w->amount, 2, ',', '.') . "\n";
    echo "Taxa: R$ " . number_format($w->fee ?? 0, 2, ',', '.') . "\n";
    echo "Valor Líquido: R$ " . number_format($w->net_amount, 2, ',', '.') . "\n";
    echo "Status: {$w->status}\n";
    echo "Método: {$w->method}\n";
    echo "Criado em: {$w->created_at}\n";
    echo str_repeat('-', 60) . "\n\n";
}

// Buscar carteira
$wallet = \App\Models\AccountWallet::where('account_id', $accountId)->first();

if ($wallet) {
    echo "\nSALDO DA CARTEIRA:\n";
    echo "Saldo Disponível: R$ " . number_format($wallet->available_balance / 100, 2, ',', '.') . "\n";
    echo "Saldo Pendente: R$ " . number_format($wallet->pending_balance / 100, 2, ',', '.') . "\n";
} else {
    echo "\nCarteira não encontrada para esta conta.\n";
}
