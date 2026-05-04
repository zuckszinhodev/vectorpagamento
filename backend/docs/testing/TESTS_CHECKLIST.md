# ✅ Checklist de Validação dos Testes

## 📋 Antes de Executar

- [ ] Composer instalado (`composer --version`)
- [ ] PHP 8.2+ instalado (`php --version`)
- [ ] Extensões PHP necessárias:
  - [ ] mbstring
  - [ ] xml
  - [ ] ctype
  - [ ] json
  - [ ] bcmath
  - [ ] pdo
  - [ ] sqlite3
  - [ ] pdo_sqlite

## 🚀 Instalação

```bash
cd backend
composer install
```

## ✅ Validação Rápida

### 1. Verificar Estrutura de Testes
```bash
# Windows
dir tests\Feature\*.php
dir tests\Unit\*.php

# Linux/Mac
ls -la tests/Feature/*.php
ls -la tests/Unit/*.php
```

**Esperado:**
- 15 arquivos em `tests/Feature/`
- 7 arquivos em `tests/Unit/`

### 2. Executar Teste Simples
```bash
php artisan test tests/Feature/HealthCheckTest.php
```

**Esperado:** ✅ Todos os testes passam

### 3. Executar Unit Tests
```bash
php artisan test --testsuite=Unit
```

**Esperado:** ~40-50 testes passam

### 4. Executar Feature Tests
```bash
php artisan test --testsuite=Feature
```

**Esperado:** ~100+ testes passam

### 5. Executar Todos os Testes
```bash
php artisan test
```

**Esperado:** ~150+ testes passam

## 🔍 Checklist de Testes por Módulo

### Autenticação & Segurança
- [ ] AuthTest - 10 testes
  - [ ] Registro de usuário
  - [ ] Login válido/inválido
  - [ ] Validações de campos
- [ ] ApiKeyTest - 10 testes
  - [ ] CRUD de API keys
  - [ ] Rotação de keys
  - [ ] Validação de permissões
- [ ] KycTest - 6 testes
  - [ ] Submissão de KYC
  - [ ] Aprovação/rejeição

### Pagamentos & Financeiro
- [ ] ChargeTest - (existente)
- [ ] WithdrawalTest - 7 testes
  - [ ] Criação de saques
  - [ ] Validação de saldo
  - [ ] Requisito de KYC
- [ ] CouponTest - 9 testes
  - [ ] Cupons percentuais/fixos
  - [ ] Validação pública
  - [ ] Expiração
- [ ] PaymentServiceTest - 3 testes
  - [ ] Validações de pagamento

### Produtos & Checkout
- [ ] ProductTest - 9 testes
  - [ ] CRUD completo
  - [ ] Validações
  - [ ] Isolamento multi-tenant
- [ ] CheckoutTest - 7 testes
  - [ ] CRUD de checkouts
  - [ ] Clonagem
  - [ ] Validação de slugs
- [ ] PublicCheckoutTest - 6 testes
  - [ ] Carregamento público
  - [ ] Aplicação de cupons
  - [ ] Processamento PIX
- [ ] CheckoutRendererTest - 6 testes
  - [ ] Renderização de blocos
  - [ ] Escape de HTML

### Afiliados
- [ ] AffiliateTest - 7 testes
  - [ ] CRUD de afiliados
  - [ ] Validação de códigos
  - [ ] Taxa de comissão
- [ ] SplitServiceTest - 5 testes
  - [ ] Cálculo de comissões
  - [ ] Validações

### Área de Membros
- [ ] MemberAreaTest - 6 testes
  - [ ] Criação de áreas
  - [ ] Cursos e lições
  - [ ] Matrículas
  - [ ] Progresso

### Integrações
- [ ] N8nIntegrationTest - 6 testes
  - [ ] CRUD de integrações
  - [ ] Teste de conexão
- [ ] WebhookDispatchTest - 4 testes
  - [ ] Assinatura HMAC
  - [ ] Validação

### Infraestrutura
- [ ] DomainTest - 6 testes
  - [ ] CRUD de domínios
  - [ ] Validação DNS
- [ ] NotificationTest - 6 testes
  - [ ] Listagem
  - [ ] Marcar como lida
- [ ] HealthCheckTest - (existente)
- [ ] CircuitBreakerTest - 12 testes
  - [ ] Estados do circuit breaker
  - [ ] Threshold e cooldown

### Segurança
- [ ] FraudDetectionTest - 5 testes
  - [ ] Blacklist
  - [ ] Score de risco
- [ ] AcquirerFactoryTest - 5 testes
  - [ ] Factory pattern
  - [ ] Validações

## 🐛 Troubleshooting

### Erro: "Class not found"
```bash
composer dump-autoload
```

### Erro: "Database not found"
Verifique `phpunit.xml`:
```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

### Erro: "Too many open files"
```bash
# Linux/Mac
ulimit -n 4096
```

### Testes falhando aleatoriamente
```bash
php artisan cache:clear
php artisan config:clear
php artisan test --order-by=random
```

### Testes muito lentos
```bash
# Use execução paralela
php artisan test --parallel

# Ou execute apenas um testsuite
php artisan test --testsuite=Unit
```

## 📊 Métricas de Sucesso

### Mínimo Aceitável
- [ ] 80% dos testes passando
- [ ] Tempo de execução < 2 minutos
- [ ] Sem erros fatais

### Ideal
- [ ] 100% dos testes passando
- [ ] Tempo de execução < 1 minuto
- [ ] Cobertura > 70%

## 🎯 Próximos Passos Após Validação

1. [ ] Corrigir testes que falharam
2. [ ] Adicionar mocks para serviços externos
3. [ ] Configurar CI/CD (GitHub Actions)
4. [ ] Aumentar cobertura para 80%+
5. [ ] Adicionar testes de integração com adquirentes

## 📝 Relatório de Execução

Preencha após executar os testes:

**Data:** _______________

**Ambiente:**
- PHP Version: _______________
- OS: _______________
- Composer Version: _______________

**Resultados:**
- Total de Testes: _______________
- Testes Passaram: _______________
- Testes Falharam: _______________
- Tempo de Execução: _______________

**Problemas Encontrados:**
1. _______________________________________________
2. _______________________________________________
3. _______________________________________________

**Ações Necessárias:**
1. _______________________________________________
2. _______________________________________________
3. _______________________________________________

## ✅ Aprovação Final

- [ ] Todos os testes críticos passando
- [ ] Documentação revisada
- [ ] Scripts de execução funcionando
- [ ] CI/CD configurado (opcional)
- [ ] Equipe treinada

**Aprovado por:** _______________
**Data:** _______________
