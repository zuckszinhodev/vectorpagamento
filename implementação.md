PARTE 1: DIAGNÓSTICO DO SISTEMA ATUAL
1.1 PROBLEMAS CRÍTICOS ENCONTRADOS
Backend — Segurança
#	Problema	Severidade	Local
1	Webhook signature verification é opcional — quando PAYFORT_WEBHOOK_SECRET está vazio, aceita webhooks sem validação	CRÍTICO	PayFortWebhookHandler
2	VerifyRequestSignature middleware permite requests sem assinatura (modo "gradual rollout")	CRÍTICO	Middleware
3	SSL verification desabilitada em APP_ENV=local — se vazar para produção, MITM em todas as chamadas	ALTO	PayFortAcquirer
4	Rate limiter usa banco de dados ao invés de Redis — auto-DoS sob carga	ALTO	RateLimitApi
5	API key hash sem pepper — brute-force offline viável se DB for comprometido	ALTO	AuthApiKey
6	IP spoofing via X-Forwarded-For sem TrustProxies configurado	MÉDIO	RateLimitApi
7	Headers sensíveis logados em webhooks	MÉDIO	PayFortWebhookHandler
Backend — Race Conditions
#	Cenário	Risco
1	Double-spend em split creation — sem lockForUpdate() na transaction row	ALTO
2	Processamento concorrente de withdrawals — sem lock pessimista	ALTO
3	Wallet balance race — firstOrCreate + increment sem lock explícito	MÉDIO
4	Webhook duplicado — sem lock na charge antes de creditar wallet	MÉDIO
Backend — Integridade Financeira
#	Problema	Impacto
1	Sem ledger double-entry — apenas increment/decrement em saldo	Impossível auditar movimentações
2	Sem hold period (D+N) — fundos disponíveis imediatamente	Risco de fraude/chargeback
3	Sem limites de saque por conta (diário/mensal)	Exposição financeira ilimitada
4	Sem versionamento de saldo (optimistic locking)	Corrupção silenciosa
5	Sem reserva financeira (rolling reserve)	Sem proteção contra chargebacks
6	ProcessWithdrawalJob marca como falho no primeiro erro mas tem 3 retries — retries são no-op	Saques falham permanentemente
Frontend Admin — UX/UI
#	Problema	Impacto
1	Apenas 2 componentes admin compartilhados — todo o resto é inline/duplicado	Manutenção impossível
2	StatusBadge definido diferente em 6+ arquivos	Inconsistência visual
3	Nenhum ConfirmationModal — ações destrutivas sem confirmação	Risco operacional
4	window.prompt() usado para input em Control Tower	UX inaceitável para fintech
5	Dark mode parcialmente implementado — 2 sistemas conflitantes	UI quebrada
6	Sem Error Boundary global — app crasha com tela branca	Experiência degradada
7	Suspense fallback={null} — tela branca durante navegação	Percepção de lentidão
8	Tabs "Fees" e "Acquirers" no detalhe do seller estão vazias	Feature incompleta
9	Impersonation token na URL query string — visível em logs/histórico	Vulnerabilidade
10	Sidebar com acentos faltando ("Visao Geral", "Operacao")	Falta de polish
Frontend Admin — Responsividade
#	Problema
1	Split binário mobile/desktop em lg (1024px) — tablets recebem layout mobile
2	Sem breakpoint md (768px) — nenhum tratamento intermediário
3	Sem breakpoint xl/2xl — ultra-wide não otimizado
4	Toast container não adapta para mobile
5	Pulse bar no admin wrapa em 2-3 linhas em telas médias
Frontend Admin — Acessibilidade
#	Problema
1	Botões hamburger/close sem aria-label
2	Tabelas clicáveis sem role, aria-label ou keyboard handler
3	Sem aria-current="page" em nav links ativos
4	Toast container sem aria-live region
5	Search input sem <label> associado
1.2 FUNCIONALIDADES AUSENTES PARA ENTERPRISE
Categoria	O que falta
Ledger	Double-entry accounting, conta gráfica, extrato interno
Settlement	D+N configurável, liquidação programada, repasse automático
Fee Engine	Motor de taxas dinâmico, MDR, antecipação, taxa por volume
Limites	Limites por conta, por transação, por dia, por método
Reserva	Rolling reserve, saldo bloqueado com liberação programada
AML	Monitoramento de lavagem, alertas por threshold, SAR
BACEN	Reporting regulatório, compliance PIX
Conciliação	Automática diária, cruzamento com extratos bancários
Smart Retry	Retry inteligente com troca de adquirente
Observabilidade	OpenTelemetry, tracing distribuído, métricas por adquirente
Multi-moeda	Suporte USD/EUR
Nota Fiscal	Integração NF-e/NFS-e
Tickets	Sistema de suporte interno
Incidentes	Gestão de incidentes operacionais
PARTE 2: ARQUITETURA IDEAL ENTERPRISE
2.1 Arquitetura Backend
┌─────────────────────────────────────────────────────────────────┐
│                        API GATEWAY (Nginx)                        │
│  Rate Limit │ WAF │ SSL │ Load Balance │ Request Routing          │
└──────────────────────────────┬──────────────────────────────────┘
                               │
