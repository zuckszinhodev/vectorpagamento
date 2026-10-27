<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Motor de roteamento inteligente
    |--------------------------------------------------------------------------
    |
    | Quando desligado, o PaymentService continua usando o caminho legado.
    | Permite ativar o novo motor gradualmente em produção.
    |
    */
    'engine_enabled' => env('ACQUIRER_ROUTING_ENGINE_ENABLED', true),

    /*
    | Percentual de tráfego roteado pelo novo motor (0-100).
    | Útil para canary release: 5 → 5% das cobranças usam o motor novo.
    */
    'rollout_percentage' => (int) env('ACQUIRER_ROUTING_ROLLOUT_PERCENTAGE', 100),

    /*
    |--------------------------------------------------------------------------
    | Criptografia de credenciais
    |--------------------------------------------------------------------------
    |
    | ACQUIRER_ENCRYPTION_KEY: chave AES-256 em base64 (32 bytes).
    |   Gere com: php artisan acquirer:generate-key
    |
    | ACQUIRER_ENCRYPTION_KEYS_PREVIOUS: JSON {"1":"<base64>","2":"<base64>"}
    |   com as chaves antigas, para rotação sem downtime.
    |
    | Sem ACQUIRER_ENCRYPTION_KEY o cofre deriva uma chave do APP_KEY via HKDF
    | e registra um aviso — funciona, mas não é recomendado em produção porque
    | amarra o segredo das adquirentes ao ciclo de vida do APP_KEY.
    |
    */
    'encryption' => [
        'key' => env('ACQUIRER_ENCRYPTION_KEY'),
        'key_version' => (int) env('ACQUIRER_ENCRYPTION_KEY_VERSION', 1),
        'previous_keys' => env('ACQUIRER_ENCRYPTION_KEYS_PREVIOUS'),
        'allow_app_key_fallback' => env('ACQUIRER_ENCRYPTION_ALLOW_APP_KEY_FALLBACK', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Módulos de adquirente
    |--------------------------------------------------------------------------
    |
    | Mapa acquirer_key => classe. Para adicionar uma adquirente nova basta:
    |
    |   1. criar a classe (implementando PaymentProviderInterface, ou seguindo
    |      a convenção das classes existentes para reaproveitar o adaptador);
    |   2. adicionar uma linha aqui;
    |   3. cadastrar a adquirente pelo painel administrativo.
    |
    | Nenhum arquivo do núcleo de roteamento precisa ser alterado.
    |
    | Os métodos de pagamento suportados vêm de acquirer_configs.supported_methods,
    | controlado pelo painel — não são fixados em código.
    |
    */
    'providers' => [
        'payfort' => \App\Infrastructure\Acquirers\PayFortAcquirer::class,
        'blupay' => \App\Infrastructure\Acquirers\BluPayAcquirer::class,
        'akkadpag' => \App\Infrastructure\Acquirers\AkkadPagAcquirer::class,
        'enki' => \App\Infrastructure\Acquirers\EnkiAcquirer::class,
        'witetec' => \App\Infrastructure\Acquirers\WitetecAcquirer::class,
        'asaas' => \App\Infrastructure\Acquirers\AsaasAcquirer::class,
        'huntersub' => \App\Infrastructure\Acquirers\HunterSubAcquirer::class,
        'sharkhubsub' => \App\Infrastructure\Acquirers\SharkHubSubAcquirer::class,
        'babylon' => \App\Infrastructure\Acquirers\BabylonAcquirer::class,
        'turbos' => \App\Infrastructure\Acquirers\TurbosCashAcquirer::class,
        'ascentpay' => \App\Infrastructure\Acquirers\AscentPayAcquirer::class,
        'stripe' => \App\Infrastructure\Acquirers\StripeAcquirer::class,
        'pagarme' => \App\Infrastructure\Acquirers\PagarmeAcquirer::class,
        'mercadopago' => \App\Infrastructure\Acquirers\MercadoPagoAcquirer::class,
        'kokupay' => \App\Infrastructure\Acquirers\KokuPayAcquirer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Leitura de saldo por adquirente
    |--------------------------------------------------------------------------
    |
    | Cada PSP devolve o saldo com um nome de campo e uma UNIDADE diferente.
    | Errar a unidade é um erro de fator 100 num saque, então o mapeamento é
    | explícito aqui em vez de ficar num match espalhado pelo código.
    |
    |   field   → caminho do campo na resposta de getBalance() (dot notation)
    |   unit    → 'cents' ou 'reais'
    |   blocked → campo opcional do saldo bloqueado
    |
    | Adquirente não listada: tenta os campos comuns abaixo, assumindo reais.
    | Se nada casar, o saldo é reportado como indisponível — nunca como zero,
    | para não parecer "sem fundos" quando na verdade não sabemos ler.
    |
    */
    'balances' => [
        'enki' => ['field' => 'available', 'unit' => 'cents', 'blocked' => 'reserved'],
        'blupay' => ['field' => 'pix_balance', 'unit' => 'reais', 'blocked' => 'pix_blocked'],
        'witetec' => ['field' => 'balance', 'unit' => 'reais', 'blocked' => 'blocked_balance'],
        'kokupay' => ['field' => 'available_cents', 'unit' => 'cents', 'blocked' => 'blocked_cents'],

        // Campos tentados quando a adquirente não está mapeada acima.
        'fallback_fields' => ['available_balance', 'available', 'balance', 'amount'],
        'fallback_unit' => 'reais',
    ],

    /*
    |--------------------------------------------------------------------------
    | Padrões de roteamento
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'strategy' => env('ACQUIRER_ROUTING_STRATEGY', 'priority'),
        'failover_enabled' => env('ACQUIRER_FAILOVER_ENABLED', true),
        // Quantas adquirentes diferentes tentar antes de desistir.
        'max_attempts' => (int) env('ACQUIRER_ROUTING_MAX_ATTEMPTS', 3),
        // Orçamento total da cadeia de fallback. Ao estourar, o router para
        // mesmo que ainda existam candidatas — protege o tempo de resposta.
        'total_budget_ms' => (int) env('ACQUIRER_ROUTING_BUDGET_MS', 25000),
        'strict_health_check' => env('ACQUIRER_ROUTING_STRICT_HEALTH', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Padrões por adquirente (usados quando não há perfil no banco)
    |--------------------------------------------------------------------------
    */
    'profile_defaults' => [
        'priority' => 100,
        'weight' => 1,
        'max_tps' => null,
        'max_concurrent' => null,
        'connect_timeout_ms' => 3000,
        'timeout_ms' => 15000,
        'max_retries' => 1,
        'retry_backoff_ms' => 200,
        'retry_backoff_max_ms' => 2000,
        'cb_failure_threshold' => 5,
        'cb_cooldown_seconds' => 60,
        'cb_half_open_max_calls' => 1,
        'cb_success_threshold' => 2,
        'health_check_enabled' => true,
        'health_check_interval_seconds' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring do roteador
    |--------------------------------------------------------------------------
    |
    | Pesos usados para ordenar candidatas nas estratégias baseadas em métrica.
    | A soma não precisa ser 1 — os valores são normalizados.
    |
    */
    'scoring' => [
        'latency_weight' => 0.30,
        'approval_weight' => 0.40,
        'error_weight' => 0.20,
        'utilization_weight' => 0.10,
        // Janela de métricas considerada para decisões de roteamento.
        'window_minutes' => (int) env('ACQUIRER_ROUTING_METRICS_WINDOW', 15),
        // Abaixo disso não há amostra suficiente: cai para prioridade.
        'min_samples' => (int) env('ACQUIRER_ROUTING_MIN_SAMPLES', 20),
        // Latência (ms) considerada "ruim" ao normalizar o score.
        'latency_ceiling_ms' => 8000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Health check
    |--------------------------------------------------------------------------
    */
    'health' => [
        'enabled' => env('ACQUIRER_HEALTH_CHECK_ENABLED', true),
        'default_interval_seconds' => (int) env('ACQUIRER_HEALTH_CHECK_INTERVAL', 60),
        'timeout_ms' => (int) env('ACQUIRER_HEALTH_CHECK_TIMEOUT_MS', 5000),
        // Latência acima da qual a adquirente é marcada como degradada.
        'degraded_latency_ms' => (int) env('ACQUIRER_HEALTH_DEGRADED_LATENCY_MS', 3000),
        // Falhas consecutivas de health check antes de marcar offline.
        'offline_after_failures' => (int) env('ACQUIRER_HEALTH_OFFLINE_AFTER', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */
    'http' => [
        // Reaproveitamento de conexões TCP/TLS entre requisições do mesmo worker.
        'connection_pooling' => env('ACQUIRER_HTTP_POOLING', true),
        'max_connections' => (int) env('ACQUIRER_HTTP_MAX_CONNECTIONS', 50),
        'max_connections_per_host' => (int) env('ACQUIRER_HTTP_MAX_PER_HOST', 20),
        'keep_alive_seconds' => (int) env('ACQUIRER_HTTP_KEEPALIVE', 60),
        'user_agent' => env('ACQUIRER_HTTP_USER_AGENT', 'VectorPagamentos/2.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retenção
    |--------------------------------------------------------------------------
    */
    'retention' => [
        'metric_buckets_days' => (int) env('ACQUIRER_METRICS_RETENTION_DAYS', 30),
        'routing_logs_days' => (int) env('ACQUIRER_ROUTING_LOGS_RETENTION_DAYS', 60),
        'events_days' => (int) env('ACQUIRER_EVENTS_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'prefix' => 'acq_routing',
        'catalog_ttl' => (int) env('ACQUIRER_ROUTING_CATALOG_TTL', 60),
        'policy_ttl' => (int) env('ACQUIRER_ROUTING_POLICY_TTL', 120),
        'metrics_ttl' => (int) env('ACQUIRER_ROUTING_METRICS_TTL', 15),
    ],

];
