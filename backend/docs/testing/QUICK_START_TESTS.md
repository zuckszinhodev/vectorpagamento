# ⚡ Quick Start - Testes GatePro

## 🚀 Início Rápido (5 minutos)

### 1️⃣ Instalar Dependências
```bash
cd backend
composer install
```

### 2️⃣ Executar Testes
```bash
# Opção 1: Script pronto (recomendado)
run-tests.bat

# Opção 2: Artisan direto
php artisan test
```

### 3️⃣ Ver Resultados
```
✓ Passou: ~150 testes
✗ Falhou: 0 testes
⏱ Tempo: ~30-60 segundos
```

## 📋 Comandos Essenciais

### Executar Todos os Testes
```bash
php artisan test
```

### Executar Apenas Unit Tests (mais rápido)
```bash
php artisan test --testsuite=Unit
```

### Executar Apenas Feature Tests
```bash
php artisan test --testsuite=Feature
```

### Executar Teste Específico
```bash
php artisan test tests/Feature/AuthTest.php
```

### Executar com Cobertura
```bash
php artisan test --coverage
```

### Executar em Paralelo (mais rápido)
```bash
php artisan test --parallel
```

## 🎯 Testes por Funcionalidade

### Testar Autenticação
```bash
php artisan test tests/Feature/AuthTest.php
```

### Testar Produtos
```bash
php artisan test tests/Feature/ProductTest.php
```

### Testar Pagamentos
```bash
php artisan test tests/Feature/ChargeTest.php
php artisan test tests/Feature/WithdrawalTest.php
```

### Testar Afiliados
```bash
php artisan test tests/Feature/AffiliateTest.php
```

### Testar Checkouts
```bash
php artisan test tests/Feature/CheckoutTest.php
php artisan test tests/Feature/PublicCheckoutTest.php
```

## 🔧 Troubleshooting Rápido

### Erro: "vendor/autoload.php not found"
```bash
composer install
```

### Erro: "Class not found"
```bash
composer dump-autoload
```

### Testes falhando
```bash
php artisan cache:clear
php artisan config:clear
php artisan test
```

### Testes muito lentos
```bash
php artisan test --parallel
```

## 📊 Verificar Cobertura

```bash
# Gerar relatório de cobertura
php artisan test --coverage

# Ver cobertura mínima
php artisan test --coverage --min=70
```

## 🎨 Opções Úteis

### Parar no Primeiro Erro
```bash
php artisan test --stop-on-failure
```

### Executar com Output Detalhado
```bash
php artisan test --verbose
```

### Executar Apenas Testes que Falharam
```bash
php artisan test --retry
```

### Executar em Ordem Aleatória
```bash
php artisan test --order-by=random
```

## 📝 Criar Novo Teste

### Feature Test
```bash
php artisan make:test NomeDoTest
```

### Unit Test
```bash
php artisan make:test NomeDoTest --unit
```

## 🎯 Fluxo de Trabalho Recomendado

### Antes de Commitar
```bash
# 1. Executar testes rápidos (Unit)
php artisan test --testsuite=Unit

# 2. Se passou, executar todos
php artisan test

# 3. Se passou, commitar
git add .
git commit -m "feat: nova funcionalidade"
```

### Antes de Deploy
```bash
# 1. Executar todos os testes
php artisan test

# 2. Verificar cobertura
php artisan test --coverage --min=70

# 3. Se passou, fazer deploy
```

### Durante Desenvolvimento
```bash
# Executar apenas o teste que você está trabalhando
php artisan test tests/Feature/MeuNovoTest.php

# Ou usar watch mode (requer package adicional)
php artisan test --watch
```

## 📚 Recursos Adicionais

### Documentação Completa
- `tests/README.md` - Documentação detalhada
- `TESTING_GUIDE.md` - Guia completo
- `TESTS_CHECKLIST.md` - Checklist de validação
- `TESTS_STATISTICS.md` - Estatísticas

### Scripts Úteis
- `run-tests.bat` - Windows
- `run-tests.sh` - Linux/Mac

### Helpers
- `tests/Helpers/TestHelpers.php` - Funções reutilizáveis

## 🎉 Pronto!

Agora você está pronto para executar e criar testes no GatePro Gateway!

**Dúvidas?** Consulte a documentação completa em `tests/README.md`

**Problemas?** Veja o troubleshooting em `TESTS_CHECKLIST.md`

---

**Tempo total de setup:** ~5 minutos
**Tempo de execução dos testes:** ~30-60 segundos
**Cobertura atual:** ~70%
**Total de testes:** ~150+