┌──────────────────────────────┼──────────────────────────────────┐
│                    APPLICATION LAYER                              │
│                                                                   │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐             │
│  │  Auth API   │  │ Payment API │  │  Admin API  │             │
│  │  (Laravel)  │  │  (Laravel)  │  │  (Laravel)  │             │
│  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘             │
│         │                 │                 │                     │
│  ┌──────┴─────────────────┴─────────────────┴──────┐            │
│  │              DOMAIN LAYER (DDD)                   │            │
│  │                                                   │            │
│  │  ┌──────────┐ ┌──────────┐ ┌──────────┐         │            │
│  │  │ Payment  │ │ Account  │ │  Fraud   │         │            │
│  │  │ Domain   │ │ Domain   │ │  Domain  │         │            │
│  │  └────┬─────┘ └────┬─────┘ └────┬─────┘         │            │
│  │       │             │             │               │            │
│  │  ┌────┴─────────────┴─────────────┴────┐         │            │
│  │  │         LEDGER ENGINE               │         │            │
│  │  │  Double-Entry │ Fee Calc │ Settle   │         │            │
│  │  └────────────────────────────────────┘         │            │
│  └──────────────────────────────────────────────────┘            │
└──────────────────────────────┬──────────────────────────────────┘
                               │
┌──────────────────────────────┼──────────────────────────────────┐
│                   INFRASTRUCTURE LAYER                            │
│                                                                   │
│  ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐       │
│  │PostgreSQL│ │ Redis  │ │ Queue  │ │  S3    │ │Elastic │       │
│  │(Primary)│ │(Cache) │ │(Redis) │ │(Docs)  │ │(Logs)  │       │
│  └────────┘ └────────┘ └────────┘ └────────┘ └────────┘       │
│                                                                   │
│  ┌────────────────────────────────────────────────────┐         │
│  │           ACQUIRER GATEWAY LAYER                    │         │
│  │  PayFort│BluPay│Enki│Witetec│Asaas│Pagar.me│Stripe │         │
│  │  Circuit Breaker │ Health Check │ Failover          │         │
│  └────────────────────────────────────────────────────┘         │
│                                                                   │
│  ┌────────────────────────────────────────────────────┐         │
│  │           OBSERVABILITY STACK                       │         │
│  │  OpenTelemetry │ Prometheus │ Grafana │ Sentry      │         │
│  └────────────────────────────────────────────────────┘         │
└─────────────────────────────────────────────────────────────────┘
2.2 Estrutura Backend Ideal
backend/
├── app/
│   ├── Domain/
│   │   ├── Payment/
│   │   │   ├── Entities/          → Charge, Transaction, PaymentMethod
│   │   │   ├── ValueObjects/      → Money, Currency, CardData, PixKey
│   │   │   ├── Events/           → ChargeCreated, ChargePaid, ChargeRefunded
│   │   │   ├── Repositories/     → ChargeRepositoryInterface
│   │   │   └── Services/         → PaymentDomainService
│   │   ├── Ledger/
│   │   │   ├── Entities/          → LedgerEntry, Account, Journal
│   │   │   ├── ValueObjects/      → DebitCredit, EntryType
│   │   │   ├── Events/           → EntryPosted, BalanceChanged
│   │   │   └── Services/         → LedgerDomainService
│   │   ├── Settlement/
│   │   │   ├── Entities/          → Settlement, SettlementBatch
│   │   │   ├── Events/           → SettlementScheduled, SettlementExecuted
│   │   │   └── Services/         → SettlementDomainService
│   │   ├── Acquirer/
│   │   │   ├── Entities/          → Acquirer, AcquirerConfig
│   │   │   ├── ValueObjects/      → CircuitState, HealthStatus
│   │   │   └── Contracts/        → AcquirerInterface, PixProvider
│   │   ├── Fraud/
│   │   │   ├── Entities/          → RiskAssessment, FraudRule
│   │   │   ├── ValueObjects/      → RiskScore, DeviceFingerprint
│   │   │   └── Services/         → RiskEngineDomainService
│   │   ├── Account/
│   │   │   ├── Entities/          → Account, Wallet, User
│   │   │   ├── ValueObjects/      → AccountStatus, KycLevel
│   │   │   └── Events/           → AccountCreated, WalletCredited
│   │   ├── Fee/
│   │   │   ├── Entities/          → FeeRule, FeeCalculation
│   │   │   ├── ValueObjects/      → FeeType, FeeAmount
│   │   │   └── Services/         → FeeEngineDomainService
│   │   └── Compliance/
│   │       ├── Entities/          → AmlAlert, SarReport
│   │       ├── ValueObjects/      → RiskLevel, ComplianceStatus
│   │       └── Services/         → AmlMonitoringService
│   │
│   ├── Application/
│   │   ├── Payment/
│   │   │   ├── Commands/          → CreateCharge, ProcessRefund, RetryCharge
│   │   │   ├── Queries/           → GetCharge, ListCharges, GetChargeStats
│   │   │   └── Handlers/         → CreateChargeHandler, SmartRetryHandler
│   │   ├── Ledger/
│   │   │   ├── Commands/          → PostEntry, ReverseEntry
│   │   │   └── Queries/           → GetStatement, GetBalance, GetReconciliation
│   │   ├── Settlement/
│   │   │   ├── Commands/          → ScheduleSettlement, ExecuteSettlement
│   │   │   └── Queries/           → GetPendingSettlements
│   │   ├── Fraud/
│   │   │   ├── Commands/          → AssessRisk, BlockEntity, TrainModel
│   │   │   └── Queries/           → GetRiskScore, GetFraudDashboard
│   │   └── Fee/
│   │       ├── Commands/          → CalculateFee, ApplyFee
│   │       └── Queries/           → SimulateFee, GetFeeReport
│   │
│   ├── Infrastructure/
│   │   ├── Acquirer/
│   │   │   ├── PayFort/           → PayFortAcquirer, PayFortWebhookHandler
│   │   │   ├── BluPay/            → BluPayAcquirer
│   │   │   ├── Stripe/            → StripeAcquirer
│   │   │   └── Shared/            → CircuitBreaker, HealthChecker, AcquirerRouter
│   │   ├── Persistence/
│   │   │   ├── Repositories/      → EloquentChargeRepository, etc.
│   │   │   └── ReadModels/        → ChargeReadModel (CQRS read side)
│   │   ├── Messaging/
│   │   │   ├── Events/            → RabbitMQ/Redis event bus
│   │   │   └── Handlers/          → WebhookDispatcher, NotificationDispatcher
│   │   ├── Cache/                 → Redis cache strategies
│   │   ├── Observability/         → OpenTelemetry spans, metrics
│   │   └── External/
│   │       ├── Fraud/             → ML model client, scoring API
│   │       └── Compliance/        → BACEN reporting, NF-e integration
│   │
│   └── Http/
│       ├── Controllers/
│       │   ├── V1/                → Public API controllers
│       │   └── Admin/             → Admin-only controllers
│       ├── Middleware/
│       │   ├── Security/          → WAF, RateLimit, SignatureVerify
│       │   ├── Auth/              → JWT, APIKey, MFA
│       │   ├── Audit/             → AuditLog, ComplianceLog
│       │   └── Financial/         → IdempotencyCheck, BalanceLock
│       └── Resources/             → API Resources (transformers)
│
├── database/
│   ├── migrations/
│   └── seeders/
├── config/
│   ├── acquirers.php
│   ├── payment.php
│   ├── fees.php
│   ├── settlement.php
│   ├── fraud.php
│   └── compliance.php
└── tests/
    ├── Unit/Domain/
    ├── Integration/
    └── E2E/
