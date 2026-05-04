# 🏗️ Arquitetura WazerPay

## Clean Architecture + Domain-Driven Design

### Bounded Contexts

```
┌─────────────────────────────────────────────────────────────────────────┐
│                          WAZERPAY BOUNDED CONTEXTS                       │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌──────────────────┐    ┌──────────────────┐    ┌──────────────────┐  │
│  │   Auth & Access  │    │     Accounts     │    │  Payments Core   │  │
│  │                  │    │                  │    │                  │  │
│  │ • Users          │    │ • Companies      │    │ • Charges        │  │
│  │ • Roles          │    │ • Settings       │    │ • Transactions   │  │
│  │ • Permissions    │    │ • Wallets        │    │ • Splits         │  │
│  │ • API Keys       │    │ • Balances       │    │ • Refunds        │  │
│  │ • Sessions       │    │ • KYC            │    │ • Reconciliation │  │
│  └──────────────────┘    └──────────────────┘    └──────────────────┘  │
│                                                                          │
│  ┌──────────────────┐    ┌──────────────────┐    ┌──────────────────┐  │
│  │    Acquirers     │    │ Checkout Builder │    │    Products &    │  │
│  │                  │    │                  │    │   Marketplace    │  │
│  │ • Adapters       │    │ • Templates      │    │                  │  │
│  │ • Routing        │    │ • Themes         │    │ • Products       │  │
│  │ • Fallback       │    │ • Fields         │    │ • Categories     │  │
│  │ • Health Check   │    │ • Embed/Redirect │    │ • Inventory      │  │
│  │ • Credentials    │    │ • Customization  │    │ • SEO            │  │
│  └──────────────────┘    └──────────────────┘    └──────────────────┘  │
│                                                                          │
│  ┌──────────────────┐    ┌──────────────────┐    ┌──────────────────┐  │
│  │    Affiliates    │    │     Webhooks     │    │  Reports & Logs  │  │
│  │                  │    │                  │    │                  │  │
│  │ • Links          │    │ • Endpoints      │    │ • Analytics      │  │
│  │ • Tracking       │    │ • Events         │    │ • Statements     │  │
│  │ • Commissions    │    │ • Signatures     │    │ • Exports        │  │
│  │ • Payouts        │    │ • Retries        │    │ • Audit Trail    │  │
│  └──────────────────┘    └──────────────────┘    └──────────────────┘  │
│                                                                          │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │                      Security & Audit                             │  │
│  │                                                                    │  │
│  │  • Rate Limiting  • Fraud Detection  • Encryption  • Compliance  │  │
│  │  • IP Blocking    • Risk Scoring     • Key Rotation • LGPD       │  │
│  └──────────────────────────────────────────────────────────────────┘  │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

## Camadas da Arquitetura

### 1. Domain Layer (Core)

```
Domain/
├── Auth/
│   ├── Entities/
│   │   ├── User.php
│   │   ├── Role.php
│   │   └── Permission.php
│   ├── ValueObjects/
│   │   ├── Email.php
│   │   ├── Password.php
│   │   └── ApiKeyToken.php
│   ├── Events/
│   │   ├── UserCreated.php
│   │   └── UserAuthenticated.php
│   └── Services/
│       └── AuthDomainService.php
│
├── Payment/
│   ├── Entities/
│   │   ├── Charge.php
│   │   ├── Transaction.php
│   │   ├── Split.php
│   │   └── Refund.php
│   ├── ValueObjects/
│   │   ├── Money.php
│   │   ├── CardData.php
│   │   ├── PixData.php
│   │   └── TransactionStatus.php
│   ├── Events/
│   │   ├── ChargeCreated.php
│   │   ├── PaymentApproved.php
│   │   └── PaymentFailed.php
│   ├── Services/
│   │   └── PaymentDomainService.php
│   └── Specifications/
│       ├── CanProcessPayment.php
│       └── IsRefundable.php
│
├── Acquirer/
│   ├── Contracts/
│   │   └── AcquirerGatewayInterface.php
│   ├── ValueObjects/
│   │   ├── AcquirerResponse.php
│   │   └── RoutingRule.php
│   └── Services/
│       └── AcquirerRoutingService.php
│
├── Checkout/
│   ├── Entities/
│   │   └── Checkout.php
│   ├── ValueObjects/
│   │   ├── CheckoutTheme.php
│   │   └── CheckoutField.php
│   └── Events/
│       └── CheckoutCompleted.php
│
├── Product/
│   ├── Entities/
│   │   ├── Product.php
│   │   └── Category.php
│   ├── ValueObjects/
│   │   └── ProductPrice.php
│   └── Events/
│       └── ProductCreated.php
│
├── Affiliate/
│   ├── Entities/
│   │   ├── Affiliate.php
│   │   └── AffiliateLink.php
│   ├── ValueObjects/
│   │   └── Commission.php
│   └── Events/
│       └── CommissionEarned.php
│
└── Shared/
    ├── ValueObjects/
    │   ├── Uuid.php
    │   ├── Money.php
    │   └── DateRange.php
    └── Contracts/
        ├── AggregateRoot.php
        └── DomainEvent.php
