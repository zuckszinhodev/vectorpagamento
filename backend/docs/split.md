# Split de pagamentos

Como o valor de uma cobrança é dividido entre contas do sistema.

## Regra de ouro

**O split redistribui dinheiro que já entrou. Nunca cria saldo novo.**

Para toda cobrança paga vale:

```
soma(parcelas dos recebedores) + parcela do produtor == valor_bruto - taxa
```

`SplitResult::isBalanced()` verifica isso, e `persistSplits()` recusa gravar
qualquer rateio que não feche.

## Fluxo do dinheiro

1. Cobrança aprovada → `PaymentService::updateWalletOnPayment()` credita o
   **líquido inteiro** na carteira do produtor.
2. `SplitService::createSplitsForCharge()` calcula as parcelas e grava os
   registros em `splits` (status `pending`).
3. `processSplit()` **transfere**: debita da carteira do produtor e credita a do
   recebedor.

O passo 3 é uma transferência, não um crédito novo. A parcela do produtor não
movimenta nada — ele já recebeu tudo no passo 1, e o que sai para os outros é
descontado dele.

## Modos de taxa

Controlado por `charge_fee_to_producer` na regra.

| Modo | `charge_fee_to_producer` | Base do percentual | Quem paga a taxa |
|---|---|---|---|
| `FEE_PRODUCER` (padrão) | `true` | valor **bruto** | só o produtor |
| `FEE_PROPORTIONAL` | `false` | valor **líquido** | todos, proporcional |

Exemplo — cobrança de R$ 100,00, taxa de R$ 5,00, parceiro com 30%:

| Modo | Parceiro | Produtor | Total |
|---|---|---|---|
| `FEE_PRODUCER` | R$ 30,00 | R$ 65,00 | R$ 95,00 |
| `FEE_PROPORTIONAL` | R$ 28,50 | R$ 66,50 | R$ 95,00 |

## Destinatários

Todo destinatário é validado por `RecipientValidator` **antes** de a regra ser
salva e antes de a cobrança com split inline ser criada.

- `account` — precisa existir em `accounts` e não estar `blocked`/`suspended`.
- `affiliate` — precisa existir e ter conta de pagamento resolvível.

A conta de pagamento de um afiliado vem de `Affiliate::payoutAccountId()`:
primeiro `metadata.affiliate_account_id`, senão `user_id → users.account_id`.

> `affiliates.account_id` é a conta do **produtor** dono do programa, não a do
> afiliado. Creditar nela devolveria a comissão para quem vendeu.

## Limites do cálculo

- Percentuais somando mais de 100% são **travados no disponível** — os últimos
  recebedores recebem menos ou nada, mas nunca se distribui além do líquido.
- Valores fixos maiores que o restante são truncados pelo mesmo motivo.
- A API e o painel rejeitam somas acima de 100% na entrada; a trava do
  calculador é a segunda linha de defesa.

## Estados de um split

| Status | Significado |
|---|---|
| `pending` | Aguardando processamento (ou adiado por saldo). |
| `processing` | Transferência em andamento. |
| `transferred` | Valor creditado no recebedor. |
| `failed` | Erro definitivo — exige intervenção. |

**Saldo insuficiente não é `failed`.** Se parte do líquido do produtor está
retida (rolling reserve) ou já foi sacada, o split lança
`InsufficientProducerBalanceException` e **continua `pending`**. O job
`ProcessPendingSplitsJob` roda a cada 5 minutos e tenta de novo.

Destinatário inexistente, por outro lado, é `failed`: nenhum valor se move e o
caso precisa ser corrigido à mão.

## Idempotência

- `createSplitsForCharge()` devolve os splits já existentes da transação em vez
  de criar de novo.
- `processSplit()` relê o split sob `lockForUpdate()` e só age se ainda estiver
  `pending`, então webhook duplicado ou corrida entre job e auto-process não
  credita duas vezes.

## Split inline via API

Enviado em `split_rules` na criação da cobrança:

```json
{
  "amount": 10000,
  "split_rules": [
    {
      "recipient_id": "uuid-da-conta",
      "recipient_type": "account",
      "type": "percentage",
      "percentage": 30
    }
  ]
}
```

Aceita `type: "percentage"` (campo `percentage`) ou `type: "fixed"` / `"flat"`
(campo `amount`, em centavos). `charge_processing_fee: true` em qualquer item
muda o rateio inteiro para `FEE_PROPORTIONAL`.

`CreateChargeRequest` valida a soma dos percentuais, a soma dos fixos contra o
valor da cobrança, e a existência de cada destinatário.

## Onde mexer

| Arquivo | Responsabilidade |
|---|---|
| `Services/Split/SplitCalculator.php` | Cálculo puro do rateio. Sem banco. |
| `Services/Split/SplitResult.php` | Resultado + verificação da invariante. |
| `Services/Split/RecipientValidator.php` | Destinatário existe e pode receber? |
| `Services/SplitService.php` | Regras, persistência, transferência. |

O calculador é a única fonte de verdade: criação real e simulação passam por
ele, então o preview nunca diverge do que acontece de fato.