2.3 Estrutura Frontend Admin Ideal
frontend/src/
├── app/
│   ├── router.tsx                → Route definitions + guards
│   ├── providers.tsx             → QueryClient, Theme, Auth providers
│   └── error-boundary.tsx        → Global error boundary
│
├── layouts/
│   ├── AdminLayout/
│   │   ├── index.tsx
│   │   ├── Sidebar.tsx           → Collapsible, responsive
│   │   ├── Header.tsx            → Search, notifications, user menu
│   │   ├── PulseBar.tsx          → Real-time operational status
│   │   └── Breadcrumbs.tsx
│   └── AuthLayout.tsx
│
├── pages/admin/
│   ├── dashboard/
│   │   ├── ExecutiveDashboard.tsx    → KPIs, revenue, volume
│   │   ├── OperationalDashboard.tsx  → Queues, SLAs, health
│   │   ├── FinancialDashboard.tsx    → P&L, fees, settlements
│   │   └── FraudDashboard.tsx        → Risk scores, alerts
│   ├── finance/
│   │   ├── Transactions.tsx          → All charges with advanced filters
│   │   ├── Withdrawals.tsx           → Queue + approval workflow
│   │   ├── Settlements.tsx           → D+N settlement management
│   │   ├── Reconciliation.tsx        → Auto + manual reconciliation
│   │   ├── Ledger.tsx                → Double-entry journal viewer
│   │   ├── FeeManagement.tsx         → Fee rules engine
│   │   └── Reserves.tsx              → Rolling reserve management
│   ├── sellers/
│   │   ├── SellerList.tsx            → Advanced table + bulk actions
│   │   ├── SellerDetail.tsx          → Full profile + tabs
│   │   ├── SellerFees.tsx            → Per-seller fee config
│   │   └── SellerLimits.tsx          → Transaction/withdrawal limits
│   ├── compliance/
│   │   ├── KycQueue.tsx              → Review queue
│   │   ├── KybQueue.tsx              → Business verification
│   │   ├── AmlAlerts.tsx             → Suspicious activity
│   │   ├── SarReports.tsx            → Regulatory reports
│   │   └── AuditTrail.tsx            → Immutable audit logs
│   ├── risk/
│   │   ├── FraudMonitor.tsx          → Real-time fraud detection
│   │   ├── RiskRules.tsx             → Rule engine config
│   │   ├── Blacklists.tsx            → Block management
│   │   ├── DeviceFingerprints.tsx    → Device tracking
│   │   └── ChargebackManagement.tsx  → Dispute lifecycle
│   ├── acquirers/
│   │   ├── AcquirerList.tsx          → Health + metrics
│   │   ├── AcquirerDetail.tsx        → Config + performance
│   │   ├── RoutingRules.tsx          → Intelligent routing config
│   │   └── CircuitBreakers.tsx       → Circuit state management
│   ├── operations/
│   │   ├── Tickets.tsx               → Internal support
│   │   ├── Incidents.tsx             → Incident management
│   │   ├── Webhooks.tsx              → Delivery logs + retry
│   │   ├── Jobs.tsx                  → Queue monitoring
│   │   └── Notifications.tsx         → Notification center
│   ├── security/
│   │   ├── ThreatMonitor.tsx         → Real-time threats
│   │   ├── AccessLogs.tsx            → Login history
│   │   ├── Sessions.tsx              → Active sessions
│   │   └── Permissions.tsx           → RBAC management
│   └── settings/
│       ├── Platform.tsx              → General config
│       ├── Branding.tsx              → White-label
│       ├── ApiKeys.tsx               → Key management
│       └── Integrations.tsx          → External services
│
├── components/
│   ├── ui/                           → Design system primitives
│   │   ├── Button.tsx
│   │   ├── Input.tsx
│   │   ├── Select.tsx
│   │   ├── Modal.tsx
│   │   ├── ConfirmDialog.tsx         → ⚠️ MISSING TODAY
│   │   ├── DataTable.tsx             → Unified table component
│   │   ├── Pagination.tsx
│   │   ├── Badge.tsx
│   │   ├── StatusBadge.tsx           → ⚠️ UNIFIED (6 copies today)
│   │   ├── Card.tsx
│   │   ├── StatCard.tsx
│   │   ├── Skeleton.tsx
│   │   ├── Toast.tsx
│   │   ├── EmptyState.tsx
│   │   ├── ErrorState.tsx            → ⚠️ MISSING TODAY
│   │   ├── FilterBar.tsx             → ⚠️ MISSING TODAY
│   │   ├── DateRangePicker.tsx       → ⚠️ MISSING TODAY
│   │   ├── CurrencyDisplay.tsx       → ⚠️ MISSING TODAY
│   │   └── Tooltip.tsx
│   ├── admin/
│   │   ├── AdminPageShell.tsx
│   │   ├── AdminPageHeader.tsx
│   │   ├── KpiCard.tsx
│   │   ├── ChartCard.tsx
│   │   ├── ActivityFeed.tsx
│   │   ├── ApprovalWorkflow.tsx
│   │   └── BulkActionBar.tsx         → ⚠️ MISSING TODAY
│   └── charts/
│       ├── RevenueChart.tsx
│       ├── VolumeChart.tsx
│       ├── ApprovalRateChart.tsx
│       └── RealTimeChart.tsx
│
├── services/api/
│   ├── admin/
│   │   ├── dashboard.ts
│   │   ├── finance.ts
│   │   ├── sellers.ts
│   │   ├── compliance.ts
│   │   ├── fraud.ts
│   │   ├── acquirers.ts
│   │   └── operations.ts
│   └── shared/
│       ├── http.ts                   → Axios instance + interceptors
│       └── websocket.ts             → Real-time events
│
├── stores/
│   ├── authStore.ts
│   ├── themeStore.ts
│   ├── notificationStore.ts
│   └── realtimeStore.ts             → WebSocket state
│
├── hooks/
│   ├── usePermission.ts
│   ├── useRealtime.ts
│   ├── useDebounce.ts
│   ├── usePagination.ts
│   └── useConfirm.ts
│
└── types/
    ├── admin.ts
    ├── finance.ts
    ├── compliance.ts
    └── shared.ts
