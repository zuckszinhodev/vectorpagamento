<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "LISTANDO TODAS AS CONTAS E SAQUES DO SISTEMA\n";
echo str_repeat('=', 60) . "\n\n";

// Listar todas as contas
$accounts = \App\Models\Account::orderBy('created_at', 'desc')->limit(20)->get();

echo "CONTAS CADASTRADAS (últimas 20):\n\n";

if ($accounts->isEmpty()) {
    echo "Nenhuma conta encontrada.\n\n";
} else {
    foreach ($accounts as $acc) {
        echo "ID: {$acc->id}\n";
        echo "Nome: {$acc->name}\n";
        echo "Email: {$acc->email}\n";
        echo "Status: {$acc->status}\n";
        echo "Criado em: {$acc->created_at->format('d/m/Y H:i')}\n";
        
        // Contar saques desta conta
        $withdrawalCount = \App\Models\Withdrawal::where('account_id', $acc->id)->count();
        echo "Saques: {$withdrawalCount}\n";
        
        echo str_repeat('-', 60) . "\n\n";
    }
}

echo "\n";
echo str_repeat('=', 60) . "\n";
echo "TODOS OS SAQUES (últimos 50):\n";
echo str_repeat('=', 60) . "\n\n";

$withdrawals = \App\Models\Withdrawal::orderBy('created_at', 'desc')->limit(50)->get();

if ($withdrawals->isEmpty()) {
    echo "Nenhum saque encontrado no sistema.\n";
} else {
    echo "Total de saques: {$withdrawals->count()}\n\n";
    
    foreach ($withdrawals as $w) {
        echo "ID Saque: {$w->id}\n";
        echo "ID Conta: {$w->account_id}\n";
        
        $acc = \App\Models\Account::find($w->account_id);
        if ($acc) {
            echo "Nome: {$acc->name}\n";
        }
        
        echo "Valor: R$ " . number_format($w->amount / 100, 2, ',', '.') . "\n";
        echo "Status: {$w->status}\n";
        echo "Método: {$w->method}\n";
        echo "Data: {$w->created_at->format('d/m/Y H:i:s')}\n";
        
        if ($w->failure_reason) {
            echo "Motivo Falha: {$w->failure_reason}\n";
        }
        
        echo str_repeat('-', 60) . "\n\n";
    }
}
