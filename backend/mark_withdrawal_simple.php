<?php

/**
 * Script simples para marcar saque como pago
 * Execute na VPS: php mark_withdrawal_simple.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$withdrawalId = '09771d42-26a2-4d17-987b-32969979372b';

echo "Buscando saque {$withdrawalId}...\n\n";

try {
    $withdrawal = \App\Models\Withdrawal::find($withdrawalId);
    
    if (!$withdrawal) {
        echo "✗ Saque não encontrado\n";
        exit(1);
    }
    
    echo "SAQUE ENCONTRADO:\n";
    echo "ID: {$withdrawal->id}\n";
    echo "Conta: {$withdrawal->account_id}\n";
    echo "Valor: R$ " . number_format($withdrawal->amount / 100, 2, ',', '.') . "\n";
    echo "Status: {$withdrawal->status}\n";
    echo "Motivo: {$withdrawal->failure_reason}\n";
    echo str_repeat('=', 60) . "\n\n";
    
    $wallet = \App\Models\AccountWallet::where('account_id', $withdrawal->account_id)->first();
    
    if (!$wallet) {
        echo "✗ Carteira não encontrada\n";
        exit(1);
    }
    
    echo "SALDO ATUAL:\n";
    echo "Disponível: R$ " . number_format($wallet->available_balance / 100, 2, ',', '.') . "\n";
    echo "Pendente: R$ " . number_format($wallet->pending_balance / 100, 2, ',', '.') . "\n";
    echo str_repeat('=', 60) . "\n\n";
    
    echo "PROCESSANDO...\n";
    
    DB::beginTransaction();
    
    $amount = (int) $withdrawal->amount;
    
    // Como falhou, deduz do saldo disponível
    $wallet->decrement('available_balance', $amount);
    
    $withdrawal->update([
        'status' => 'completed',
        'processed_at' => now(),
        'notes' => 'Marcado como pago manualmente via script',
        'failure_reason' => null,
    ]);
    
    DB::commit();
    
    $wallet->refresh();
    
    echo "\n✓ SUCESSO!\n";
    echo "Saque marcado como pago\n";
    echo "Valor descontado: R$ " . number_format($amount / 100, 2, ',', '.') . "\n";
    echo "Novo saldo disponível: R$ " . number_format($wallet->available_balance / 100, 2, ',', '.') . "\n";
    
} catch (Exception $e) {
    if (isset($withdrawal)) {
        DB::rollBack();
    }
    echo "✗ ERRO: " . $e->getMessage() . "\n";
    exit(1);
}