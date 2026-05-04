# GatePro - Test Suite

Suíte completa de testes automatizados para o GatePro Gateway.

## 📊 Cobertura de Testes

### Feature Tests (Testes de Integração/API)
- ✅ **AuthTest** - Autenticação (registro, login, logout)
- ✅ **ApiKeyTest** - Gestão de API Keys
- ✅ **ProductTest** - CRUD de produtos
- ✅ **AffiliateTest** - Sistema de afiliados
- ✅ **CheckoutTest** - Checkouts customizáveis
- ✅ **CouponTest** - Sistema de cupons
- ✅ **WithdrawalTest** - Saques e retiradas
- ✅ **KycTest** - Verificação KYC
- ✅ **MemberAreaTest** - Área de membros/cursos
- ✅ **PublicCheckoutTest** - Checkout público
- ✅ **DomainTest** - Domínios customizados
- ✅ **NotificationTest** - Sistema de notificações
- ✅ **ChargeTest** - Gestão de cobranças
- ✅ **HealthCheckTest** - Health check da API

### Unit Tests (Testes Unitários)
- ✅ **CircuitBreakerTest** - Circuit breaker pattern
- ✅ **AcquirerFactoryTest** - Factory de adquirentes
- ✅ **FraudDetectionTest** - Detecção de fraude
- ✅ **SplitServiceTest** - Cálculo de comissões
- ✅ **WebhookDispatchTest** - Dispatch de webhooks
- ✅ **PaymentServiceTest** - Serviço de pagamentos
- ✅ **CheckoutRendererTest** - Renderização de checkout

## 🚀 Como Executar

### Executar todos os testes
```bash
cd backend
php artisan test
```

### Executar apenas Feature Tests
```bash
php artisan test --testsuite=Feature
```

### Executar apenas Unit Tests
```bash
php artisan test --testsuite=Unit
```

### Executar um arquivo específico
```bash
php artisan test tests/Feature/AuthTest.php
```

### Executar com cobertura de código
```bash
php artisan test --coverage
```

### Executar em modo paralelo (mais rápido)
```bash
php artisan test --parallel
```

## 🔌 Integração Real (Manual no GitHub Actions)

Existe um workflow dedicado para testes reais de integração:

- [.github/workflows/backend-real-integration.yml](../../.github/workflows/backend-real-integration.yml)

Esse workflow:

1. Roda apenas manualmente (`workflow_dispatch`), sem impactar o CI padrão.
2. Permite selecionar a suíte (`all`, `external-pix`, `internal-real-pix`, `turbos`).
3. Falha cedo se segredos obrigatórios estiverem ausentes.
4. Falha se a suíte selecionada terminar 100% em skipped (guardrail anti-falso-positivo).
5. Publica resumo final no `GITHUB_STEP_SUMMARY` com Passed/Skipped/Failed por suíte.

### Secrets esperados

- `EXTERNAL_PIX_API_KEY`
- `PAYFORT_API_KEY`
- `PAYFORT_API_SECRET`
- `TURBOS_PUBLIC_KEY`
- `TURBOS_SECRET_KEY`
- `TURBOS_WITHDRAW_KEY` (opcional)

### Variables opcionais

- `EXTERNAL_PIX_API_BASE_URL`
- `PAYFORT_BASE_URL`
- `TURBOS_BASE_URL`

### Como disparar

1. GitHub > Actions > Backend Real Integration Tests.
2. Clique em Run workflow.
3. Escolha a suite desejada.
4. Verifique o resumo no fim da execução (tabela por suíte).

### Execução local equivalente (sem GitHub Actions)

```bash
cd backend

# External PIX real
RUN_EXTERNAL_PIX_TESTS=true \
EXTERNAL_PIX_API_KEY=... \
vendor/bin/pest tests/Feature/ExternalPixApiTest.php --colors=never

# Internal real PIX
RUN_REAL_PIX_TESTS=true \
PAYFORT_ENABLED=true \
PAYFORT_API_KEY=... \
PAYFORT_API_SECRET=... \
vendor/bin/pest tests/Feature/PixGenerationTest.php --colors=never

# Turbos real
TURBOS_ENABLED=true \
TURBOS_PUBLIC_KEY=... \
TURBOS_SECRET_KEY=... \
vendor/bin/pest tests/Feature/TurbosCashIntegrationTest.php --colors=never
```

## 📈 Load Testing (k6)

There is now a versioned k6 suite for critical production-readiness flows.

- [docs/testing/LOAD_TESTING.md](../../docs/testing/LOAD_TESTING.md)
- [load-tests/k6/charge-load.js](../../load-tests/k6/charge-load.js)
- [load-tests/k6/charge-idempotency.js](../../load-tests/k6/charge-idempotency.js)
- [load-tests/k6/public-checkout-load.js](../../load-tests/k6/public-checkout-load.js)
- [load-tests/k6/withdrawal-burst.js](../../load-tests/k6/withdrawal-burst.js)
- [.github/workflows/backend-load-tests.yml](../../.github/workflows/backend-load-tests.yml)