```

### 2. Application Layer

```
Application/
├── Auth/
│   ├── Commands/
│   │   ├── RegisterUserCommand.php
│   │   ├── LoginCommand.php
│   │   └── CreateApiKeyCommand.php
│   ├── Queries/
│   │   ├── GetUserQuery.php
│   │   └── ListApiKeysQuery.php
│   ├── Handlers/
│   │   ├── RegisterUserHandler.php
│   │   └── LoginHandler.php
│   └── DTOs/
│       ├── UserDTO.php
│       └── ApiKeyDTO.php
│
├── Payment/
│   ├── Commands/
│   │   ├── CreateChargeCommand.php
│   │   ├── ProcessPaymentCommand.php
│   │   └── RefundPaymentCommand.php
│   ├── Queries/
│   │   ├── GetChargeQuery.php
│   │   └── ListTransactionsQuery.php
│   ├── Handlers/
│   │   ├── CreateChargeHandler.php
│   │   └── ProcessPaymentHandler.php
│   └── Services/
│       ├── PaymentService.php
│       └── RefundService.php
│
├── Checkout/
│   ├── Commands/
│   │   ├── CreateCheckoutCommand.php
│   │   └── UpdateCheckoutCommand.php
│   ├── Handlers/
│   │   └── CreateCheckoutHandler.php
│   └── Services/
│       └── CheckoutBuilderService.php
│
├── Product/
│   ├── Commands/
│   │   └── CreateProductCommand.php
│   ├── Services/
│   │   └── ProductService.php
│   └── DTOs/
│       └── ProductDTO.php
│
└── Affiliate/
    ├── Commands/
    │   └── CreateAffiliateLinkCommand.php
    ├── Services/
    │   └── AffiliateService.php
    └── DTOs/
        └── AffiliateDTO.php
```

### 3. Infrastructure Layer

```
Infrastructure/
├── Persistence/
│   ├── Repositories/
│   │   ├── EloquentUserRepository.php
│   │   ├── EloquentChargeRepository.php
│   │   └── EloquentProductRepository.php
│   ├── Models/
│   │   ├── UserModel.php
│   │   ├── ChargeModel.php
│   │   └── TransactionModel.php
│   └── Migrations/
│
├── Acquirers/
│   ├── Stripe/
│   │   ├── StripeGateway.php
│   │   └── StripeWebhookHandler.php
│   ├── Pagarme/
│   │   ├── PagarmeGateway.php
│   │   └── PagarmeWebhookHandler.php
│   ├── MercadoPago/
│   │   ├── MercadoPagoGateway.php
│   │   └── MercadoPagoWebhookHandler.php
│   └── Factory/
│       └── AcquirerFactory.php
│
├── Cache/
│   └── RedisCacheService.php
│
├── Queue/
│   ├── Jobs/
│   │   ├── ProcessPaymentJob.php
│   │   ├── SendWebhookJob.php
│   │   └── ReconcileTransactionJob.php
│   └── Listeners/
│       └── PaymentEventListener.php
│
├── External/
│   ├── Antifraud/
│   │   └── ClearSaleService.php
│   └── Notification/
│       ├── EmailService.php
│       └── SmsService.php
│
└── Security/
    ├── Encryption/
    │   └── AesEncryptionService.php
    ├── RateLimiter/
    │   └── RedisRateLimiter.php
    └── Audit/
        └── AuditLogService.php
```

### 4. Interfaces Layer (API)

```
Interfaces/
├── Http/
│   ├── Controllers/
│   │   ├── Api/
│   │   │   └── V1/
│   │   │       ├── AuthController.php
│   │   │       ├── ChargeController.php
│   │   │       ├── PaymentController.php
│   │   │       ├── CheckoutController.php
│   │   │       ├── ProductController.php
│   │   │       ├── AffiliateController.php
│   │   │       └── WebhookController.php
│   │   └── Admin/
│   │       └── DashboardController.php
│   │
│   ├── Requests/
│   │   ├── CreateChargeRequest.php
│   │   ├── ProcessPaymentRequest.php
│   │   └── CreateCheckoutRequest.php
│   │
│   ├── Resources/
│   │   ├── ChargeResource.php
│   │   ├── TransactionResource.php
│   │   └── ProductResource.php
│   │
│   └── Middleware/
│       ├── ApiKeyAuthentication.php
│       ├── RateLimitMiddleware.php
│       ├── IdempotencyMiddleware.php
│       └── AuditMiddleware.php
│
└── Console/
    └── Commands/
        ├── ReconcileTransactions.php
        └── RotateApiKeys.php
