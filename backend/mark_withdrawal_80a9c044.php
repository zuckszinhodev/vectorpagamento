<?php

$host = 'aws-1-sa-east-1.pooler.supabase.com';
$port = 5432;
$database = 'postgres';
$username = 'postgres.awvixkmlmzgeiftxwfsi';

// Pegar a senha do .env
$env = file_get_contents('.env');
preg_match('/DB_PASSWORD=(.+)/', $env, $matches);
$password = trim($matches[1]);

echo "Conectando ao Supabase...\n";

try {
    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$database};sslmode=require",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "✓ Conectado!\n\n";
    
    $withdrawalId = '80a9c044-af01-4585-af26-17d304446e31';
    
    // Buscar saque
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
    if ($withdrawal['failure_reason']) {
        echo "Motivo: {$withdrawal['failure_reason']}\n";
    }
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
    
    $pdo->beginTransaction();
    
    $amount = (int) $withdrawal['amount'];
    
    // Lógica baseada no status
    if ($withdrawal['status'] === 'failed') {
        // Se falhou, deduz do saldo disponível
        $newAvailableBalance = $wallet['available_balance'] - $amount;
        $stmt = $pdo->prepare("UPDATE account_wallets SET available_balance = ? WHERE account_id = ?");
        $stmt->execute([$newAvailableBalance, $withdrawal['account_id']]);
        echo "✓ Deduzido R$ " . number_format($amount / 100, 2, ',', '.') . " do saldo disponível\n";
    } else {
        // Se pending/processing, deduz do saldo pendente
        $newPendingBalance = $wallet['pending_balance'] - $amount;
        $stmt = $pdo->prepare("UPDATE account_wallets SET pending_balance = ? WHERE account_id = ?");
        $stmt->execute([$newPendingBalance, $withdrawal['account_id']]);
        echo "✓ Deduzido R$ " . number_format($amount / 100, 2, ',', '.') . " do saldo pendente\n";
    }
    
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
    
    // Buscar saldo atualizado
    $stmt = $pdo->prepare("SELECT * FROM account_wallets WHERE account_id = ?");
    $stmt->execute([$withdrawal['account_id']]);
    $updatedWallet = $stmt->fetch();
    
    echo "\n";
    echo str_repeat('=', 60) . "\n";
    echo "✓ OPERAÇÃO CONCLUÍDA COM SUCESSO!\n";
    echo str_repeat('=', 60) . "\n\n";
    
    echo "Saque ID: {$withdrawalId}\n";
    echo "Status: {$withdrawal['status']} → completed\n";
    echo "Valor descontado: R$ " . number_format($amount / 100, 2, ',', '.') . "\n\n";
    
    echo "NOVO SALDO:\n";
    echo "Disponível: R$ " . number_format($updatedWallet['available_balance'] / 100, 2, ',', '.') . "\n";
    echo "Pendente: R$ " . number_format($updatedWallet['pending_balance'] / 100, 2, ',', '.') . "\n";
    
} catch (Exception $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    echo "✗ ERRO: " . $e->getMessage() . "\n";
    exit(1);
}