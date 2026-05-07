# Comando para Marcar Saque como Pago Manualmente

## Descrição
Este comando permite marcar um saque como pago e deduzir o valor da conta sem realmente processar o saque através da adquirente. Útil para ajustes administrativos no dashboard.

## Como Usar

### Sintaxe
```bash
php artisan withdrawal:mark-paid {withdrawal_id} {account_id}
```

### Parâmetros
- `withdrawal_id`: ID do saque que deseja marcar como pago
- `account_id`: ID da conta (para validação de segurança)

### Exemplo
```bash
php artisan withdrawal:mark-paid 7d76ffec-df82-4247-91d1-8c9c07ccad21 09771d42-26a2-4d17-987b-32969979372b
```

## O que o comando faz

1. **Busca o saque** pelo ID fornecido
2. **Valida** se o saque pertence à conta especificada
3. **Mostra informações** do saque e saldo atual
4. **Solicita confirmação** antes de executar
5. **Marca o saque como completado** (status = 'completed')
6. **Deduz o valor** do saldo pendente da carteira
7. **Registra a operação** nos logs do sistema

## Segurança

- ✓ Requer confirmação antes de executar
- ✓ Valida se o saque pertence à conta informada
- ✓ Usa transação de banco de dados (rollback em caso de erro)
- ✓ Registra todas as operações nos logs
- ✓ Usa lock na carteira para evitar condições de corrida

## Observações Importantes

- O valor é deduzido do **saldo pendente**, não do saldo disponível
- O saque é marcado com a nota: "Marcado como pago manualmente via comando administrativo"
- A operação é registrada nos logs do Laravel para auditoria
- Não há comunicação com a adquirente (PayFort, Enki, etc.)

## Logs

Todas as operações são registradas em:
- `storage/logs/laravel.log` (sucesso e erros)

Exemplo de log:
```
[2026-05-07 10:30:00] local.INFO: Saque marcado como pago manualmente {"withdrawal_id":"...","account_id":"...","amount":10000}
```

## Troubleshooting

### Erro: "Saque não encontrado"
- Verifique se o ID do saque está correto
- Use: `php artisan tinker` e execute `\App\Models\Withdrawal::find('id')`

### Erro: "O saque não pertence à conta"
- Confirme o account_id correto do saque
- Use: `\App\Models\Withdrawal::find('id')->account_id`

### Erro: "Carteira não encontrada"
- A conta precisa ter uma carteira (AccountWallet)
- Verifique: `\App\Models\AccountWallet::where('account_id', 'id')->first()`
