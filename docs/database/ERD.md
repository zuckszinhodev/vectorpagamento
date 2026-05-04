# 📊 Entity Relationship Diagram - WazerPay

## Diagrama Completo

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              WAZERPAY DATABASE SCHEMA                            │
└─────────────────────────────────────────────────────────────────────────────────┘

┌────────────────────┐          ┌────────────────────┐          ┌────────────────────┐
│       users        │          │      accounts      │          │     api_keys       │
├────────────────────┤          ├────────────────────┤          ├────────────────────┤
│ id (uuid) PK       │──┐       │ id (uuid) PK       │          │ id (uuid) PK       │
│ account_id FK      │  │   ┌──▶│ name               │◀────┐    │ account_id FK      │
│ email (unique)     │  │   │   │ document           │     │    │ name               │
│ password_hash      │  │   │   │ document_type      │     │    │ key_hash           │
│ name               │  │   │   │ email              │     │    │ secret_hash        │
│ phone              │  │   │   │ phone              │     │    │ permissions (json) │
│ role_id FK         │  │   │   │ status             │     │    │ rate_limit         │
│ email_verified_at  │  │   │   │ settings (json)    │     │    │ expires_at         │
│ mfa_enabled        │  │   │   │ metadata (json)    │     │    │ last_used_at       │
│ mfa_secret         │  │   │   │ created_at         │     │    │ is_active          │
│ last_login_at      │  │   │   │ updated_at         │     │    │ created_at         │
│ last_login_ip      │  │   │   │ deleted_at         │     │    │ updated_at         │
│ created_at         │  │   │   └────────────────────┘     │    │ deleted_at         │
│ updated_at         │  │   │                              │    └────────────────────┘
│ deleted_at         │  │   │   ┌────────────────────┐     │
└────────────────────┘  │   │   │   account_wallets  │     │
         │              │   │   ├────────────────────┤     │
         │              └───┼──▶│ id (uuid) PK       │     │
         ▼                  │   │ account_id FK      │◀────┘
┌────────────────────┐      │   │ currency           │
│       roles        │      │   │ available_balance  │
├────────────────────┤      │   │ pending_balance    │
│ id (uuid) PK       │      │   │ blocked_balance    │
│ name               │      │   │ created_at         │
│ slug               │      │   │ updated_at         │
│ description        │      │   └────────────────────┘
│ permissions (json) │      │
│ created_at         │      │   ┌────────────────────┐
│ updated_at         │      │   │     acquirers      │
└────────────────────┘      │   ├────────────────────┤
                            │   │ id (uuid) PK       │
                            │   │ name               │
                            │   │ slug               │
┌────────────────────┐      │   │ is_active          │
│     checkouts      │      │   │ supported_methods  │
├────────────────────┤      │   │ settings (json)    │
│ id (uuid) PK       │      │   │ created_at         │
│ account_id FK      │◀─────┘   │ updated_at         │
│ name               │          └────────────────────┘
│ slug (unique)      │                   ▲
│ type               │                   │
│ status             │          ┌────────┴───────────┐
│ theme (json)       │          │account_acquirers   │
│ fields (json)      │          ├────────────────────┤
│ settings (json)    │      ┌──▶│ id (uuid) PK       │
│ success_url        │      │   │ account_id FK      │
│ cancel_url         │      │   │ acquirer_id FK     │
│ webhook_url        │      │   │ credentials (enc)  │
│ created_at         │      │   │ priority           │
│ updated_at         │      │   │ is_active          │
│ deleted_at         │      │   │ settings (json)    │
└────────────────────┘      │   │ created_at         │
         ▲                  │   │ updated_at         │
         │                  │   └────────────────────┘
         │                  │
