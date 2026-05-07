# Instruções para Marcar Saque como Pago no Supabase

## Informações do Saque
- **ID do Saque**: `09771d42-26a2-4d17-987b-32969979372b`
- **ID da Conta**: `20d4d539-9012-428e-9bc4-5fd2a33c9632`
- **Valor**: R$ 881,00
- **Data**: 07/05/2026, 12:19
- **Status**: Falhou (failed)

## Como Executar

### Opção 1: No servidor de produção (VPS)

```bash
cd /caminho/para/backend

# Execute com a senha do banco
DB_PASSWORD=sua_senha_supabase php mark_withdrawal_paid_production.php
```

### Opção 2: Usando variáveis de ambiente

```bash
cd /caminho/para/backend

# Configure a senha
export DB_PASSWORD=sua_senha_supabase

# Execute o script
php mark_withdrawal_paid_production.php
```

### Opção 3: Usando o comando Artisan (se estiver no servidor)

```bash
cd /caminho/para/backend

# Se o .env já estiver configurado com Supabase
php artisan withdrawal:mark-paid 09771d42-26a2-4d17-987b-32969979372b 20d4d539-9012-428e-9bc4-5fd2a33c9632
```

## O que o script faz

1. ✓ Conecta ao banco Supabase
2. ✓ Busca o saque pelo ID
3. ✓ Verifica o status atual do saque
4. ✓ Mostra informações do saque e saldo da carteira
5. ✓ Deduz o valor do saldo (disponível ou pendente, dependendo do status)
6. ✓ Marca o saque como "completed" (pago)
7. ✓ Registra a operação nos logs

## Lógica de Dedução

- **Se o saque está "failed"**: Deduz do **saldo disponível** (pois quando falha, o dinheiro volta para lá)
- **Se o saque está "pending" ou "processing"**: Deduz do **saldo pendente**
- **Outros status**: Deduz do **saldo disponível** por segurança

## Conexão Supabase

```
Host: aws-1-sa-east-1.pooler.supabase.com
Port: 5432
Database: postgres
Username: postgres.awvixkmlmzgeiftxwfsi
Password: [necessário fornecer]
```

## Segurança

- ✓ Usa transação de banco de dados (rollback em caso de erro)
- ✓ Registra todas as operações nos logs
- ✓ Valida se o saque existe antes de processar
- ✓ Verifica se a carteira existe

## Troubleshooting

### Erro: "DB_PASSWORD não configurado"
- Forneça a senha do Supabase ao executar o script

### Erro: "Saque não encontrado"
- Verifique se o ID do saque está correto no banco Supabase
- O script listará os saques recentes para você identificar o correto

### Erro: "Carteira não encontrada"
- A conta precisa ter uma carteira (account_wallets)
- Verifique no Supabase se existe registro na tabela account_wallets

## Verificação Pós-Execução

Após executar, verifique no Supabase:

```sql
-- Verificar status do saque
SELECT id, account_id, amount, status, processed_at, notes
FROM withdrawals
WHERE id = '09771d42-26a2-4d17-987b-32969979372b';

-- Verificar saldo da carteira
SELECT account_id, available_balance, pending_balance
FROM account_wallets
WHERE account_id = '20d4d539-9012-428e-9bc4-5fd2a33c9632';
```
