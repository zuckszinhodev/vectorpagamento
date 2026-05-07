<?php

/**
 * Script para marcar saque como pago no banco de produção (Supabase)
 * 
 * USO:
 * DB_PASSWORD=sua_senha php mark_withdrawal_paid_production.php
 * 
 * Ou configure as variáveis de ambiente antes de executar
 */

require __DIR__.'/vendor/autoload.php';

// Carregar variáveis de ambiente de produção
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__, '.env.production');
$dotenv->safeLoad();

// Sobrescrever configurações do banco para Supabase
putenv('DB_CONNECTION=pgsql');
putenv('DB_HOST=aws-1-sa-east-1.pooler.supabase.com');
putenv('DB_PORT=5432');
putenv('DB_DATABASE=postgres');
putenv('DB_USERNAME=postgres.awvixkmlmzgeiftxwfsi');

// Verificar se a senha foi fornecida
if (!getenv('DB_PASSWORD')) {
    echo "ERRO: DB_PASSWORD não configurado.\n";
    echo "Execute: DB_PASSWORD=sua_senha php mark_withdrawal_paid_production.php\n";
    exit(1);
}

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Forçar configuração do banco
config(['database.connections.pgsql.host' => 'aws-1-sa-east-1.pooler.supabase.com']);
config(['database.connections.pgsql.port' => 5432]);
config(['database.connections.pgsql.database' => 'postgres']);
config(['database.connections.pgsql.username' => 'postgres.awvixkmlmzgeiftxwfsi']);
config(['database.connections.pgsql.password' => getenv('DB_PASSWORD')]);

echo "Conectando ao Supabase...\n\n";

try {
    // Testar conexão
    DB::connection()->getPdo();
    echo "✓ Conectado ao Supabase com sucesso!\n\n";
} catch (\Exception $e) {
    echo "✗ Erro ao conectar ao Supabase: " . $e->getMessage() . "\n";
    exit(1);
}

// IDs fornecidos
$withdrawalId = '09771d42-26a2-4d17-987b-32969979372b';
$accountId = '20d4d539-9012-428e-9bc4-5fd2a33c9632';

echo "Buscando saque ID: {$withdrawalId}\n\n";

$withdrawal = \App\Models\Withdrawal::find($withdrawalId);

if (!$withdrawal) {
    echo "✗ Saque não encontrado.\n";
    echo "Listando saques recentes...\n\n";
    
    $recentWithdrawals = \App\Models\Withdrawal::orderBy('created_at', 'desc')->limit(10)->get();
    
    if ($recentWithdrawals->isEmpty()) {
        echo "Nenhum saque encontrado no banco.\n";
        exit(1);
    }
    
    foreach ($recentWithdrawals as $w) {
        echo "ID: {$w->id}\n";
        echo "Conta: {$w->account_id}\n";
        echo "Valor: R$ " . number_format($w->amount / 100, 2, ',', '.') . "\n";
        echo "Status: {$w->status}\n";
        echo "Data: {$w->created_at->format('d/m/Y H:i')}\n";
        echo str_repeat('-', 60) . "\n\n";
    }
    exit(1);
}

echo str_repeat('=', 60) . "\n";
echo "SAQUE ENCONTRADO:\n";
echo str_repeat('=', 60) . "\n";
echo "ID: {$withdrawal->id}\n";
echo "Conta: {$withdrawal->account_id}\n";
echo "Valor: R$ " . number_format($withdrawal->amount / 100, 2, ',', '.') . "\n";
echo "Taxa: R$ " . number_format(($withdrawal->fee ?? 0) / 100, 2, ',', '.') . "\n";
echo "Valor Líquido: R$ " . number_format($withdrawal->net_amount / 100, 2, ',', '.') . "\n";
echo "Status Atual: {$withdrawal->status}\n";
echo "Método: {$withdrawal->method}\n";
echo "Criado em: {$withdrawal->created_at->format('d/m/Y H:i:s')}\n";
if ($withdrawal->failure_reason) {
    echo "Motivo Falha: {$withdrawal->failure_reason}\n";
}
echo str_repeat('=', 60) . "\n\n";

// Buscar carteira
$wallet = \App\Models\AccountWallet::where('account_id', $withdrawal->account_id)->first();

if (!$wallet) {
    echo "✗ Carteira não encontrada para a conta {$withdrawal->account_id}\n";
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
    
    // Lógica baseada no status atual
    if ($withdrawal->status === 'failed') {
        // Quando falha, o dinheiro volta para available_balance
        // Deduzimos do available_balance
        $wallet->decrement('available_balance', $amountCents);
        echo "✓ Deduzido R$ " . number_format($amountCents / 100, 2, ',', '.') . " do saldo disponível\n";
    } elseif ($withdrawal->status === 'pending' || $withdrawal->status === 'processing') {
        // Se está pending/processing, deduz do pending
        $wallet->decrement('pending_balance', $amountCents);
        echo "✓ Deduzido R$ " . number_format($amountCents / 100, 2, ',', '.') . " do saldo pendente\n";
    } else {
        echo "⚠ Status '{$withdrawal->status}' - deduzindo do saldo disponível\n";
        $wallet->decrement('available_balance', $amountCents);
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
    echo "Status atualizado para: completed\n";
    echo "Valor descontado: R$ " . number_format($amountCents / 100, 2, ',', '.') . "\n\n";
    
    echo "NOVO SALDO DA CARTEIRA:\n";
    echo "Saldo Disponível: R$ " . number_format($wallet->available_balance / 100, 2, ',', '.') . "\n";
    echo "Saldo Pendente: R$ " . number_format($wallet->pending_balance / 100, 2, ',', '.') . "\n";
    echo str_repeat('=', 60) . "\n";
    
    \Log::info('Saque marcado como pago manualmente (Supabase)', [
        'withdrawal_id' => $withdrawal->id,
        'account_id' => $withdrawal->account_id,
        'amount' => $amountCents,
        'previous_status' => $withdrawal->getOriginal('status'),
        'executed_at' => now(),
    ]);

} catch (\Exception $e) {
    DB::rollBack();
    echo "\n✗ ERRO AO PROCESSAR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