┌────────┴───────────┐      │   ┌────────────────────┐
│      products      │      │   │   routing_rules    │
├────────────────────┤      │   ├────────────────────┤
│ id (uuid) PK       │      └──▶│ id (uuid) PK       │
│ account_id FK      │          │ account_id FK      │
│ checkout_id FK     │          │ acquirer_id FK     │
│ name               │          │ payment_method     │
│ slug (unique)      │          │ min_amount         │
│ description        │          │ max_amount         │
│ type               │          │ priority           │
│ price              │          │ conditions (json)  │
│ currency           │          │ is_active          │
│ commission_percent │          │ created_at         │
│ commission_fixed   │          │ updated_at         │
│ affiliate_enabled  │          └────────────────────┘
│ stock_enabled      │
│ stock_quantity     │
│ images (json)      │          ┌────────────────────┐
│ metadata (json)    │          │      charges       │
│ seo_title          │          ├────────────────────┤
│ seo_description    │     ┌───▶│ id (uuid) PK       │
│ is_active          │     │    │ account_id FK      │
│ created_at         │     │    │ product_id FK      │
│ updated_at         │     │    │ checkout_id FK     │
│ deleted_at         │     │    │ affiliate_id FK    │
└────────────────────┘     │    │ idempotency_key    │
         ▲                 │    │ external_id        │
         │                 │    │ amount             │
         │                 │    │ currency           │
┌────────┴───────────┐     │    │ payment_method     │
│     categories     │     │    │ status             │
├────────────────────┤     │    │ customer (json)    │
│ id (uuid) PK       │     │    │ billing (json)     │
│ account_id FK      │     │    │ metadata (json)    │
│ parent_id FK       │     │    │ expires_at         │
│ name               │     │    │ paid_at            │
│ slug               │     │    │ created_at         │
│ description        │     │    │ updated_at         │
│ image_url          │     │    └────────────────────┘
│ sort_order         │     │             ▲
│ is_active          │     │             │
│ created_at         │     │    ┌────────┴───────────┐
│ updated_at         │     │    │    transactions    │
└────────────────────┘     │    ├────────────────────┤
                           │    │ id (uuid) PK       │
                           │    │ charge_id FK       │
┌────────────────────┐     │    │ acquirer_id FK     │
│     affiliates     │     │    │ type               │
├────────────────────┤     │    │ status             │
│ id (uuid) PK       │     │    │ amount             │
│ user_id FK         │     │    │ fee                │
│ product_id FK      │─────┘    │ net_amount         │
│ status             │          │ acquirer_tid       │
│ commission_percent │          │ acquirer_nsu       │
│ commission_fixed   │          │ authorization_code │
│ token (unique)     │          │ card_brand         │
│ total_sales        │          │ card_last_digits   │
│ total_commission   │          │ installments       │
│ metadata (json)    │          │ gateway_response   │
│ created_at         │          │ error_code         │
│ updated_at         │          │ error_message      │
│ deleted_at         │          │ processed_at       │
└────────────────────┘          │ created_at         │
         │                      │ updated_at         │
         │                      └────────────────────┘
         │                               ▲
         │                               │
         │                      ┌────────┴───────────┐
         │                      │       splits       │
         │                      ├────────────────────┤
         │                      │ id (uuid) PK       │
         └─────────────────────▶│ transaction_id FK  │
                                │ recipient_id FK    │
                                │ recipient_type     │
                                │ type               │
                                │ amount             │
                                │ percentage         │
                                │ fee_amount         │
                                │ net_amount         │
                                │ status             │
                                │ transferred_at     │
                                │ created_at         │
                                │ updated_at         │
                                └────────────────────┘

┌────────────────────┐          ┌────────────────────┐
│      refunds       │          │      webhooks      │
├────────────────────┤          ├────────────────────┤
│ id (uuid) PK       │          │ id (uuid) PK       │
│ transaction_id FK  │          │ account_id FK      │
│ charge_id FK       │          │ url                │
│ amount             │          │ events (json)      │
│ reason             │          │ secret             │
│ status             │          │ is_active          │
│ acquirer_refund_id │          │ created_at         │
│ processed_at       │          │ updated_at         │
│ metadata (json)    │          │ deleted_at         │
│ created_at         │          └────────────────────┘
│ updated_at         │
└────────────────────┘          ┌────────────────────┐
                                │  webhook_attempts  │
                                ├────────────────────┤