PARTE 3: ESTRUTURAS DETALHADAS
3.1 Estrutura do Ledger (Double-Entry)
-- Plano de contas
CREATE TABLE chart_of_accounts (
    id UUID PRIMARY KEY,
    code VARCHAR(20) UNIQUE NOT NULL,        -- '1.1.001' (Ativo.Circulante.CaixaPIX)
    name VARCHAR(100) NOT NULL,
    type ENUM('asset','liability','equity','revenue','expense'),
    parent_id UUID REFERENCES chart_of_accounts(id),
    is_system BOOLEAN DEFAULT false,         -- Contas do sistema (não editáveis)
    metadata JSONB,
    created_at TIMESTAMP
);

-- Journals (agrupamento lógico de entries)
CREATE TABLE journals (
    id UUID PRIMARY KEY,
    reference_type VARCHAR(50) NOT NULL,     -- 'charge', 'withdrawal', 'fee', 'split'
    reference_id UUID NOT NULL,
    description TEXT,
    posted_at TIMESTAMP NOT NULL,
    reversed_at TIMESTAMP,                   -- Se foi estornado
    reversal_of UUID REFERENCES journals(id),
    created_by UUID,
    metadata JSONB,
    created_at TIMESTAMP
);

-- Ledger entries (imutáveis)
CREATE TABLE ledger_entries (
    id UUID PRIMARY KEY,
    journal_id UUID NOT NULL REFERENCES journals(id),
    account_id UUID NOT NULL REFERENCES chart_of_accounts(id),
    wallet_id UUID REFERENCES account_wallets(id),  -- Wallet do seller (se aplicável)
    debit BIGINT DEFAULT 0,                  -- Em centavos
    credit BIGINT DEFAULT 0,                 -- Em centavos
    balance_after BIGINT NOT NULL,           -- Running balance
    currency VARCHAR(3) DEFAULT 'BRL',
    posted_at TIMESTAMP NOT NULL,
    metadata JSONB,
    created_at TIMESTAMP,
    
    CONSTRAINT chk_debit_or_credit CHECK (
        (debit > 0 AND credit = 0) OR (debit = 0 AND credit > 0)
    )
);

