<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$accountId = '20d4d539-9012-428e-9bc4-5fd2a33c9632';
$withdrawalId = '09771d42-26a2-4d17-987b-32969979372b';

echo "Buscando saque específico...\n\n";

$withdrawal = \App\Models\Withdrawal::find($withdrawalId);

if (!$withdrawal) {
    echo "Saque {$withdrawalId} não encontrado.\n";
    exit(1);
}

echo "INFORMAÇÕES DO SAQUE:\n";
echo "ID: {$withdrawal->id}\n";
echo "Conta: {$withdrawal->account_id}\n";
echo "Valor: R$ " . number_format($withdrawal->amount, 2, ',', '.') . "\n";
echo "Taxa: R$ " . number_format($withdrawal->fee, 2, ',', '.') . "\n";
echo "Valor Líquido: R$ " . number_format($withdrawal->net_amount, 2, ',', '.') . "\n";
echo "Status: {$withdrawal->status}\n";
echo "Método: {$withdrawal->method}\n";
echo "Criado em: {$withdrawal->created_at}\n";
echo str_repeat('=', 60) . "\n\n";

if ($withdrawal->account_id !== $accountId) {
    echo "AVISO: O saque pertence à conta {$withdrawal->account_id}, não à conta {$accountId}\n";
    echo "Usando a conta correta do saque...\n\n";
    $accountId = $withdrawal->account_id;
}

$withdrawals = collect([$withdrawal]);

if (!$withdrawal) {
    echo "Saque não encontrado.\n";
    exit(0);
}

echo "DETALHES DO SAQUE:\n";
echo "ID: {$withdrawal->id}\n";
echo "Valor: R$ " . number_format($withdrawal->amount, 2, ',', '.') . "\n";
echo "Taxa: R$ " . number_format($withdrawal->fee, 2, ',', '.') . "\n";
echo "Valor Líquido: R$ " . number_format($withdrawal->net_amount, 2, ',', '.') . "\n";
echo "Status: {$withdrawal->status}\n";
echo "Método: {$withdrawal->method}\n";
echo "Criado em: {$withdrawal->created_at}\n";
echo str_repeat('-', 60) . "\n\n";

// Buscar carteira
$wallet = \App\Models\AccountWallet::where('account_id', $accountId)->first();

if ($wallet) {
    echo "SALDO DA CARTEIRA:\n";
    echo "Saldo Disponível: R$ " . number_format($wallet->available_balance / 100, 2, ',', '.') . "\n";
    echo "Saldo Pendente: R$ " . number_format($wallet->pending_balance / 100, 2, ',', '.') . "\n";
} else {
    echo "Carteira não encontrada para esta conta.\n";
}
