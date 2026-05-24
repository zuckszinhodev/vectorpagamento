# Vector Pagamentos - Gateway de Pagamento by zuckszinho

![Vector Pagamentos](https://img.shields.io/badge/Vector Pagamentos-Gateway-purple)
![Laravel](https://img.shields.io/badge/Laravel-10+-red)
![React](https://img.shields.io/badge/React-18+-blue)
![License](https://img.shields.io/badge/License-Proprietary-green)

## 📋 Visão Geral

Vector Pagamentos é um gateway de pagamento SaaS enterprise-grade que oferece:

- ✅ **Checkout Builder** - Checkout altamente personalizável
- ✅ **Multiadquirente** - Roteamento inteligente com fallback automático
- ✅ **Marketplace** - Vitrine de produtos integrada
- ✅ **Sistema de Afiliados** - Split automático de comissões
- ✅ **API Pública** - REST API versionada completa
- ✅ **Segurança Fintech** - PCI-DSS compliant

## 🏗️ Arquitetura

```
┌─────────────────────────────────────────────────────────────────┐
│                        WAZERPAY GATEWAY                          │
├─────────────────────────────────────────────────────────────────┤
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐             │
│  │   React     │  │   Mobile    │  │  External   │             │
│  │  Dashboard  │  │    Apps     │  │   Clients   │             │
│  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘             │
│         │                │                │                     │
│         └────────────────┼────────────────┘                     │
│                          ▼                                      │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                    API Gateway (v1)                      │   │
│  │         Rate Limit │ Auth │ Validation │ CORS            │   │
│  └─────────────────────────────────────────────────────────┘   │
│                          │                                      │
│  ┌───────────────────────┴───────────────────────┐             │
│  │              APPLICATION LAYER                 │             │
│  │  ┌─────────┐ ┌─────────┐ ┌─────────┐        │             │
│  │  │ Payment │ │Checkout │ │Affiliate│        │             │
│  │  │ Service │ │ Service │ │ Service │  ...   │             │
│  │  └────┬────┘ └────┬────┘ └────┬────┘        │             │
│  └───────┼───────────┼───────────┼───────────────┘             │
│          │           │           │                              │
│  ┌───────┴───────────┴───────────┴───────────────┐             │
│  │                 DOMAIN LAYER                   │             │
│  │  Entities │ Value Objects │ Domain Services   │             │
│  │  Aggregates │ Events │ Specifications         │             │
│  └───────────────────────────────────────────────┘             │
│                          │                                      │
│  ┌───────────────────────┴───────────────────────┐             │
│  │             INFRASTRUCTURE LAYER               │             │
│  │  ┌─────────┐ ┌─────────┐ ┌─────────┐        │             │
│  │  │  Repos  │ │Acquirers│ │ Queue   │  ...   │             │
│  │  └────┬────┘ └────┬────┘ └────┬────┘        │             │
│  └───────┼───────────┼───────────┼───────────────┘             │
│          │           │           │                              │
│  ┌───────┴───┐ ┌─────┴─────┐ ┌───┴───┐                        │
│  │PostgreSQL │ │ Acquirers │ │ Redis │                        │
│  │           │ │           │ │       │                        │
│  │  • Users  │ │ • Stripe  │ │•Cache │                        │
│  │  • Trans  │ │ • Pagarme │ │•Queue │                        │
│  │  • Prods  │ │ • MercPag │ │•Sess  │                        │
│  └───────────┘ └───────────┘ └───────┘                        │
└─────────────────────────────────────────────────────────────────┘
```

## 🧱 Stack Tecnológica

### Backend
- **Framework:** Laravel 10+
- **Arquitetura:** Clean Architecture + DDD
- **Banco:** PostgreSQL 15+
- **Cache/Queue:** Redis 7+
- **Auth:** JWT + API Keys

### Frontend
- **Framework:** React 18+
- **Linguagem:** TypeScript
- **Build:** Vite
- **Estilo:** TailwindCSS
- **State:** Zustand
- **HTTP:** Axios

## 📂 Estrutura do Projeto

```
vectorpagamentos/
├── backend/                    # Laravel API
│   ├── app/
│   │   ├── Domain/            # Entidades, VOs, Domain Services
│   │   ├── Application/       # Use Cases, App Services
│   │   ├── Infrastructure/    # Repos, External Services
│   │   └── Interfaces/        # Controllers, Requests, Resources
│   ├── config/
│   ├── database/
│   ├── routes/
│   └── tests/
│
├── frontend/                   # React Dashboard
│   ├── src/
│   │   ├── components/
│   │   ├── pages/
│   │   ├── services/
│   │   ├── stores/
│   │   └── types/
│   └── public/
│
└── docs/                       # Documentação
    ├── api/
    ├── architecture/
    └── security/
```

## 🚀 Quick Start

### Requisitos
- PHP 8.2+
- Composer
- Node.js 18+
- PostgreSQL 15+
- Redis 7+

### Instalação

```bash
# Clone o repositório
git clone https://github.com/vectorpagamentos/gateway.git
cd gateway

# Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed

# Frontend
cd ../frontend
npm install
npm run dev
```

### Supabase

O projeto agora pode usar o Supabase em duas frentes:

1. Backend Laravel conectado ao Postgres do Supabase via `backend/.env`
2. Frontend Vite com cliente Supabase via `frontend/.env`

Exemplo mínimo de backend:

```env
DB_CONNECTION=pgsql
DB_HOST=db.<your-project-ref>.supabase.co
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres
DB_PASSWORD=<database-password>
DB_SSLMODE=require

SUPABASE_URL=https://<your-project-ref>.supabase.co
SUPABASE_ANON_KEY=<anon-key>
SUPABASE_SERVICE_ROLE_KEY=<service-role-key>
```

Exemplo mínimo de frontend:

```env
VITE_API_URL=http://127.0.0.1:8000/api/v1
VITE_SUPABASE_URL=https://<your-project-ref>.supabase.co
VITE_SUPABASE_ANON_KEY=<anon-key>
```

Observações:

- O fluxo atual de autenticação do sistema continua sendo JWT pelo Laravel.
- O cliente Supabase do frontend foi adicionado para uso futuro com `Auth`, `Storage` ou `Realtime`, sem quebrar a API existente.
- Se preferir, o backend também aceita `DATABASE_URL` do Supabase em vez de `DB_HOST`, `DB_PORT` e demais variáveis separadas.

## 📚 Documentação

- [Arquitetura](docs/architecture/README.md)
- [API Reference](docs/api/README.md)
- [Segurança](docs/security/README.md)
- [Integrações](docs/integrations/README.md)

## 🔐 Segurança

Vector Pagamentos implementa segurança de nível fintech:

- JWT + Refresh Token com rotação
- API Keys com permissões granulares
- Rate limiting por IP e API Key
- RBAC completo
- Proteção XSS, CSRF, SQL Injection
- Webhooks assinados (HMAC-SHA256)
- Logs de auditoria completos
- Idempotência em transações
- Criptografia de credenciais (AES-256)

## 📄 Licença

Proprietary - Vector Pagamentos © 2024
