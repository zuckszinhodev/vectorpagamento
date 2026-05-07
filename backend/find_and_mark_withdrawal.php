<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$accountId = '20d4d539-9012-428e-9bc4-5fd2a33c9632';
$amount = 88100; // R$ 881,00 em centavos
$date = '2026-05-07';

echo "Buscando saque de R$ 881,00 da conta {$accountId} em 07/05/2026...\n\n";

$withdrawal = \App\Models\Withdrawal::where('account_id', $accountId)
    ->where('amount', $amount)
    ->whereDate('created_at', $date)
    ->first();

if (!$withdrawal) {
    echo "Saque não encontrado com esses critérios exatos.\n";
    echo "Buscando saques próximos...\n\n";
    
    // Buscar saques próximos ao valor
    $withdrawals = \App\Models\Withdrawal::where('account_id', $accountId)
        ->whereBetween('amount', [$amount - 1000, $amount + 1000])
        ->whereDate('created_at', $date)
        ->get();
    
    if ($withdrawals->isEmpty()) {
        echo "Nenhum saque encontrado próximo a esse valor e data.\n";
        echo "Listando todos os saques da conta:\n\n";
        
        $allWithdrawals = \App\Models\Withdrawal::where('account_id', $accountId)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();
        
        foreach ($allWithdrawals as $w) {
            echo "ID: {$w->id}\n";
            echo "Valor: R$ " . number_format($w->amount / 100, 2, ',', '.') . "\n";
            echo "Status: {$w->status}\n";
            echo "Data: {$w->created_at}\n";
            echo str_repeat('-', 60) . "\n\n";
        }
        exit(1);
    }
    
    echo "Saques encontrados próximos ao valor:\n\n";
    foreach ($withdrawals as $w) {
        echo "ID: {$w->id}\n";
        echo "Valor: R$ " . number_format($w->amount / 100, 2, ',', '.') . "\n";
        echo "Status: {$w->status}\n";
        echo "Data: {$w->created_at}\n";
        echo str_repeat('-', 60) . "\n\n";
    }
    
    $withdrawal = $withdrawals->first();
}

echo "SAQUE ENCONTRADO:\n";
echo "ID: {$withdrawal->id}\n";
echo "Conta: {$withdrawal->account_id}\n";
echo "Valor: R$ " . number_format($withdrawal->amount / 100, 2, ',', '.') . "\n";
echo "Taxa: R$ " . number_format(($withdrawal->fee ?? 0) / 100, 2, ',', '.') . "\n";
echo "Valor Líquido: R$ " . number_format($withdrawal->net_amount / 100, 2, ',', '.') . "\n";
echo "Status Atual: {$withdrawal->status}\n";
echo "Método: {$withdrawal->method}\n";
echo "Criado em: {$withdrawal->created_at}\n";
echo str_repeat('=', 60) . "\n\n";

// Buscar carteira antes
$wallet = \App\Models\AccountWallet::where('account_id', $accountId)->first();

if ($wallet) {
    echo "SALDO ATUAL DA CARTEIRA:\n";
    echo "Saldo Disponível: R$ " . number_format($wallet->available_balance / 100, 2, ',', '.') . "\n";
    echo "Saldo Pendente: R$ " . number_format($wallet->pending_balance / 100, 2, ',', '.') . "\n";
    echo str_repeat('=', 60) . "\n\n";
} else {
    echo "ERRO: Carteira não encontrada para esta conta.\n";
    exit(1);
}

// Confirmar e executar
echo "Processando operação...\n\n";

try {
    DB::beginTransaction();

    // Marcar saque como completado
    $withdrawal->update([
        'status' => 'completed',
        'processed_at' => now(),
        'notes' => 'Marcado como pago manualmente via script administrativo - sem processamento real na adquirente',
    ]);

    // Deduzir do saldo pendente
    $amountCents = (int) $withdrawal->amount;
    $wallet->decrement('pending_balance', $amountCents);
    $wallet->refresh();

    DB::commit();

    echo "✓ OPERAÇÃO CONCLUÍDA COM SUCESSO!\n\n";
    echo "Saque ID: {$withdrawal->id}\n";
    echo "Status atualizado para: completed\n";
    echo "Valor deduzido do saldo pendente: R$ " . number_format($amountCents / 100, 2, ',', '.') . "\n\n";
    
    echo "NOVO SALDO DA CARTEIRA:\n";
    echo "Saldo Disponível: R$ " . number_format($wallet->available_balance / 100, 2, ',', '.') . "\n";
    echo "Saldo Pendente: R$ " . number_format($wallet->pending_balance / 100, 2, ',', '.') . "\n";
    
    \Log::info('Saque marcado como pago manualmente via script', [
        'withdrawal_id' => $withdrawal->id,
        'account_id' => $accountId,
        'amount' => $amountCents,
        'executed_at' => now(),
    ]);

} catch (\Exception $e) {
    DB::rollBack();
    echo "✗ ERRO AO PROCESSAR: " . $e->getMessage() . "\n";
    exit(1);
}