The k6 suite is manual by design and should be run only against homologation or a controlled production-like environment.

## 🚦 Go-Live Gate (Operação Real)

Para liberação final de produção, use o gate manual:

- [.github/workflows/go-live-readiness.yml](../../.github/workflows/go-live-readiness.yml)

Runbook operacional completo:

- [docs/operations/GO_LIVE_RUNBOOK.md](../../docs/operations/GO_LIVE_RUNBOOK.md)

Esse gate exige evidências dos workflows de integração real e carga, além de confirmação explícita de reconciliação, rollback e prontidão de incidentes.

## 📝 Estrutura dos Testes

```
tests/
├── Feature/              # Testes de integração (API endpoints)
│   ├── AuthTest.php
│   ├── ApiKeyTest.php
│   ├── ProductTest.php
│   ├── AffiliateTest.php
│   ├── CheckoutTest.php
│   ├── CouponTest.php
│   ├── WithdrawalTest.php
│   ├── KycTest.php
│   ├── MemberAreaTest.php
│   ├── PublicCheckoutTest.php
│   ├── DomainTest.php
│   ├── NotificationTest.php
│   ├── ChargeTest.php
│   └── HealthCheckTest.php
│
├── Unit/                 # Testes unitários (lógica de negócio)
│   ├── CircuitBreakerTest.php
│   ├── AcquirerFactoryTest.php
│   ├── FraudDetectionTest.php
│   ├── SplitServiceTest.php
│   ├── WebhookDispatchTest.php
│   ├── PaymentServiceTest.php
│   └── CheckoutRendererTest.php
│
├── Helpers/              # Helpers para testes
│   └── TestHelpers.php
│
├── CreatesApplication.php
├── TestCase.php
├── Pest.php
└── README.md
```

## 🛠️ Configuração

Os testes usam:
- **SQLite in-memory** para banco de dados (rápido e isolado)
- **Array driver** para cache e sessões
- **Sync queue** para jobs (execução imediata)
- **Array mailer** para emails (não envia realmente)

Configuração em `phpunit.xml`:
```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
<env name="CACHE_DRIVER" value="array"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="SESSION_DRIVER" value="array"/>
<env name="MAIL_MAILER" value="array"/>
```

## 📚 Padrões de Teste

### Feature Test Example
```php
it('creates a product', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/products', [
            'name'  => 'Test Product',
            'price' => 10000,
            'type'  => 'digital',
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['success', 'data' => ['id', 'name']]);

    $this->assertDatabaseHas('products', ['name' => 'Test Product']);
});
```

### Unit Test Example
```php
it('calculates commission correctly', function () {
    $service = app(SplitService::class);
    
    $commission = $service->calculateCommission(10000, 10);
    
    expect($commission)->toBe(1000);
});
```

## 🔧 Helpers Disponíveis

```php
use Tests\Helpers\TestHelpers;

// Criar conta de teste
$account = TestHelpers::createAccount();

// Criar usuário de teste
$user = TestHelpers::createUser($account);

// Criar usuário autenticado (retorna account, user, token)
$auth = TestHelpers::createAuthenticatedUser();

// Gerar header de autorização
$headers = TestHelpers::authHeader($token);

// Formatar valores monetários
$formatted = TestHelpers::formatMoney(10000); // "R$ 100,00"
$cents = TestHelpers::parseMoney("R$ 100,00"); // 10000
```

## 🎯 Próximos Passos

### Testes a Adicionar
- [ ] N8nIntegrationTest
- [ ] UtmifyIntegrationTest
- [ ] LandingPageTest
- [ ] ReconciliationTest
- [ ] ChargebackTest
- [ ] ReportTest
- [ ] AnalyticsTest
- [ ] WebhookTest (unified webhook handler)
- [ ] AdminTest (admin endpoints)

### Melhorias
- [ ] Aumentar cobertura para 80%+
- [ ] Adicionar testes de performance
- [ ] Adicionar testes de segurança
- [ ] Configurar CI/CD para rodar testes automaticamente
- [ ] Adicionar mutation testing

## 📊 Executar com Relatório

```bash
# Gerar relatório HTML de cobertura
php artisan test --coverage-html coverage

# Ver relatório
open coverage/index.html
```

## 🐛 Debug de Testes

```bash
# Executar com output detalhado
php artisan test --verbose

# Parar no primeiro erro
php artisan test --stop-on-failure

# Executar apenas testes que falharam
php artisan test --retry
```

## 💡 Dicas

1. **Use RefreshDatabase** em todos os Feature Tests para garantir isolamento
2. **Limpe o cache** antes de Unit Tests que usam cache
3. **Mock serviços externos** (HTTP, APIs de adquirentes)
4. **Use factories** para criar dados de teste consistentes
5. **Teste casos de erro** além dos casos de sucesso
6. **Valide estrutura JSON** além de status codes

## 📞 Suporte

Para dúvidas sobre os testes, consulte:
- [Documentação do Pest](https://pestphp.com)
- [Laravel Testing](https://laravel.com/docs/testing)
- [PHPUnit](https://phpunit.de)