```

## Fluxo de Pagamento

```
┌─────────┐     ┌─────────┐     ┌─────────┐     ┌─────────┐     ┌─────────┐
│ Cliente │────▶│ Gateway │────▶│ Routing │────▶│Acquirer │────▶│ Response│
└─────────┘     └─────────┘     └─────────┘     └─────────┘     └─────────┘
     │               │               │               │               │
     │   Request     │  Validation   │   Strategy    │   Process     │
     │   ────────▶   │   ────────▶   │   ────────▶   │   ────────▶   │
     │               │               │               │               │
     │               │               │   ┌───────┐   │               │
     │               │               │   │Fallback│  │               │
     │               │               │   │  ▼    │   │               │
     │               │               │   │Acquirer│  │               │
     │               │               │   │  2    │   │               │
     │               │               │   └───────┘   │               │
     │               │               │               │               │
     │   ◀────────   │   ◀────────   │   ◀────────   │   ◀────────   │
     │   Response    │   Format      │   Result      │   Gateway Res │
     │               │               │               │               │
     ▼               ▼               ▼               ▼               ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                           EVENT BUS                                      │
│  PaymentCreated │ PaymentProcessing │ PaymentApproved │ PaymentFailed   │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                    ┌───────────────┼───────────────┐
                    ▼               ▼               ▼
              ┌──────────┐   ┌──────────┐   ┌──────────┐
              │ Webhook  │   │  Split   │   │  Audit   │
              │ Dispatch │   │ Process  │   │   Log    │
              └──────────┘   └──────────┘   └──────────┘
```

## Roteamento Multiadquirente

```
┌─────────────────────────────────────────────────────────────────┐
│                    ROUTING ENGINE                                │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌─────────────┐                                                │
│  │   Request   │                                                │
│  │  (Charge)   │                                                │
│  └──────┬──────┘                                                │
│         │                                                        │
│         ▼                                                        │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │              ROUTING RULES ENGINE                        │   │
│  │                                                          │   │
│  │  1. Check Account Preferences                           │   │
│  │  2. Check Payment Method Support                        │   │
│  │  3. Evaluate Priority Rules                             │   │
│  │  4. Check Acquirer Health Status                        │   │
│  │  5. Compare Transaction Fees                            │   │
│  │  6. Apply Load Balancing                                │   │
│  └─────────────────────────────────────────────────────────┘   │
│         │                                                        │
│         ▼                                                        │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                 SELECTED ACQUIRER                        │   │
│  │         ┌───────────────────────────────┐               │   │
│  │         │   Primary: Stripe (98% up)    │               │   │
│  │         │   Fee: 2.9% + R$ 0.30         │               │   │
│  │         └───────────────────────────────┘               │   │
│  └─────────────────────────────────────────────────────────┘   │
│         │                                                        │
│         ▼                                                        │
│  ┌─────────────┐     ┌─────────────┐     ┌─────────────┐       │
│  │   SUCCESS   │     │   FAILURE   │────▶│  FALLBACK   │       │
│  │             │     │             │     │             │       │
│  │  Complete   │     │  Retry or   │     │ Next Best   │       │
│  │  Payment    │     │  Fallback   │     │ Acquirer    │       │
│  └─────────────┘     └─────────────┘     └─────────────┘       │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

## Segurança em Camadas

```
┌─────────────────────────────────────────────────────────────────┐
│                    SECURITY LAYERS                               │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Layer 1: Network                                               │
│  ├── WAF (Web Application Firewall)                            │
│  ├── DDoS Protection                                            │
│  └── TLS 1.3                                                    │
│                                                                  │
│  Layer 2: Application                                           │
│  ├── Rate Limiting (IP + API Key)                              │
│  ├── Request Validation                                         │
│  ├── Input Sanitization                                         │
│  └── CORS Policy                                                │
│                                                                  │
│  Layer 3: Authentication                                        │
│  ├── JWT + Refresh Token                                        │
│  ├── API Keys with Scopes                                       │
│  ├── Multi-Factor Authentication                                │
│  └── Session Management                                         │
│                                                                  │
│  Layer 4: Authorization                                         │
│  ├── RBAC (Role-Based Access Control)                          │
│  ├── Permission-based Actions                                   │
│  └── Resource Ownership Validation                              │
│                                                                  │
│  Layer 5: Data                                                  │
│  ├── Encryption at Rest (AES-256)                              │
│  ├── Encryption in Transit (TLS)                               │
│  ├── PII Tokenization                                          │
│  └── Key Rotation                                               │
│                                                                  │
│  Layer 6: Audit                                                 │
│  ├── Action Logging                                             │
│  ├── Access Logging                                             │
│  ├── Security Events                                            │
│  └── Compliance Reports                                         │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```