-- Índices para performance
CREATE INDEX idx_ledger_account_posted ON ledger_entries(account_id, posted_at DESC);
CREATE INDEX idx_ledger_wallet ON ledger_entries(wallet_id, posted_at DESC);
CREATE INDEX idx_ledger_journal ON ledger_entries(journal_id);
CREATE INDEX idx_journals_reference ON journals(reference_type, reference_id);

-- Balance snapshots (para queries rápidas)
CREATE TABLE balance_snapshots (
    id UUID PRIMARY KEY,
    account_id UUID NOT NULL REFERENCES chart_of_accounts(id),
    wallet_id UUID REFERENCES account_wallets(id),
    balance BIGINT NOT NULL,
    snapshot_date DATE NOT NULL,
    entry_count INT NOT NULL,
    created_at TIMESTAMP,
    UNIQUE(account_id, wallet_id, snapshot_date)
);
Fluxo de uma cobrança aprovada:

Journal: "Charge #abc123 approved - R$ 100,00"
├── DEBIT  1.1.001 (Caixa PIX PayFort)      R$ 100,00
├── CREDIT 2.1.001 (A Pagar - Seller X)      R$  96,50
├── CREDIT 4.1.001 (Receita MDR)             R$   3,00
└── CREDIT 4.1.002 (Receita Antifraude)      R$   0,50
3.2 Estrutura do Fee Engine (Motor de Taxas)
CREATE TABLE fee_plans (
    id UUID PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    is_default BOOLEAN DEFAULT false,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

CREATE TABLE fee_rules (
    id UUID PRIMARY KEY,
    fee_plan_id UUID NOT NULL REFERENCES fee_plans(id),
    payment_method ENUM('pix','credit_card','boleto','ted') NOT NULL,
    fee_type ENUM('percentage','fixed','percentage_plus_fixed') NOT NULL,
    percentage_value DECIMAL(6,4),            -- Ex: 3.4900 = 3.49%
    fixed_value BIGINT,                       -- Em centavos
    min_fee BIGINT,                           -- Fee mínimo
    max_fee BIGINT,                           -- Fee máximo (cap)
    installment_from INT DEFAULT 1,           -- Aplica de X parcelas
    installment_to INT DEFAULT 1,             -- Até Y parcelas
    volume_min BIGINT,                        -- Volume mínimo mensal para esta regra
    volume_max BIGINT,                        -- Volume máximo
    acquirer_id UUID,                         -- Se específico por adquirente
    priority INT DEFAULT 0,                   -- Maior prioridade = aplicado primeiro
    is_active BOOLEAN DEFAULT true,
    metadata JSONB,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

CREATE TABLE account_fee_assignments (
    id UUID PRIMARY KEY,
    account_id UUID NOT NULL REFERENCES accounts(id),
    fee_plan_id UUID NOT NULL REFERENCES fee_plans(id),
    custom_overrides JSONB,                   -- Override específico do seller
    effective_from TIMESTAMP NOT NULL,
    effective_until TIMESTAMP,
    created_at TIMESTAMP
);

CREATE TABLE fee_calculations (
    id UUID PRIMARY KEY,
    charge_id UUID NOT NULL REFERENCES charges(id),
    fee_rule_id UUID NOT NULL REFERENCES fee_rules(id),
    gross_amount BIGINT NOT NULL,
    fee_amount BIGINT NOT NULL,
    net_amount BIGINT NOT NULL,
    breakdown JSONB NOT NULL,                 -- Detalhamento do cálculo
    created_at TIMESTAMP
);

-- Antecipação
CREATE TABLE anticipation_rules (
    id UUID PRIMARY KEY,
    fee_plan_id UUID REFERENCES fee_plans(id),
    account_id UUID REFERENCES accounts(id), -- Se específico
    rate_per_day DECIMAL(8,6) NOT NULL,       -- Taxa diária (ex: 0.000333 = 1%/30d)
    min_days INT DEFAULT 1,
    max_days INT DEFAULT 365,
    auto_anticipate BOOLEAN DEFAULT false,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP
);
3.3 Estrutura de Permissões Granulares (RBAC + ACL)
-- Roles hierárquicos
CREATE TABLE roles (
    id UUID PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    slug VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    level INT NOT NULL,                       -- Hierarquia (owner=100, admin=90, etc.)
    is_system BOOLEAN DEFAULT false,          -- Não pode ser deletado
    created_at TIMESTAMP
);

-- Permissões atômicas
CREATE TABLE permissions (
    id UUID PRIMARY KEY,
    module VARCHAR(50) NOT NULL,              -- 'finance', 'sellers', 'compliance', etc.
    action VARCHAR(50) NOT NULL,              -- 'view', 'create', 'update', 'delete', 'approve', 'export'
    resource VARCHAR(50) NOT NULL,            -- 'charges', 'withdrawals', 'kyc', etc.
    description TEXT,
    risk_level ENUM('low','medium','high','critical') DEFAULT 'low',
    requires_