<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Verificando contas e saques no sistema...\n\n";

// Verificar se a conta existe
$accountId = '20d4d539-9012-428e-9bc4-5fd2a33c9632';
$account = \App\Models\Account::find($accountId);

if ($account) {
    echo "✓ Conta encontrada:\n";
    echo "ID: {$account->id}\n";
    echo "Nome: {$account->name}\n";
    echo "Email: {$account->email}\n";
    echo "Status: {$account->status}\n";
    echo str_repeat('-', 60) . "\n\n";
} else {
    echo "✗ Conta {$accountId} não encontrada.\n\n";
}

// Buscar todos os saques recentes (últimas 24h)
echo "Buscando saques recentes (últimas 24 horas):\n\n";

$recentWithdrawals = \App\Models\Withdrawal::where('created_at', '>=', now()->subDay())
    ->orderBy('created_at', 'desc')
    ->get();

if ($recentWithdrawals->isEmpty()) {
    echo "Nenhum saque nas últimas 24 horas.\n\n";
} else {
    echo "Total: {$recentWithdrawals->count()} saques\n\n";
    
    foreach ($recentWithdrawals as $w) {
        echo "ID Saque: {$w->id}\n";
        echo "ID Conta: {$w->account_id}\n";
        echo "Valor: R$ " . number_format($w->amount / 100, 2, ',', '.') . "\n";
        echo "Status: {$w->status}\n";
        echo "Data: {$w->created_at->format('d/m/Y H:i:s')}\n";
        
        $acc = \App\Models\Account::find($w->account_id);
        if ($acc) {
            echo "Nome da Conta: {$acc->name}\n";
        }
        
        echo str_repeat('-', 60) . "\n\n";
    }
}

// Buscar pelo ID do saque que foi informado
echo "\nVerificando ID do saque informado: 09771d42-26a2-4d17-987b-32969979372b\n\n";

$withdrawalById = \App\Models\Withdrawal::find('09771d42-26a2-4d17-987b-32969979372b');

if ($withdrawalById) {
    echo "✓ Saque encontrado:\n";
    echo "ID: {$withdrawalById->id}\n";
    echo "Conta: {$withdrawalById->account_id}\n";
    echo "Valor: R$ " . number_format($withdrawalById->amount / 100, 2, ',', '.') . "\n";
    echo "Status: {$withdrawalById->status}\n";
    echo "Data: {$withdrawalById->created_at->format('d/m/Y H:i:s')}\n";
} else {
    echo "✗ Saque com ID 09771d42-26a2-4d17-987b-32969979372b não encontrado.\n";
}
