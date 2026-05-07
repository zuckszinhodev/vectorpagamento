<?php

require __DIR__.'/vendor/autoload.php';

// Configurações do Supabase (da sua VPS)
$host = 'aws-1-sa-east-1.pooler.supabase.com';
$port = 5432;
$database = 'postgres';
$username = 'postgres.awvixkmlmzgeiftxwfsi';
$password = 'IVCCQPAMprudVLGc';

echo "Conectando ao Supabase...\n";

try {
    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$database};sslmode=require",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    
    echo "✓ Conectado ao Supabase!\n\n";
} catch (PDOException $e) {
    echo "✗ Erro ao conectar: " . $e->getMessage() . "\n";
    exit(1);
}

$withdrawalId = '09771d42-26a2-4d17-987b-32969979372b';

// Buscar o saque
$stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ?");
$stmt->execute([$withdrawalId]);
$withdrawal = $stmt->fetch();

if (!$withdrawal) {
    echo "✗ Saque não encontrado\n";
    exit(1);
}

echo "SAQUE ENCONTRADO:\n";
echo "ID: {$withdrawal['id']}\n";
echo "Conta: {$withdrawal['account_id']}\n";
echo "Valor: R$ " . number_format($withdrawal['amount'] / 100, 2, ',', '.') . "\n";
echo "Status: {$withdrawal['status']}\n";
echo "Motivo falha: {$withdrawal['failure_reason']}\n";
echo str_repeat('=', 60) . "\n\n";

// Buscar carteira
$stmt = $pdo->prepare("SELECT * FROM account_wallets WHERE account_id = ?");
$stmt->execute([$withdrawal['account_id']]);
$wallet = $stmt->fetch();

if (!$wallet) {
    echo "✗ Carteira não encontrada\n";
    exit(1);
}

echo "SALDO ATUAL:\n";
echo "Disponível: R$ " . number_format($wallet['available_balance'] / 100, 2, ',', '.') . "\n";
echo "Pendente: R$ " . number_format($wallet['pending_balance'] / 100, 2, ',', '.') . "\n";
echo str_repeat('=', 60) . "\n\n";

echo "PROCESSANDO...\n";

try {
    $pdo->beginTransaction();
    
    $amount = (int) $withdrawal['amount'];
    
    // Como o saque falhou, o dinheiro está no saldo disponível
    // Vamos deduzir do saldo disponível
    $newAvailableBalance = $wallet['available_balance'] - $amount;
    
    // Atualizar carteira
    $stmt = $pdo->prepare("UPDATE account_wallets SET available_balance = ? WHERE account_id = ?");
    $stmt->execute([$newAvailableBalance, $withdrawal['account_id']]);
    
    // Marcar saque como pago
    $stmt = $pdo->prepare("
        UPDATE withdrawals 
        SET status = 'completed', 
            processed_at = NOW(), 
            notes = 'Marcado como pago manualmente via script administrativo',
            failure_reason = NULL,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$withdrawalId]);
    
    $pdo->commit();
    
    echo "\n";
    echo str_repeat('=', 60) . "\n";
    echo "✓ OPERAÇÃO CONCLUÍDA COM SUCESSO!\n";
    echo str_repeat('=', 60) . "\n\n";
    
    echo "Saque ID: {$withdrawalId}\n";
    echo "Status: failed → completed\n";
    echo "Valor descontado: R$ " . number_format($amount / 100, 2, ',', '.') . "\n\n";
    
    echo "NOVO SALDO:\n";
    echo "Disponível: R$ " . number_format($newAvailableBalance / 100, 2, ',', '.') . "\n";
    echo "Pendente: R$ " . number_format($wallet['pending_balance'] / 100, 2, ',', '.') . "\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n✗ ERRO: " . $e->getMessage() . "\n";
    exit(1);
}