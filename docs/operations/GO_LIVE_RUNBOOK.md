# Go-Live Runbook (Operacao Real)

Este runbook define os gates minimos para liberar deploy em ambiente real com rastreabilidade.

## 1. Objetivo

Liberar operacao real somente quando houver evidencia tecnica e operacional de estabilidade, seguranca e rollback.

## 2. Artefatos obrigatorios

1. Evidencia de integracao real:
   1. Workflow: .github/workflows/backend-real-integration.yml
   2. Resultado esperado: sem falha e sem 100% skipped na suite selecionada.
2. Evidencia de carga:
   1. Workflow: .github/workflows/backend-load-tests.yml
   2. Resultado esperado: taxas e latencias dentro dos limites aprovados.
3. Gate final de go-live:
   1. Workflow: .github/workflows/go-live-readiness.yml
   2. Resultado esperado: aprovado com links das evidencias acima.

## 3. Criterios minimos de aprovacao

1. Baseline local/CI: suite backend verde.
2. Integracao real: aprovada para provedores ativos em producao.
3. Carga (k6):
   1. http_req_failed < 2% em charge/checkout.
   2. p95 < 1500ms e p99 < 3000ms em charge/checkout.
   3. Idempotencia retornando unica cobranca por chave duplicada.
4. Reconciliacao financeira: aprovada por responsavel.
5. Rollback: plano pronto e validado.
6. On-call: contatos e janelas de resposta confirmadas.

## 4. Sequencia de execucao (pre-go-live)

1. Executar Backend Real Integration Tests (manual) com secrets reais.
2. Executar Backend Load Tests (manual) contra homologacao/ambiente controlado.
3. Validar metricas e registrar links das execucoes.
4. Executar Go Live Readiness Gate e preencher:
   1. tag de release,
   2. link de real integration,
   3. link de load tests,
   4. status de reconciliacao,
   5. readiness de rollback,
   6. readiness de incidentes.

## 5. Janela de cutover

1. Congelar alteracoes nao essenciais.
2. Garantir disponibilidade de on-call (engenharia + operacoes + financeiro).
3. Aplicar deploy da release aprovada.
4. Executar smoke checks imediatos:
   1. auth/login,
   2. criacao de charge,
   3. checkout publico,
   4. webhook de confirmacao,
   5. saque (conta de teste controlada).
5. Monitorar por pelo menos 60 minutos:
   1. taxa de erro,
   2. latencia,
   3. filas,
   4. confirmacoes de pagamento,
   5. eventos de webhook.

## 6. Rollback

Acionar rollback imediato se qualquer condicao abaixo ocorrer:

1. aumento sustentado de erro 5xx acima do limite operacional,
2. quebra de idempotencia em cobrancas,
3. inconsistencias de reconciliacao,
4. falha sistemica de webhook sem mitigacao rapida.

Passos:

1. Reverter para ultima release estavel.
2. Pausar novos deploys.
3. Comunicar stakeholders.
4. Abrir postmortem com linha do tempo e acao corretiva.

## 7. Pos-go-live (D+1)

1. Revisar indicadores de 24h (erro, latencia, conversao, chargeback inicial).
2. Confirmar reconciliacao D+1.
3. Atualizar backlog com ajustes encontrados.
