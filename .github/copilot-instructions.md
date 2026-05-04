# WazerPay Gateway — Copilot Instructions

Plataforma de pagamentos digitais com dashboard para vendedores, checkout público, área de membros (cursos), landing pages, sistema de afiliados e painel administrativo.

## Arquitetura Geral

Monorepo com `frontend/` (React SPA) e `backend/` (Laravel API). Deploy via Docker Compose com Nginx, Redis e Evolution API (WhatsApp).

## Tech Stack

### Frontend
- **React 18** + **TypeScript 5.2** + **Vite 5** (porta 3000)
- **Zustand** (state) + **React Query** (server data) + **React Router 6**
- **Tailwind CSS 3.3** com temas dinâmicos (light/dark, classic/modern) via CSS variables
- **Axios** com interceptor de token refresh automático (401)
- Formulários: React Hook Form + Zod
- Charts: Chart.js + react-chartjs-2
- UI: Headless UI + Heroicons + Lucide + Framer Motion
- Google OAuth: `@react-oauth/google` com `useGoogleLogin` (popup)

### Backend
- **Laravel 10/11** + **PHP 8.2-8.5**
- **JWT Auth** (tymon/jwt-auth) — TTL 60min, refresh 14 dias
- **Google2FA** (pragmarx/google2fa) para MFA/TOTP — NÃO confundir com Google OAuth
- Redis (Predis) para cache e filas
- Guzzle HTTP para chamadas externas
- Stripe SDK para pagamentos internacionais

## Estrutura do Projeto

### Frontend (`frontend/src/`)
```
components/       → 20+ componentes UI (Badge, Button, Card, DataTable, Modal, StatCard, etc.)
config/           → seo.ts, themes.ts
hooks/            → useWebSocket (Pusher)
layouts/          → AuthLayout, DashboardLayout
lib/              → api.ts (Axios config + interceptors), domain.ts, safeUrl.ts
pages/            → 30+ páginas (auth, dashboard, public, admin, members)
services/         → 16 serviços API (auth, products, checkouts, charges, affiliates, etc.)
stores/           → 6 Zustand stores (auth, products, checkouts, charges, affiliates, theme)
styles/           → globals.css com CSS variables do tema
types/            → TypeScript interfaces
App.tsx           → Router + guards de autenticação + KYC redirect
main.tsx          → Entry point (GoogleOAuthProvider, QueryClient, HelmetProvider)
```

### Backend (`backend/app/`)
```
Application/      → DTOs e Services da camada de aplicação (Payment)
Domain/           → Lógica de domínio (Account, Acquirer, Auth, Checkout, Fraud, Payment, Product, Affiliate, Shared)
  ├── Entities/
  ├── Events/
  ├── Repositories/   → Contracts (interfaces)
  ├── Services/
  └── ValueObjects/
Infrastructure/   → Implementações concretas
  ├── Acquirers/      → 8 gateways de pagamento (PayFort, BluPay, Stripe, Pagarme, Babylon, MercadoPago, TurbosCash, AscentPay)
  ├── Persistence/
  └── Jobs/
Http/
  ├── Controllers/Api/V1/  → 49 controllers
  ├── Middleware/           → 21 middlewares (Auth, RateLimit, CORS, Security, Idempotency, etc.)
  ├── Requests/
  └── Traits/
Models/           → 44 Eloquent models
Services/         → 20+ serviços de negócio
Events/           → 3 domain events (PaymentStatusChanged, ChargebackReceived, SalesCountUpdated)
Jobs/             → 10 queue jobs (reconciliação, splits, webhooks, tracking)
Mail/             → 8 templates (welcome, KYC, payment, password reset, etc.)
```

## Rotas Principais (API v1)

### Públicas (sem auth)
- `POST /auth/register|login|google|forgot-password|reset-password`
- `POST /auth/mfa/verify`
- `GET|POST /checkout/{slug}` (checkout público)
- `GET /lp/{slug}` (landing pages)
- `GET /marketplace/products|categories|stats`
- `POST /webhook` (webhooks de acquirers)

### Autenticadas (JWT ou API Key)
- **Produtos**: CRUD `/products`
- **Checkouts**: CRUD `/checkouts` + clone + analytics
- **Cobranças**: CRUD `/charges` + refund + cancel + sync
- **Afiliados**: CRUD `/affiliates` + stats + invite
- **Área de Membros**: `/member-area/courses|modules|lessons|enrollments`
- **Relatórios**: `/reports/dashboard|transactions|revenue|products|export`
- **Saques**: CRUD `/withdrawals` + summary
- **KYC**: `/kyc/status|submit|documents`
- **Domínios**: CRUD `/domains` + verify
- **Webhooks**: CRUD `/webhooks`
- **API Keys**: CRUD `/api-keys` + rotate
- **Conta**: `/account` + wallet + transactions + payment-info

### Admin (role: admin/owner)
- `/admin/stats|activities|customers|withdrawals|kyc|acquirers`

## Sistema de Pagamentos

### Acquirers Suportados
1. **PayFort Bank** (primário) — PIX + Cartão
2. **BluPay IP** (fallback) — PIX + Cartão
3. **Stripe** — Cartão (internacional)
4. **Pagar.me** — Cartão + Boleto
5. **Babylon Bank** — Cartão + Boleto
6. **MercadoPago**, **TurbosCash**, **AscentPay**