┌────────────────────┐          │ id (uuid) PK       │
│    audit_logs      │          │ webhook_id FK      │
├────────────────────┤          │ event_type         │
│ id (uuid) PK       │          │ payload (json)     │
│ user_id FK         │          │ response_code      │
│ account_id FK      │          │ response_body      │
│ action             │          │ attempt_number     │
│ entity_type        │          │ next_retry_at      │
│ entity_id          │          │ created_at         │
│ old_values (json)  │          └────────────────────┘
│ new_values (json)  │
│ ip_address         │
│ user_agent         │          ┌────────────────────┐
│ metadata (json)    │          │  security_events   │
│ created_at         │          ├────────────────────┤
└────────────────────┘          │ id (uuid) PK       │
                                │ user_id FK         │
                                │ account_id FK      │
                                │ event_type         │
                                │ severity           │
                                │ ip_address         │
                                │ user_agent         │
                                │ details (json)     │
                                │ created_at         │
                                └────────────────────┘

┌────────────────────┐          ┌────────────────────┐
│ idempotency_keys   │          │  rate_limit_logs   │
├────────────────────┤          ├────────────────────┤
│ id (uuid) PK       │          │ id (uuid) PK       │
│ key (unique)       │          │ identifier         │
│ request_hash       │          │ identifier_type    │
│ response (json)    │          │ endpoint           │
│ status_code        │          │ requests_count     │
│ created_at         │          │ window_start       │
│ expires_at         │          │ blocked            │
└────────────────────┘          │ created_at         │
                                └────────────────────┘
```

## Índices Principais

```sql
-- Performance indexes
CREATE INDEX idx_users_account_id ON users(account_id);
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_charges_account_id ON charges(account_id);
CREATE INDEX idx_charges_status ON charges(status);
CREATE INDEX idx_charges_created_at ON charges(created_at);
CREATE INDEX idx_charges_idempotency_key ON charges(idempotency_key);
CREATE INDEX idx_transactions_charge_id ON transactions(charge_id);
CREATE INDEX idx_transactions_status ON transactions(status);
CREATE INDEX idx_products_account_id ON products(account_id);
CREATE INDEX idx_products_slug ON products(slug);
CREATE INDEX idx_affiliates_token ON affiliates(token);
CREATE INDEX idx_api_keys_key_hash ON api_keys(key_hash);
CREATE INDEX idx_audit_logs_entity ON audit_logs(entity_type, entity_id);
CREATE INDEX idx_webhook_attempts_webhook_id ON webhook_attempts(webhook_id);
```

## Constraints e Regras

```sql
-- Soft delete em entidades críticas
-- (users, accounts, charges, transactions, products, affiliates, api_keys, webhooks)

-- UUID como primary key em todas as tabelas

-- Foreign key constraints com ON DELETE RESTRICT para integridade

-- Check constraints para enums
CHECK (status IN ('pending', 'processing', 'approved', 'declined', 'cancelled', 'refunded'))
CHECK (payment_method IN ('credit_card', 'debit_card', 'pix', 'boleto'))
CHECK (currency IN ('BRL', 'USD', 'EUR'))
```

## Tabelas de Configuração

```
┌────────────────────┐          ┌────────────────────┐
│   settings         │          │   feature_flags    │
├────────────────────┤          ├────────────────────┤
│ id (uuid) PK       │          │ id (uuid) PK       │
│ account_id FK      │          │ name               │
│ key                │          │ description        │
│ value (json)       │          │ is_enabled         │
│ created_at         │          │ rollout_percentage │
│ updated_at         │          │ created_at         │
└────────────────────┘          │ updated_at         │
                                └────────────────────┘
```
