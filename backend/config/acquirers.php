<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Acquirers Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your payment gateway acquirers here. Each acquirer can be
    | enabled/disabled and configured with their respective credentials.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Stripe
    |--------------------------------------------------------------------------
    */
    'stripe' => [
        'enabled' => env('STRIPE_ENABLED', false),
        'api_key' => env('STRIPE_SECRET_KEY'),
        'public_key' => env('STRIPE_PUBLIC_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagar.me
    |--------------------------------------------------------------------------
    */
    'pagarme' => [
        'enabled' => env('PAGARME_ENABLED', false),
        'api_key' => env('PAGARME_API_KEY'),
        'encryption_key' => env('PAGARME_ENCRYPTION_KEY'),
        'webhook_secret' => env('PAGARME_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Banco Babylon
    |--------------------------------------------------------------------------
    |
    | API Documentation: https://bancobabylon.readme.io/reference/introdução
    |
    | Authentication: Basic Auth with Secret Key as username and Company ID as password
    |
    | To get your credentials:
    | 1. Access the gateway panel
    | 2. Navigate to Integrations → API Keys
    | 3. Copy the Secret Key (will be used as username)
    | 4. Copy the Company ID (will be used as password)
    |
    */
    'babylon' => [
        'enabled' => env('BABYLON_ENABLED', false),
        
        // Secret Key - used as username for Basic Auth
        'secret_key' => env('BABYLON_SECRET_KEY'),
        
        // Company ID - used as password for Basic Auth
        'company_id' => env('BABYLON_COMPANY_ID'),
        
        // Public Key - used for frontend card tokenization
        'public_key' => env('BABYLON_PUBLIC_KEY'),
        
        // Webhook Secret - for verifying webhook signatures (optional)
        'webhook_secret' => env('BABYLON_WEBHOOK_SECRET'),
        
        // API Base URL (usually don't change this)
        'base_url' => env('BABYLON_BASE_URL', 'https://api.bancobabylon.com/functions/v1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | PayFort Bank
    |--------------------------------------------------------------------------
    |
    | API Documentation: https://docs.payfortbank.com
    |
    | Authentication: Basic Auth (API Key + API Secret) → JWT Bearer Token (30min TTL)
    | PIX IN:  POST /api/v1/pix/in/qrcode
    | PIX OUT: POST /api/v1/pix/out/pixkey (requires x-idempotency-key header)
    | Webhook: HMAC-SHA256 signature via payfort-Signature header
    |
    | To get your credentials:
    | 1. Access PayFort Bank dashboard
    | 2. Copy the API Key (pk_live_*)
    | 3. Copy the API Secret (sk_live_*)
    | 4. Configure webhook URL and save the signatureSecret
    |
    */
    'payfort' => [
        'enabled' => env('PAYFORT_ENABLED', true),

        // API Key - used for Basic Auth (username)
        'api_key' => env('PAYFORT_API_KEY'),

        // API Secret - used for Basic Auth (password)
        'api_secret' => env('PAYFORT_API_SECRET'),

        // Webhook Signature Secret - for verifying webhook signatures (HMAC-SHA256)
        'webhook_secret' => env('PAYFORT_WEBHOOK_SECRET'),

        // API Base URL
        'base_url' => env('PAYFORT_BASE_URL', 'https://api.payfortbank.com'),

        // Public webhook URL (used as postbackUrl in PIX requests)
        // Set this to your public-facing API URL if APP_URL is localhost
        'webhook_url' => env('PAYFORT_WEBHOOK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | BluPay IP
    |--------------------------------------------------------------------------
    |
    | API Documentation: https://docs.blupayip.io
    |
    | Authentication: Basic Auth (secretKey:publicKey) on all endpoints
    | PIX IN:  POST /api/v1/transactions
    | PIX OUT: POST /api/v1/transactions/pix-out (requires Idempotency-Key)
    | Balance: GET /api/v1/balance
    |
    | Webhook events: transaction.paid, transaction.refunded, transaction.infraction,
    |                 transfer.paid, transfer.failed
    | Webhook signature: HMAC-SHA256 via X-Webhook-Signature header
    |
    | Used as FALLBACK when the primary acquirer (PayFort) fails.
    |
    */
    'blupay' => [
        'enabled' => env('BLUPAY_ENABLED', false),

        // Secret Key - used as username for Basic Auth
        'secret_key' => env('BLUPAY_SECRET_KEY'),

        // Public Key - used as password for Basic Auth
        'public_key' => env('BLUPAY_PUBLIC_KEY'),

        // Webhook Secret - for HMAC-SHA256 signature verification (optional)
        'webhook_secret' => env('BLUPAY_WEBHOOK_SECRET'),

        // API Base URL
        'base_url' => env('BLUPAY_BASE_URL', 'https://api.blupayip.io'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Witetec
    |--------------------------------------------------------------------------
    |
    | API Documentation: https://doc.witetec.com
    |
    | Authentication: x-api-key header
    | PIX IN:  POST /transactions (method: PIX)
    | PIX OUT: POST /withdrawals (method: PIX)
    | Card:    POST /transactions (method: CREDIT_CARD, requires 3DS encryption)
    | Boleto:  POST /transactions (method: BOLETO)
    | Balance: GET /seller-wallet/gestao
    |
    | All amounts are in CENTS (centavos)
    | Minimum withdrawal: R$ 5.00 (500 cents)
    |
    | Card payments require frontend encryption using ShieldHelper.js:
    | Script: https://shield.witetec.net/shield-helper.js
    |
    | Webhook events: TRANSACTION_PAID, TRANSACTION_FAILED, TRANSACTION_REFUNDED,
    |                 TRANSACTION_CHARGEDBACK, WITHDRAWAL_PAID, WITHDRAWAL_FAILED
    | Webhook signature: HMAC-SHA256 via X-Witetec-Signature header
    |
    */
    'witetec' => [
        'enabled' => env('WITETEC_ENABLED', false),

        // API Key - used in x-api-key header
        'api_key' => env('WITETEC_API_KEY'),

        // Webhook Secret - for HMAC-SHA256 signature verification
        'webhook_secret' => env('WITETEC_WEBHOOK_SECRET'),

        // API Base URL
        'base_url' => env('WITETEC_BASE_URL', 'https://api.witetec.net'),

        // Supported payment methods
        'supported_methods' => ['pix', 'credit_card', 'boleto'],

        // Settings
        'settings' => [
            'min_withdrawal_amount' => 5.00, // R$ 5.00
            'withdrawal_fee' => 0.10, // R$ 0.10
            'pix_expires_in' => 1800, // 30 minutes
            'boleto_days_to_expire' => 3,
            'max_installments' => 12,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Asaas
    |--------------------------------------------------------------------------
    |
    | API Documentation: https://docs.asaas.com
    |
    | Authentication: access_token header
    | PIX IN:  POST /payments (billingType=PIX) + GET /payments/{id}/pixQrCode
    | Boleto:  POST /payments (billingType=BOLETO) + GET /payments/{id}/identificationField
    | Card:    POST /payments (billingType=CREDIT_CARD)
    | Refund:  POST /payments/{id}/refund
    |
    | Split: walletId-based, fixedValue or percentualValue on netValue
    |
    | Webhook events: PAYMENT_RECEIVED, PAYMENT_CONFIRMED, PAYMENT_REFUNDED,
    |                 PAYMENT_OVERDUE, PAYMENT_DELETED, CHARGEBACK_REQUESTED, etc.
    | Webhook auth: asaas-access-token header
    |
    */
    'asaas' => [
        'enabled' => env('ASAAS_ENABLED', false),

        // API Key - used in access_token header
        'api_key' => env('ASAAS_API_KEY'),

        // Webhook Token - for verifying webhook authenticity
        'webhook_token' => env('ASAAS_WEBHOOK_TOKEN'),

        // API Base URL (sandbox by default for safety)
        'base_url' => env('ASAAS_BASE_URL', 'https://api-sandbox.asaas.com/v3'),

        // Supported payment methods
        'supported_methods' => ['pix', 'credit_card', 'boleto'],

        // Settings
        'settings' => [
            'pix_expires_in' => 1800, // 30 minutes
            'boleto_days_to_expire' => 3,
            'max_installments' => 12,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Enki Bank
    |--------------------------------------------------------------------------
    |
    | API Documentation: https://app.enki-bank.com/
    |
    | Authentication: Basic Auth (public_key:secret_key)
    | Balance:   GET  /v1/company/balance
    | PIX IN:    POST /v1/transactions
    | PIX OUT:   POST /v1/transfers (requires x-withdrawal-key)
    | Sync:      GET  /v1/transactions/:id
    |
    | Webhook events:
    | - transaction.waiting_payment
    | - transaction.paid
    | - transaction.refunded
    | - withdrawal.processing
    | - withdrawal.completed
    | - withdrawal.failed
    |
    */
    'enki' => [
        'enabled' => env('ENKI_ENABLED', false),

        // Public Key - used as username for Basic Auth
        'public_key' => env('ENKI_PUBLIC_KEY'),

        // Secret Key - used as password for Basic Auth
        'secret_key' => env('ENKI_SECRET_KEY'),

        // Withdrawal credential - required in x-withdrawal-key header for PIX OUT
        'withdrawal_key' => env('ENKI_WITHDRAWAL_KEY'),

        // API Base URL
        'base_url' => env('ENKI_BASE_URL', 'https://api.enki-bank.com'),

        // Optional public webhook URL override
        'webhook_url' => env('ENKI_WEBHOOK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HunterSub V2
    |--------------------------------------------------------------------------
    |
    | Authentication:
    | - Transactions: Basic Auth (api_key:company_id)
    | - Withdrawals: Basic Auth (api_key:company_id)
    | Charge endpoint: POST /transactions
    | Webhook events: transaction.paid, transaction.pending, transaction.failed,
    |                 transaction.refunded, transaction.chargeback, transaction.delivered
    |
    */
    'huntersub' => [
        'enabled' => env('HUNTERSUB_ENABLED', false),

        // Client ID - legacy optional identifier; company_id is used for Basic Auth.
        'client_id' => env('HUNTERSUB_CLIENT_ID'),

        // API Key / Secret Key - Basic Auth username for transactions and withdrawals
        'api_key' => env('HUNTERSUB_API_KEY'),

        // Company ID - Basic Auth password for transaction and withdrawal endpoints
        'company_id' => env('HUNTERSUB_COMPANY_ID'),

        // Webhook secret (HMAC)
        'webhook_secret' => env('HUNTERSUB_WEBHOOK_SECRET'),

        // API Base URL
        'base_url' => env('HUNTERSUB_BASE_URL', 'https://api.bluevisionsolucoes.com.br/functions/v1'),

        // Public webhook URL sent in transaction creation
        'webhook_url' => env('HUNTERSUB_WEBHOOK_URL', env('APP_URL') . '/api/v1/webhooks/huntersub'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SharkHub Subadquirente
    |--------------------------------------------------------------------------
    |
    | Authentication:
    | - Payments: Bearer token from "Credenciais de API"
    | - Transfers: Bearer token from "Credenciais de Saque via API"
    | PIX IN:  POST /v1/payment
    | PIX OUT: POST /v1/transfer
    | Webhook signature: HMAC-SHA256 Base64 via X-Signature header
    |
    */
    'sharkhubsub' => [
        'enabled' => env('SHARKHUBSUB_ENABLED', false),
        'api_key' => env('SHARKHUBSUB_API_KEY'),
        'withdrawal_key' => env('SHARKHUBSUB_WITHDRAWAL_KEY'),
        'webhook_secret' => env('SHARKHUBSUB_WEBHOOK_SECRET'),
        'transfer_webhook_secret' => env('SHARKHUBSUB_TRANSFER_WEBHOOK_SECRET'),
        'base_url' => env('SHARKHUBSUB_BASE_URL', 'https://api.sharkhubsubadquirente.com'),
        'webhook_url' => env('SHARKHUBSUB_WEBHOOK_URL', env('APP_URL') . '/api/v1/webhooks/sharkhubsub'),
        'transfer_webhook_url' => env('SHARKHUBSUB_TRANSFER_WEBHOOK_URL', env('APP_URL') . '/api/v1/webhooks/sharkhubsub'),
        'supported_methods' => ['pix', 'pix_out'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Acquirer
    |--------------------------------------------------------------------------
    |
    | The default acquirer to use when no specific routing rules match.
    |
    */
    'default' => env('DEFAULT_ACQUIRER', 'enki'),

    /*
    |--------------------------------------------------------------------------
    | Routing Configuration
    |--------------------------------------------------------------------------
    |
    | Configure intelligent routing between acquirers based on various factors.
    |
    */
    'routing' => [
        // Optional hard lock to a specific acquirer key (ex: enki).
        // Keep empty to route by active acquirers + DB priority.
        'force_acquirer' => env('ACQUIRER_FORCE_ACQUIRER', ''),

        // Enable intelligent routing between acquirers
        'enabled' => env('ACQUIRER_ROUTING_ENABLED', true),
        
        // Failover to next acquirer if primary fails
        'failover_enabled' => env('ACQUIRER_FAILOVER_ENABLED', true),
        
        // Health check interval in seconds
        'health_check_interval' => env('ACQUIRER_HEALTH_CHECK_INTERVAL', 60),
        
        // Maximum failure rate before marking acquirer as unhealthy (0-1)
        'max_failure_rate' => env('ACQUIRER_MAX_FAILURE_RATE', 0.3),
    ],

];