### Métodos de Pagamento
- **PIX**: QR Code dinâmico + código copia-e-cola
- **Cartão de Crédito**: até 12x (juros após 6x, taxa 1.99%)
- **Boleto**: código de barras + linha digitável

### Fluxo
1. Cliente acessa checkout → escolhe método
2. `POST /charges` com X-Idempotency-Key
3. AcquirerFactory roteia para gateway ativo
4. Webhook confirma pagamento → atualiza status
5. Splits distribuem entre merchant/afiliados

### Status de Cobrança
`pending → processing → authorized → approved (paid) → refunded/chargeback`

## Autenticação

1. **Email/Senha** → JWT token (ou mfa_token se MFA ativo)
2. **Google OAuth** → `useGoogleLogin` popup → access_token → backend valida via `/oauth2/v3/userinfo`
3. **MFA (TOTP)** → Google Authenticator → `POST /auth/mfa/verify`
4. **API Key** → Header `X-API-Key` (pk_live_/sk_live_)
5. Token armazenado em localStorage (`auth_token`)
6. Auto-refresh no interceptor Axios ao receber 401

## Banco de Dados

60+ tabelas. Chaves primárias UUID. Soft deletes em muitas tabelas. Valores monetários em **centavos** (integer). Principais:

`accounts`, `users`, `charges`, `products`, `checkouts`, `transactions`, `affiliates`, `courses`, `enrollments`, `lessons`, `withdrawals`, `api_keys`, `audit_logs`, `kyc_submissions`, `custom_domains`, `landing_pages`, `webhooks`, `coupons`, `order_bumps`, `upsells`, `split_rules`, `system_settings`

## Convenções de Código

### Frontend
- Idioma do código: **inglês** (variáveis, componentes). Textos na UI: **português BR**
- Componentes: PascalCase, um por arquivo
- Stores: `use[Nome]Store` com Zustand + persist
- Serviços API: `[nome].service.ts` retornando Promises
- Estilização: Tailwind utility classes, sem CSS modules
- Design tokens via CSS variables: `--surface-*`, `--text-*`, `--accent-*`, `--border-*`
- Cor principal: roxo (`#7C3AED` / purple-600)
- Dark mode: classe `.dark` no HTML root
- Imports: alias `@/` = `src/`
- Valores monetários: sempre em centavos, formatar com `formatCurrency()`

### Backend
- Arquitetura DDD/Clean: domain separado de infrastructure
- Controllers finos → lógica nos Services
- Valores monetários em **centavos** (int)
- UUIDs como primary keys
- Validação via Form Requests ou inline `$request->validate()`
- Respostas JSON: `{ success: bool, data: {...} }` ou `{ success: false, error: { code, message } }`
- Logs estruturados: `Log::error('Context', ['key' => 'value'])`
- Acquirers implementam interface comum via AcquirerFactory
- Middleware de autenticação dual: JWT primeiro, fallback API Key

## Segurança

- Rate limiting: 30req/s API, 5req/s auth
- CORS restrito a origens conhecidas
- SecurityHeaders middleware (HSTS, X-Frame-Options, nosniff)
- Idempotency middleware para prevenir duplicações
- Blacklist de IPs/emails
- Audit logs para ações admin
- XSS: DOMPurify no frontend, escape no backend
- SSRF: validação de URLs com bloqueio de IPs privados
- `safeUrl.ts` para validação de URLs externas no frontend

## Docker & Deploy

5 containers: backend (PHP-FPM), frontend (Nginx + static), Redis, Nginx (proxy reverso), Evolution API (WhatsApp). Health checks em todos. Nginx com Cloudflare real IP, gzip, rate limiting.

## Variáveis de Ambiente

### Frontend (`.env`)
```
VITE_API_URL, VITE_APP_NAME, VITE_APP_URL, VITE_GOOGLE_CLIENT_ID
VITE_PUSHER_APP_KEY, VITE_PUSHER_APP_CLUSTER (opcionais)
```

### Backend (`.env`)
```
APP_KEY, DB_*, REDIS_*, JWT_SECRET, JWT_TTL
MAIL_*, GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET
PAYFORT_*, BLUPAY_*, STRIPE_*, PAGARME_*, BABYLON_*
OPENROUTER_API_KEY, TOGETHER_API_KEY
SENTRY_DSN, EVOLUTION_API_*
```

## Comandos

```bash
# Frontend
cd frontend && npm run dev       # Dev server (porta 3000)
cd frontend && npm run build     # Build produção
cd frontend && npm run lint      # ESLint

# Backend
cd backend && php artisan serve  # Dev server (porta 8000)
cd backend && php artisan migrate
cd backend && php artisan queue:work
cd backend && ./vendor/bin/pest  # Testes

# Docker
docker-compose up -d             # Todos os serviços
docker-compose logs -f backend   # Logs do backend
```

## Notas Importantes

- **Google2FA ≠ Google OAuth**: Google2FA é MFA/TOTP (manter sempre). Google OAuth é login social (usa `useGoogleLogin` popup)
- **GoogleAnalyticsIntegration** e **Google Fonts** são features separadas, não relacionadas a OAuth
- Modelos de IA mencionando "google/gemini" são nomes de modelo, não OAuth
- Área de membros tem auth própria (guard `members`) separada dos usuários da plataforma
- Checkout público não requer autenticação
- Webhooks de acquirers verificam assinatura HMAC
