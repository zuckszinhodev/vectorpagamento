<?php

/**
 * Script que usa Laravel para pegar credenciais do .env
 */

require __DIR__.'/vendor/autoload.php';

try {
    $app = require_once __DIR__.'/bootstrap/app.php';
    $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
    
    echo "✓ Laravel carregado\n";
    
    // Pegar credenciais do config do Laravel
    $host = config('database.connections.pgsql.host');
    $port = config('database.connections.pgsql.port');
    $database = config('database.connections.pgsql.database');
    $username = config('database.connections.pgsql.username');
    $password = config('database.connections.pgsql.password');
    
    echo "Conectando ao banco: {$host}\n";
    echo "Usuário: {$username}\n\n";
    
    // Usar o Laravel DB diretamente
    $withdrawalId = '80a9c044-af01-4585-af26-17d304446e31';
    
    echo "Buscando saque {$withdrawalId}...\n\n";
    
    $withdrawal = DB::table('withdrawals')->where('id', $withdrawalId)->first();
    
    if (!$withdrawal) {
        echo "✗ Saque não encontrado\n";
        exit(1);
    }
    
    echo "SAQUE ENCONTRADO:\n";
    echo "ID: {$withdrawal->id}\n";
    echo "Conta: {$withdrawal->account_id}\n";
    echo "Valor: R$ " . number_format($withdrawal->amount / 100, 2, ',', '.') . "\n";
    echo "Status: {$withdrawal->status}\n";
    if ($withdrawal->failure_reason) {
        echo "Motivo: {$withdrawal->failure_reason}\n";
    }
    echo str_repeat('=', 60) . "\n\n";
    
    $wallet = DB::table('account_wallets')->where('account_id', $withdrawal->account_id)->first();
    
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
    
    if ($withdrawal->status === 'failed') {
        // Se falhou, deduz do saldo disponível
        DB::table('account_wallets')
            ->where('account_id', $withdrawal->account_id)
            ->decrement('available_balance', $amount);
        echo "✓ Deduzido R$ " . number_format($amount / 100, 2, ',', '.') . " do saldo disponível\n";
    } else {
        // Se pending/processing, deduz do saldo pendente
        DB::table('account_wallets')
            ->where('account_id', $withdrawal->account_id)
            ->decrement('pending_balance', $amount);
        echo "✓ Deduzido R$ " . number_format($amount / 100, 2, ',', '.') . " do saldo pendente\n";
    }
    
    // Marcar saque como pago
    DB::table('withdrawals')
        ->where('id', $withdrawalId)
        ->update([
            'status' => 'completed',
            'processed_at' => now(),
            'notes' => 'Marcado como pago manualmente via script administrativo',
            'failure_reason' => null,
            'updated_at' => now(),
        ]);
    
    DB::commit();
    
    // Buscar saldo atualizado
    $updatedWallet = DB::table('account_wallets')->where('account_id', $withdrawal->account_id)->first();
    
    echo "\n";
    echo str_repeat('=', 60) . "\n";
    echo "✓ OPERAÇÃO CONCLUÍDA COM SUCESSO!\n";
    echo str_repeat('=', 60) . "\n\n";
    
    echo "Saque ID: {$withdrawalId}\n";
    echo "Status: {$withdrawal->status} → completed\n";
    echo "Valor descontado: R$ " . number_format($amount / 100, 2, ',', '.') . "\n\n";
    
    echo "NOVO SALDO:\n";
    echo "Disponível: R$ " . number_format($updatedWallet->available_balance / 100, 2, ',', '.') . "\n";
    echo "Pendente: R$ " . number_format($updatedWallet->pending_balance / 100, 2, ',', '.') . "\n";
    
} catch (Exception $e) {
    if (class_exists('DB') && method_exists('DB', 'rollBack')) {
        DB::rollBack();
    }
    echo "✗ ERRO: " . $e->getMessage() . "\n";
    echo "Linha: " . $e->getLine() . "\n";
    exit(1);
}