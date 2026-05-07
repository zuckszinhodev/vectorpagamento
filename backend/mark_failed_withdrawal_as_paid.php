<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$accountId = '20d4d539-9012-428e-9bc4-5fd2a33c9632';
$amount = 88100; // R$ 881,00 em centavos
$date = '2026-05-07';

echo "Buscando saque FALHO de R$ 881,00 da conta em 07/05/2026...\n\n";

// Buscar saque com status failed
$withdrawal = \App\Models\Withdrawal::where('account_id', $accountId)
    ->where('status', 'failed')
    ->where('amount', $amount)
    ->whereDate('created_at', $date)
    ->first();

if (!$withdrawal) {
    echo "Saque falho não encontrado com esses critérios.\n";
    echo "Buscando todos os saques falhos da conta:\n\n";
    
    $failedWithdrawals = \App\Models\Withdrawal::where('account_id', $accountId)
        ->where('status', 'failed')
        ->orderBy('created_at', 'desc')
        ->get();
    
    if ($failedWithdrawals->isEmpty()) {
        echo "Nenhum saque falho encontrado.\n";
        echo "Buscando TODOS os saques da conta:\n\n";
        
        $allWithdrawals = \App\Models\Withdrawal::where('account_id', $accountId)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();
        
        if ($allWithdrawals->isEmpty()) {
            echo "Nenhum saque encontrado para esta conta.\n";
            exit(1);
        }
        
        foreach ($allWithdrawals as $w) {
            echo "ID: {$w->id}\n";
            echo "Valor: R$ " . number_format($w->amount / 100, 2, ',', '.') . "\n";
            echo "Status: {$w->status}\n";
            echo "Data: {$w->created_at->format('d/m/Y H:i')}\n";
            echo str_repeat('-', 60) . "\n\n";
        }
        
        // Pegar o primeiro que seja próximo ao valor
        $withdrawal = $allWithdrawals->where('amount', $amount)->first();
        
        if (!$withdrawal) {
            echo "Nenhum saque com valor de R$ 881,00 encontrado.\n";
            exit(1);
        }
    } else {
        echo "Saques falhos encontrados:\n\n";
        foreach ($failedWithdrawals as $w) {
            echo "ID: {$w->id}\n";
            echo "Valor: R$ " . number_format($w->amount / 100, 2, ',', '.') . "\n";
            echo "Status: {$w->status}\n";
            echo "Data: {$w->created_at->format('d/m/Y H:i')}\n";
            echo "Motivo: " . ($w->failure_reason ?? 'N/A') . "\n";
            echo str_repeat('-', 60) . "\n\n";
        }
        
        $withdrawal = $failedWithdrawals->first();
    }
}

echo "\n";
echo str_repeat('=', 60) . "\n";
echo "SAQUE SELECIONADO PARA MARCAR COMO PAGO:\n";
echo str_repeat('=', 60) . "\n";
echo "ID: {$withdrawal->id}\n";
echo "Conta: {$withdrawal->account_id}\n";
echo "Valor: R$ " . number_format($withdrawal->amount / 100, 2, ',', '.') . "\n";
echo "Taxa: R$ " . number_format(($withdrawal->fee ?? 0) / 100, 2, ',', '.') . "\n";
echo "Valor Líquido: R$ " . number_format($withdrawal->net_amount / 100, 2, ',', '.') . "\n";
echo "Status Atual: {$withdrawal->status}\n";
echo "Método: {$withdrawal->method}\n";
echo "Criado em: {$withdrawal->created_at->format('d/m/Y H:i')}\n";
if ($withdrawal->failure_reason) {
    echo "Motivo da Falha: {$withdrawal->failure_reason}\n";
}
echo str_repeat('=', 60) . "\n\n";

// Buscar carteira antes
$wallet = \App\Models\AccountWallet::where('account_id', $accountId)->lockForUpdate()->first();

if (!$wallet) {
    echo "ERRO: Carteira não encontrada para esta conta.\n";
    exit(1);
}

echo "SALDO ATUAL DA CARTEIRA:\n";
echo "Saldo Disponível: R$ " . number_format($wallet->available_balance / 100, 2, ',', '.') . "\n";
echo "Saldo Pendente: R$ " . number_format($wallet->pending_balance / 100, 2, ',', '.') . "\n";
echo str_repeat('=', 60) . "\n\n";

echo "PROCESSANDO OPERAÇÃO...\n\n";

try {
    DB::beginTransaction();

    $amountCents = (int) $withdrawal->amount;
    
    // Se o saque falhou, o dinheiro já voltou para available_balance
    // Agora precisamos deduzir do available_balance (como se tivesse sido pago)
    
    if ($withdrawal->status === 'failed') {
        // Quando falha, o dinheiro volta para available_balance
        // Então deduzimos do available_balance
        $wallet->decrement('available_balance', $amountCents);
        echo "✓ Deduzido R$ " . number_format($amountCents / 100, 2, ',', '.') . " do saldo disponível\n";
    } else {
        // Se está pending ou processing, deduz do pending
        $wallet->decrement('pending_balance', $amountCents);
        echo "✓ Deduzido R$ " . number_format($amountCents / 100, 2, ',', '.') . " do saldo pendente\n";
    }

    // Marcar saque como completado
    $withdrawal->update([
        'status' => 'completed',
        'processed_at' => now(),
        'notes' => 'Marcado como pago manualmente via script administrativo - ajuste de saldo sem processamento na adquirente',
        'failure_reason' => null,
    ]);

    $wallet->refresh();

    DB::commit();

    echo "\n";
    echo str_repeat('=', 60) . "\n";
    echo "✓ OPERAÇÃO CONCLUÍDA COM SUCESSO!\n";
    echo str_repeat('=', 60) . "\n\n";
    
    echo "Saque ID: {$withdrawal->id}\n";
    echo "Status atualizado: failed → completed\n";
    echo "Valor descontado: R$ " . number_format($amountCents / 100, 2, ',', '.') . "\n\n";
    
    echo "NOVO SALDO DA CARTEIRA:\n";
    echo "Saldo Disponível: R$ " . number_format($wallet->available_balance / 100, 2, ',', '.') . "\n";
    echo "Saldo Pendente: R$ " . number_format($wallet->pending_balance / 100, 2, ',', '.') . "\n";
    echo str_repeat('=', 60) . "\n";
    
    \Log::info('Saque falho marcado como pago manualmente', [
        'withdrawal_id' => $withdrawal->id,
        'account_id' => $accountId,
        'amount' => $amountCents,
        'previous_status' => 'failed',
        'executed_at' => now(),
    ]);

} catch (\Exception $e) {
    DB::rollBack();
    echo "\n✗ ERRO AO PROCESSAR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
