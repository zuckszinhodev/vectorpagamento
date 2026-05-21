<?php declare(strict_types=1);

namespace App\Services;

use App\Mail\PaymentApprovedCustomerMail;
use App\Mail\PaymentApprovedMerchantMail;
use App\Models\Charge;
use App\Models\Checkout;
use App\Models\Transaction;
use App\Models\AccountWallet;
use App\Models\AccountAcquirer;
use App\Models\AcquirerConfig;
use App\Models\AccountCustomFee;
use App\Models\SystemSetting;
use App\Domain\Acquirer\Entities\Acquirer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Services\UtmifyService;
use App\Services\AcquirerRotationService;

/**
 * Payment Service - Integrates with payment acquirers
 * 
 * Supports: PayFort Bank (primary), BluPay IP (fallback for PIX), Banco Babylon (legacy)
 * Handles all payment processing including PIX, Credit Card, and Boleto
 *
 * Fallback chain for PIX: PayFort → BluPay → Babylon → Mock
 */
class PaymentService
{
    private const DEFAULT_PIX_EXPIRATION_MINUTES = 1440;

    private const CONFIRMED_WEBHOOK_STATUSES = [
        'paid',
        'approved',
        'authorized',
        'completed',
        'confirmed',
        'settled',
        'success',
    ];

    private string $baseUrl;
    private string $secretKey;
    private string $companyId;
    private ?string $acquirerId = null;
    private ?N8nWebhookService $n8nService = null;
    private ?WebhookDispatchService $webhookDispatch = null;
    private ?UtmifyService $utmifyService = null;
    private ?AcquirerRotationService $rotationService = null;
    private string $defaultAcquirer;
    private AcquirerFactory $factory;

    /** @var array<string, CircuitBreaker> */
    private array $circuitBreakers = [];

    public function __construct(?N8nWebhookService $n8nService = null, ?WebhookDispatchService $webhookDispatch = null)
    {
        $this->defaultAcquirer = config('acquirers.default', 'payfort');
        $this->factory = AcquirerFactory::instance();

        // Initialize circuit breakers per acquirer (threshold=3 failures, cooldown=60s)
        foreach (['payfort', 'blupay', 'babylon', 'enki', 'huntersub', 'sharkhubsub'] as $name) {
            $this->circuitBreakers[$name] = new CircuitBreaker("acquirer:{$name}", 3, 60);
        }

        // Babylon config (legacy fallback)
        $this->baseUrl = (string) (config('acquirers.babylon.base_url') ?? 'https://api.bancobabylon.com/functions/v1');
        $this->secretKey = (string) (config('acquirers.babylon.secret_key') ?? '');
        $this->companyId = (string) (config('acquirers.babylon.company_id') ?? '');
        
        $this->n8nService = $n8nService;
        $this->webhookDispatch = $webhookDispatch;

        try {
            $this->utmifyService = app(UtmifyService::class);
        } catch (\Exception $e) {
            Log::warning('UtmifyService failed to initialize – UTMify notifications will be skipped', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->rotationService = app(AcquirerRotationService::class);
        } catch (\Throwable $e) {
            Log::warning('AcquirerRotationService failed to initialize', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if PayFort Bank is configured and enabled
     */
    public function isPayFortConfigured(): bool
    {
        return AcquirerFactory::isPayFortConfigured();
    }

    /**
     * Check if TurbosCash is configured and enabled
     */
    public function isTurbosConfigured(): bool
    {
        return AcquirerFactory::isTurbosConfigured();
    }

    /**
     * Check if BluPay IP is configured and enabled
     */
    public function isBluPayConfigured(): bool
    {
        return AcquirerFactory::isBluPayConfigured();
    }

    /**
     * Get the active acquirer name
     */
    public function getActiveAcquirer(): string
    {
        return $this->factory->getActiveAcquirerName();
    }

    /**
     * Get or create the Babylon acquirer ID
     */
    private function getAcquirerId(): string
    {
        if ($this->acquirerId) {
            return $this->acquirerId;
        }

        $acquirer = Acquirer::where('slug', 'babylon')->first();
        
        if (!$acquirer) {
            // Create Babylon acquirer if it doesn't exist
            $acquirer = Acquirer::create([
                'name' => 'Banco Babylon',
                'slug' => 'babylon',
                'is_active' => true,
                'supported_methods' => ['credit_card', 'pix', 'boleto'],
                'settings' => [],
            ]);
        }

        $this->acquirerId = $acquirer->id;
        return $this->acquirerId;
    }

    /**
     * Get or create acquirer ID by slug
     */
    private function getOrCreateAcquirerId(string $slug, string $displayName): string
    {
        $acquirer = Acquirer::where('slug', $slug)->first();

        if (!$acquirer) {
            $acquirer = Acquirer::create([
                'name' => $displayName,
                'slug' => $slug,
                'is_active' => true,
                'supported_methods' => ['credit_card', 'pix', 'boleto'],
                'settings' => [],
            ]);
        }

        return $acquirer->id;
    }

    /**
     * Check if Babylon is configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->secretKey) && !empty($this->companyId);
    }

    /**
     * Get authorization header
     */
    private function getAuthHeader(): string
    {
        return base64_encode($this->secretKey . ':' . $this->companyId);
    }

    /**
     * Get HTTP client with proper SSL configuration
     */
    private function getHttpClient()
    {
        $client = Http::withHeaders([
            'Authorization' => 'Basic ' . $this->getAuthHeader(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->connectTimeout(3)->timeout(10);

        // Disable SSL verification in local/development environment only
        if (config('app.env') === 'local') {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * Process payment based on method
     */
    public function processPayment(Charge $charge, array $paymentData = []): array
    {
        try {
            $paymentsEnabled = (bool) SystemSetting::get('payments_enabled', true);
            if (!$paymentsEnabled) {
                $charge->update([
                    'status' => 'failed',
                ]);

                return [
                    'success' => false,
                    'error' => 'Pagamentos temporariamente desativados pela administração.',
                ];
            }

            if (!$this->isPaymentMethodEnabled($charge->payment_method ?? 'pix')) {
                $charge->update([
                    'status' => 'failed',
                ]);

                return [
                    'success' => false,
                    'error' => 'Este método de pagamento está temporariamente indisponível.',
                ];
            }

            // Route by account-specific acquirer order when configured; otherwise use global admin priority.
            $accountId = $charge->account_id;
            $paymentMethod = $charge->payment_method ?? 'pix';
            $lockedAccountRouting = $this->resolveLockedAccountAcquirerRouting($accountId, $paymentMethod);

            if (($lockedAccountRouting['locked'] ?? false) && !empty($lockedAccountRouting['error'])) {
                $charge->update(['status' => 'failed']);

                Log::warning('Payment blocked by account-specific acquirer routing', [
                    'charge_id' => $charge->id,
                    'account_id' => $accountId,
                    'payment_method' => $paymentMethod,
                    'error' => $lockedAccountRouting['error'],
                ]);

                return [
                    'success' => false,
                    'error' => $lockedAccountRouting['error'],
                ];
            }

            $acquirer = $this->factory->getActiveAcquirerNameForAccount($accountId, $paymentMethod);
            if (($lockedAccountRouting['locked'] ?? false) && !empty($lockedAccountRouting['order'][0])) {
                $acquirer = $lockedAccountRouting['order'][0];
            }

            Log::info('PaymentService: processing payment', [
                'charge_id' => $charge->id,
                'payment_method' => $paymentMethod,
                'active_acquirer' => $acquirer,
                'account_id' => $accountId,
                'routing_scope' => ($lockedAccountRouting['locked'] ?? false) ? 'account_locked' : ($accountId ? 'account_then_global' : 'global_admin'),
            ]);

            $forcedRoutingError = $this->validateForcedAcquirerSupport($paymentMethod, $acquirer);
            if ($forcedRoutingError !== null) {
                $charge->update([
                    'status' => 'failed',
                ]);

                Log::warning('Payment blocked by forced acquirer constraints', [
                    'charge_id' => $charge->id,
                    'payment_method' => $paymentMethod,
                    'forced_acquirer' => $acquirer,
                ]);

                return $forcedRoutingError;
            }

            // Route PIX payments with automatic fallback chain based on account acquirers
            if ($charge->payment_method === 'pix') {
                return $this->processPixWithFallback($charge, $acquirer, $lockedAccountRouting);
            }

            if ($acquirer === 'mock') {
                Log::error('No acquirer configured for payment method', [
                    'charge_id' => $charge->id,
                    'payment_method' => $charge->payment_method,
                ]);
                return [
                    'success' => false,
                    'error' => 'Nenhuma adquirente configurada para processar este método de pagamento.',
                ];
            }

            // Card/Boleto → route based on active acquirer
            return match ($charge->payment_method) {
                'boleto' => $this->generateBoleto($charge),
                'credit_card' => $this->processCardWithRouting($charge, $paymentData, $acquirer),
                default => [
                    'success' => false,
                    'error' => 'Método de pagamento não suportado.',
                ],
            };
        } catch (\Throwable $e) {
            Log::error('PaymentService::processPayment exception', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error' => 'Erro interno ao processar pagamento: ' . $e->getMessage(),
            ];
        }
    }

    private function isPaymentMethodEnabled(string $paymentMethod): bool
    {
        return match (strtolower($paymentMethod)) {
            'pix' => (bool) SystemSetting::get('pix_enabled', true),
            'credit_card', 'card', 'debit_card' => (bool) SystemSetting::get('credit_card_enabled', true),
            'boleto' => (bool) SystemSetting::get('boleto_enabled', true),
            default => true,
        };
    }

    private function getForcedAcquirer(): ?string
    {
        $forced = strtolower(trim((string) config('acquirers.routing.force_acquirer', '')));
        return $forced !== '' ? $forced : null;
    }

    private function validateForcedAcquirerSupport(string $paymentMethod, string $acquirer): ?array
    {
        $forcedAcquirer = $this->getForcedAcquirer();
        if ($forcedAcquirer === null || $forcedAcquirer !== strtolower($acquirer)) {
            return null;
        }

        if ($forcedAcquirer === 'enki' && strtolower($paymentMethod) !== 'pix') {
            return [
                'success' => false,
                'error' => 'Enki suporta apenas PIX. Use PIX ou remova o forcamento de roteamento para Enki.',
            ];
        }

        return null;
    }

    private function isAcquirerEnabledForRouting(string $acquirerName): bool
    {
        $isEnabledInDb = AcquirerConfig::where('acquirer_key', $acquirerName)
            ->where('enabled', true)
            ->exists();

        if ($isEnabledInDb) {
            return true;
        }

        return match ($acquirerName) {
            'payfort' => AcquirerFactory::isPayFortConfigured(),
            'blupay' => AcquirerFactory::isBluPayConfigured(),
            'enki' => AcquirerFactory::isEnkiConfigured(),
            'babylon' => AcquirerFactory::isBabylonConfigured(),
            'witetec' => AcquirerFactory::isWitetecConfigured(),
            'asaas' => AcquirerFactory::isAsaasConfigured(),
            'huntersub' => AcquirerFactory::isHunterSubConfigured(),
            'sharkhubsub' => AcquirerFactory::isSharkHubSubConfigured(),
            default => false,
        };
    }

    private function resolveLockedAccountAcquirerRouting(?string $accountId, string $paymentMethod): array
    {
        if (!$accountId) {
            return ['locked' => false, 'order' => []];
        }

        try {
            $normalizedMethod = match (strtolower(trim($paymentMethod))) {
                'card', 'cartao', 'creditcard' => 'credit_card',
                'pixout', 'pix-out', 'withdrawal', 'withdraw' => 'pix_out',
                default => strtolower(trim($paymentMethod)) ?: 'pix',
            };

            $assignments = AccountAcquirer::query()
                ->where('account_acquirers.account_id', $accountId)
                ->where('account_acquirers.is_active', true)
                ->join('acquirers', 'acquirers.id', '=', 'account_acquirers.acquirer_id')
                ->select('account_acquirers.*', 'acquirers.slug as acquirer_slug')
                ->orderBy('account_acquirers.priority', 'asc')
                ->get();

            if ($assignments->isEmpty()) {
                return ['locked' => false, 'order' => []];
            }

            $eligibleOrder = [];
            $hasDisabledAssignment = false;

            foreach ($assignments as $assignment) {
                $slug = strtolower((string) $assignment->acquirer_slug);
                if ($slug === '') {
                    continue;
                }

                $globalConfig = AcquirerConfig::where('acquirer_key', $slug)->first();
                if (!$globalConfig || !$globalConfig->enabled) {
                    $hasDisabledAssignment = true;
                    continue;
                }

                $supportedMethods = array_map('strtolower', $globalConfig->supported_methods ?? []);
                if ($supportedMethods !== [] && !in_array($normalizedMethod, $supportedMethods, true)) {
                    continue;
                }

                $eligibleOrder[] = $slug;
            }

            if ($eligibleOrder !== []) {
                return ['locked' => true, 'order' => $eligibleOrder];
            }

            if ($hasDisabledAssignment) {
                return [
                    'locked' => true,
                    'order' => [],
                    'error' => 'A adquirente configurada para este seller esta desativada.',
                ];
            }

            return [
                'locked' => true,
                'order' => [],
                'error' => 'Nenhuma adquirente configurada para este seller suporta este metodo de pagamento.',
            ];
        } catch (\Throwable $e) {
            Log::warning('Failed to resolve locked account acquirer routing', [
                'account_id' => $accountId,
                'payment_method' => $paymentMethod,
                'error' => $e->getMessage(),
            ]);

            return [
                'locked' => true,
                'order' => [],
                'error' => 'Erro ao validar a adquirente configurada para este seller.',
            ];
        }
    }

    /**
     * Process PIX payment with automatic failover between acquirers
     * 
     * Chain determined by DB priority (acquirer_configs table).
     * Default: PayFort (priority 0) → BluPay → Babylon → Mock
     * If the primary acquirer fails, automatically tries the next one.
     */
    private function processPixWithFallback(Charge $charge, string $primaryAcquirer, array $lockedAccountRouting = []): array
    {
        $rotationSettings = $this->rotationService?->getSettings() ?? [];
        $failoverEnabled = (bool) ($rotationSettings['failover_enabled'] ?? config('acquirers.routing.failover_enabled', true));
        $isAccountLocked = (bool) ($lockedAccountRouting['locked'] ?? false);

        // Build ordered acquirer list from account priority first, then global admin priority.
        $acquirerOrder = $isAccountLocked
            ? array_values(array_filter($lockedAccountRouting['order'] ?? []))
            : $this->factory->getPixAcquirerOrderForAccount($charge->account_id, $primaryAcquirer);

        if ($isAccountLocked && $acquirerOrder === []) {
            $charge->update(['status' => 'failed']);
            return [
                'success' => false,
                'error' => $lockedAccountRouting['error'] ?? 'Nenhuma adquirente configurada para este seller conseguiu processar o PIX.',
            ];
        }

        $lockedRemaining = $isAccountLocked ? count($acquirerOrder) : 0;
        $lastLockedError = null;

        foreach ($acquirerOrder as $acquirerName) {
            if ($isAccountLocked) {
                $lockedRemaining--;
            }

            $isEnabled = $this->isAcquirerEnabledForRouting($acquirerName);

            if (!$isEnabled) {
                Log::info('Acquirer disabled in database, skipping', [
                    'charge_id' => $charge->id,
                    'acquirer' => $acquirerName,
                ]);
                if ($isAccountLocked) {
                    $lastLockedError = 'A adquirente configurada para este seller esta desativada ou sem credenciais.';
                    if ($lockedRemaining <= 0) {
                        $charge->update(['status' => 'failed']);
                        return [
                            'success' => false,
                            'error' => $lastLockedError,
                        ];
                    }
                }
                continue;
            }

            if (!$this->isPixAmountAllowedForAcquirer($charge, $acquirerName)) {
                Log::info('PIX acquirer skipped by amount limits', [
                    'charge_id' => $charge->id,
                    'acquirer' => $acquirerName,
                    'amount' => $charge->amount,
                ]);
                if ($isAccountLocked) {
                    $lastLockedError = 'A adquirente configurada para este seller nao aceita o valor desta cobranca.';
                    if ($lockedRemaining <= 0) {
                        $charge->update(['status' => 'failed']);
                        return [
                            'success' => false,
                            'error' => $lastLockedError,
                        ];
                    }
                }
                continue;
            }

            $cb = $this->circuitBreakers[$acquirerName] ?? null;

            // Skip acquirers whose circuit breaker is open (fast-fail)
            if ($cb && !$cb->isAvailable()) {
                Log::warning('Circuit breaker OPEN — skipping acquirer', [
                    'charge_id' => $charge->id,
                    'acquirer'  => $acquirerName,
                ]);
                if ($isAccountLocked) {
                    $lastLockedError = 'A adquirente configurada para este seller esta temporariamente indisponivel.';
                    if ($lockedRemaining <= 0) {
                        $charge->update(['status' => 'failed']);
                        return [
                            'success' => false,
                            'error' => $lastLockedError,
                        ];
                    }
                }
                continue;
            }

            Log::info('Attempting PIX via acquirer', [
                'charge_id' => $charge->id,
                'acquirer' => $acquirerName,
                    'is_fallback' => !$isAccountLocked && $acquirerName !== $primaryAcquirer,
                    'account_locked' => $isAccountLocked,
                ]);

            $result = match ($acquirerName) {
                'payfort' => $this->factory->payfortForAccount($charge->account_id) ? $this->generatePixViaPayFort($charge) : null,
                'blupay' => $this->factory->blupay() ? $this->generatePixViaBluPay($charge) : null,
                'enki' => $this->factory->enkiForAccount($charge->account_id) ? $this->generatePixViaEnki($charge) : null,
                'babylon' => $this->isConfigured() ? $this->generatePix($charge) : null,
                'huntersub' => $this->factory->huntersubForAccount($charge->account_id) ? $this->generatePixViaHunterSub($charge) : null,
                'sharkhubsub' => $this->factory->sharkhubsubForAccount($charge->account_id) ? $this->generatePixViaSharkHubSub($charge) : null,
                default => null,
            };

            if ($result && ($result['success'] ?? false)) {
                $qrCode = $result['pix_qr_code'] ?? null;
                $copyPaste = $result['pix_copy_paste'] ?? $qrCode;

                if (empty($qrCode) && empty($copyPaste)) {
                    Log::error('PIX acquirer returned success but no QR code data', [
                        'charge_id' => $charge->id,
                        'acquirer' => $acquirerName,
                    ]);
                    $result = ['success' => false, 'error' => 'Adquirente não retornou dados do PIX'];
                } else {
                    $charge->update(['status' => 'pending']);

                    $cb?->recordSuccess();
                    $this->rotationService?->markSuccess('pix', $acquirerName);

                    if ($acquirerName !== $primaryAcquirer) {
                        Log::warning('PIX fallback succeeded', [
                            'charge_id' => $charge->id,
                            'primary' => $primaryAcquirer,
                            'fallback_used' => $acquirerName,
                        ]);
                    }
                    return $result;
                }
            }

            // Record failure in circuit breaker
            if ($result) {
                $cb?->recordFailure();
                $this->rotationService?->markFailure('pix', $acquirerName);
                Log::warning('PIX acquirer failed, trying next', [
                    'charge_id' => $charge->id,
                    'acquirer' => $acquirerName,
                    'error' => $result['error'] ?? 'Unknown error',
                    'account_locked' => $isAccountLocked,
                ]);
            }

            if ($isAccountLocked) {
                $lastLockedError = $result['error'] ?? 'A adquirente configurada para este seller nao conseguiu gerar a cobranca.';
                Log::warning('Account-specific PIX acquirer failed; fallback blocked', [
                    'charge_id' => $charge->id,
                    'account_id' => $charge->account_id,
                    'acquirer' => $acquirerName,
                    'error' => $result['error'] ?? 'Acquirer not configured',
                ]);

                if ($lockedRemaining <= 0) {
                    $charge->update(['status' => 'failed']);
                    return $result ?? [
                        'success' => false,
                        'error' => $lastLockedError,
                    ];
                }

                continue;
            }

            // If failover is disabled, don't try the next acquirer
            if (!$failoverEnabled) {
                Log::info('Failover disabled, not trying next acquirer', ['charge_id' => $charge->id]);
                return $result ?? ['success' => false, 'error' => 'Acquirer not configured'];
            }
        }

        if ($isAccountLocked) {
            $charge->update(['status' => 'failed']);
            return [
                'success' => false,
                'error' => $lastLockedError ?? 'Nenhuma adquirente configurada para este seller conseguiu processar o PIX.',
            ];
        }

        // All acquirers failed → return error
        Log::error('All PIX acquirers failed', ['charge_id' => $charge->id]);
        
        return [
            'success' => false,
            'error' => 'Nenhuma adquirente disponível conseguiu processar o pagamento. Tente novamente mais tarde.',
        ];
    }

    /**
     * Get ordered list of PIX acquirers to try
     * Primary acquirer goes first, then the others as fallbacks
     */
    private function getPixAcquirerOrder(string $primary): array
    {
        return $this->factory->getPixAcquirerOrder($primary);
    }

    private function isPixAmountAllowedForAcquirer(Charge $charge, string $acquirerName): bool
    {
        $amount = (int) ($charge->amount ?? 0);
        if ($amount <= 0) {
            return true;
        }

        $settings = [];

        try {
            $globalConfig = AcquirerConfig::where('acquirer_key', $acquirerName)->first();
            if (is_array($globalConfig?->settings)) {
                $settings = array_replace_recursive($settings, $globalConfig->settings);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to read acquirer amount settings', [
                'charge_id' => $charge->id,
                'acquirer' => $acquirerName,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            if ($charge->account_id) {
                $accountConfig = AccountAcquirer::query()
                    ->where('account_acquirers.account_id', $charge->account_id)
                    ->where('account_acquirers.is_active', true)
                    ->join('acquirers', 'acquirers.id', '=', 'account_acquirers.acquirer_id')
                    ->where('acquirers.slug', $acquirerName)
                    ->select('account_acquirers.settings')
                    ->first();

                if (is_array($accountConfig?->settings)) {
                    $settings = array_replace_recursive($settings, $accountConfig->settings);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to read account acquirer amount settings', [
                'charge_id' => $charge->id,
                'account_id' => $charge->account_id,
                'acquirer' => $acquirerName,
                'error' => $e->getMessage(),
            ]);
        }

        [$minAmount, $maxAmount] = $this->extractPixAmountLimits($settings);

        if ($minAmount !== null && $amount < $minAmount) {
            return false;
        }

        if ($maxAmount !== null && $amount > $maxAmount) {
            return false;
        }

        return true;
    }

    /**
     * Supported keys in acquirer/settings JSON:
     * - min_amount / max_amount
     * - min_amount_cents / max_amount_cents
     * - pix.min_amount / pix.max_amount
     * Values <= 10000 are treated as reais for convenience, larger values as cents.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function extractPixAmountLimits(array $settings): array
    {
        $pixSettings = is_array($settings['pix'] ?? null) ? $settings['pix'] : [];

        $min = $pixSettings['min_amount_cents']
            ?? $settings['min_amount_cents']
            ?? $pixSettings['min_amount']
            ?? $settings['min_amount']
            ?? null;

        $max = $pixSettings['max_amount_cents']
            ?? $settings['max_amount_cents']
            ?? $pixSettings['max_amount']
            ?? $settings['max_amount']
            ?? null;

        return [
            $this->normalizeAmountLimit($min),
            $this->normalizeAmountLimit($max),
        ];
    }

    private function normalizeAmountLimit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(['R$', ' ', '.'], '', $value);
            $value = str_replace(',', '.', $value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;
        if ($amount <= 0) {
            return null;
        }

        return (int) round($amount <= 10000 ? $amount * 100 : $amount);
    }

    /**
     * Generate PIX payment via PayFort Bank
     */
    public function generatePixViaPayFort(Charge $charge): array
    {
        if (!$this->factory->payfortForAccount($charge->account_id)) {
            Log::error('PayFort acquirer not initialized - check PAYFORT_API_KEY/PAYFORT_API_SECRET in .env OR configure credentials in Admin Panel');
            return [
                'success' => false,
                'error' => 'PayFort não configurado. Verifique as credenciais no .env ou no painel admin',
            ];
        }

        try {
            // Build a Charge-like object for the PayFort acquirer
            $result = $this->factory->payfortForAccount($charge->account_id)->generatePixQrCode($charge);

            if (!$result['success']) {
                Log::error('PayFort PIX failed', [
                    'charge_id' => $charge->id,
                    'error' => $result['error'] ?? 'Unknown error',
                    'raw_response' => $result['raw_response'] ?? null,
                ]);

                return $result;
            }

            // Update charge with PayFort PIX data
            $charge->update([
                'acquirer' => 'payfort',
                'acquirer_transaction_id' => $result['external_id'],
                'external_id' => $result['external_id'],
                'pix_qr_code' => $result['pix_qr_code'] ?? null,
                'pix_copy_paste' => $result['pix_copy_paste'] ?? $result['pix_qr_code'] ?? null,
                'pix_qr_code_url' => $result['pix_qr_code_url'] ?? null,
                'expires_at' => isset($result['data']['expiresAt'])
                    ? \Carbon\Carbon::parse($result['data']['expiresAt'])
                    : $this->defaultPixExpirationAt(),
            ]);

            // Create transaction record
            Transaction::create([
                'id' => Str::uuid()->toString(),
                'charge_id' => $charge->id,
                'acquirer_id' => $this->getPayFortAcquirerId(),
                'type' => 'authorization',
                'status' => 'pending',
                'amount' => $charge->amount,
                'net_amount' => $charge->amount,
                'acquirer_tid' => $result['external_id'],
            ]);

            Log::info('PayFort PIX generated', [
                'charge_id' => $charge->id,
                'transaction_id' => $result['external_id'],
            ]);

            return [
                'success' => true,
                'acquirer' => 'payfort',
                'transaction_id' => $result['external_id'],
                'status' => $result['status'] ?? 'pending',
                'pix_qr_code' => $result['pix_qr_code'] ?? null,
                'pix_copy_paste' => $result['pix_copy_paste'] ?? $result['pix_qr_code'] ?? null,
                'pix_qr_code_url' => $result['pix_qr_code_url'] ?? null,
                'expires_at' => $result['data']['expiresAt'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('PayFort PIX exception', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'PayFort PIX error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get or create the PayFort acquirer ID
     */
    private function getPayFortAcquirerId(): string
    {
        static $payfortAcquirerId = null;

        if ($payfortAcquirerId) {
            return $payfortAcquirerId;
        }

        $acquirer = Acquirer::where('slug', 'payfort')->first();

        if (!$acquirer) {
            $acquirer = Acquirer::create([
                'name' => 'PayFort Bank',
                'slug' => 'payfort',
                'is_active' => true,
                'supported_methods' => ['pix'],
                'settings' => [],
            ]);
        }

        $payfortAcquirerId = $acquirer->id;
        return $payfortAcquirerId;
    }

    /**
     * Send PIX transfer via PayFort Bank (PIX OUT / withdrawals)
     */
    public function sendPixTransferViaPayFort(array $transferData): array
    {
        if (!$this->factory->payfort()) {
            return ['success' => false, 'error' => 'PayFort not configured'];
        }

        return $this->factory->payfort()->sendPixTransfer($transferData);
    }

    /**
     * Generate PIX payment via BluPay IP
     */
    public function generatePixViaBluPay(Charge $charge): array
    {
        if (!$this->factory->blupay()) {
            Log::error('BluPay acquirer not initialized - check BLUPAY_ENABLED, BLUPAY_SECRET_KEY, BLUPAY_PUBLIC_KEY in .env');
            return [
                'success' => false,
                'error' => 'BluPay não configurado. Verifique as credenciais no .env',
            ];
        }

        try {
            $result = $this->factory->blupay()->generatePixQrCode($charge);

            if (!$result['success']) {
                Log::error('BluPay PIX failed', [
                    'charge_id' => $charge->id,
                    'error' => $result['error'] ?? 'Unknown error',
                    'raw_response' => $result['raw_response'] ?? null,
                ]);

                return $result;
            }

            // Update charge with BluPay PIX data
            $charge->update([
                'acquirer' => 'blupay',
                'acquirer_transaction_id' => $result['external_id'],
                'external_id' => $result['external_id'],
                'pix_qr_code' => $result['pix_qr_code'] ?? null,
                'pix_copy_paste' => $result['pix_copy_paste'] ?? $result['pix_qr_code'] ?? null,
                'pix_qr_code_url' => $result['pix_qr_code_url'] ?? null,
                'expires_at' => isset($result['pix_expires_at'])
                    ? \Carbon\Carbon::parse($result['pix_expires_at'])
                    : $this->defaultPixExpirationAt(),
            ]);

            // Create transaction record
            Transaction::create([
                'id' => Str::uuid()->toString(),
                'charge_id' => $charge->id,
                'acquirer_id' => $this->getBluPayAcquirerId(),
                'type' => 'authorization',
                'status' => 'pending',
                'amount' => $charge->amount,
                'net_amount' => $charge->amount,
                'acquirer_tid' => $result['external_id'],
            ]);

            Log::info('BluPay PIX generated', [
                'charge_id' => $charge->id,
                'transaction_id' => $result['external_id'],
            ]);

            return [
                'success' => true,
                'acquirer' => 'blupay',
                'transaction_id' => $result['external_id'],
                'status' => $result['status'] ?? 'pending',
                'pix_qr_code' => $result['pix_qr_code'] ?? null,
                'pix_copy_paste' => $result['pix_copy_paste'] ?? $result['pix_qr_code'] ?? null,
                'pix_qr_code_url' => $result['pix_qr_code_url'] ?? null,
                'expires_at' => $result['pix_expires_at'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('BluPay PIX exception', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'BluPay PIX error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get or create the BluPay acquirer ID
     */
    private function getBluPayAcquirerId(): string
    {
        static $blupayAcquirerId = null;

        if ($blupayAcquirerId) {
            return $blupayAcquirerId;
        }

        $acquirer = Acquirer::where('slug', 'blupay')->first();

        if (!$acquirer) {
            $acquirer = Acquirer::create([
                'name' => 'BluPay IP',
                'slug' => 'blupay',
                'is_active' => true,
                'supported_methods' => ['pix'],
                'settings' => [],
            ]);
        }

        $blupayAcquirerId = $acquirer->id;
        return $blupayAcquirerId;
    }

    /**
     * Send PIX transfer via BluPay IP (PIX OUT / withdrawals)
     */
    public function sendPixTransferViaBluPay(array $transferData): array
    {
        if (!$this->factory->blupay()) {
            return ['success' => false, 'error' => 'BluPay not configured'];
        }

        return $this->factory->blupay()->sendPixTransfer($transferData);
    }

    /**
     * Generate PIX payment via Enki Bank
     */
    public function generatePixViaEnki(Charge $charge): array
    {
        $enki = $this->factory->enkiForAccount($charge->account_id);

        if (!$enki) {
            Log::error('Enki not configured', ['charge_id' => $charge->id]);
            return [
                'success' => false,
                'error' => 'Enki não configurada. Verifique as credenciais no painel admin.',
            ];
        }

        try {
            $result = $enki->generatePixQrCode($charge);

            if (!$result['success']) {
                Log::error('Enki PIX failed', [
                    'charge_id' => $charge->id,
                    'error' => $result['error'] ?? 'Unknown error',
                    'raw_response' => $result['raw_response'] ?? null,
                ]);

                return $result;
            }

            $charge->update([
                'acquirer' => 'enki',
                'acquirer_transaction_id' => $result['external_id'] ?? null,
                'external_id' => $result['external_id'] ?? null,
                'pix_qr_code' => $result['pix_qr_code'] ?? null,
                'pix_copy_paste' => $result['pix_copy_paste'] ?? $result['pix_qr_code'] ?? null,
                'pix_qr_code_url' => $result['pix_qr_code_url'] ?? null,
                'expires_at' => isset($result['pix_expires_at'])
                    ? \Carbon\Carbon::parse($result['pix_expires_at'])
                    : $this->defaultPixExpirationAt(),
                'status' => $result['status'] ?? 'pending',
            ]);

            Transaction::create([
                'id' => Str::uuid()->toString(),
                'charge_id' => $charge->id,
                'acquirer_id' => $this->getOrCreateAcquirerId('enki', 'Enki Bank'),
                'type' => 'authorization',
                'status' => 'pending',
                'amount' => $charge->amount,
                'net_amount' => $charge->amount,
                'acquirer_tid' => $result['external_id'] ?? null,
            ]);

            return [
                'success' => true,
                'acquirer' => 'enki',
                'transaction_id' => $result['external_id'] ?? null,
                'status' => $result['status'] ?? 'pending',
                'pix_qr_code' => $result['pix_qr_code'] ?? null,
                'pix_copy_paste' => $result['pix_copy_paste'] ?? $result['pix_qr_code'] ?? null,
                'pix_qr_code_url' => $result['pix_qr_code_url'] ?? null,
                'expires_at' => $result['pix_expires_at'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('Enki PIX exception', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'Enki PIX error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Send PIX transfer via Enki Bank (PIX OUT / withdrawals)
     */
    public function sendPixTransferViaEnki(array $transferData): array
    {
        $enki = $this->factory->enkiForAccount($transferData['metadata']['account_id'] ?? null);

        if (!$enki) {
            return ['success' => false, 'error' => 'Enki not configured'];
        }

        return $enki->sendPixTransfer($transferData);
    }

    /**
     * Generate PIX via HunterSub V2
     */
    public function generatePixViaHunterSub(Charge $charge): array
    {
        $huntersub = $this->factory->huntersubForAccount($charge->account_id);
        
        if (!$huntersub) {
            Log::error('HunterSub not configured', ['charge_id' => $charge->id]);
            return [
                'success' => false,
                'error' => 'HunterSub not configured',
            ];
        }

        try {
            Log::info('HunterSub PIX request', ['charge_id' => $charge->id]);
            
            $result = $huntersub->generatePixQrCode($charge);
            
            Log::info('HunterSub PIX result', [
                'charge_id' => $charge->id,
                'success' => $result['success'] ?? false,
                'error' => $result['error'] ?? null,
            ]);
            
            if (!$result['success']) {
                Log::error('HunterSub PIX failed', [
                    'charge_id' => $charge->id,
                    'error' => $result['error'] ?? 'Unknown error',
                    'raw_response' => $result['raw_response'] ?? null,
                ]);
                return $result;
            }
            
            // Update charge with HunterSub data
            $charge->update([
                'acquirer' => 'huntersub',
                'acquirer_transaction_id' => $result['external_id'] ?? null,
                'external_id' => $result['external_id'] ?? null,
                'pix_qr_code' => $result['pix_qr_code'] ?? null,
                'pix_copy_paste' => $result['pix_copy_paste'] ?? $result['pix_qr_code'] ?? null,
                'pix_qr_code_url' => $result['pix_qr_code_url'] ?? null,
                'pix_end2end_id' => $result['end2end_id'] ?? null,
                'expires_at' => isset($result['pix_expires_at']) 
                    ? \Carbon\Carbon::parse($result['pix_expires_at']) 
                    : $this->defaultPixExpirationAt(),
                'status' => $result['status'] ?? 'pending',
            ]);
            
            Log::info('HunterSub PIX success', [
                'charge_id' => $charge->id,
                'transaction_id' => $result['external_id'] ?? null,
            ]);
            
            return [
                'success' => true,
                'acquirer' => 'huntersub',
                'transaction_id' => $result['external_id'] ?? null,
                'status' => $result['status'] ?? 'pending',
                'pix_qr_code' => $result['pix_qr_code'] ?? null,
                'pix_copy_paste' => $result['pix_copy_paste'] ?? $result['pix_qr_code'] ?? null,
                'pix_qr_code_url' => $result['pix_qr_code_url'] ?? null,
                'expires_at' => $result['pix_expires_at'] ?? null,
            ];
            
        } catch (\Exception $e) {
            Log::error('HunterSub PIX exception', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function generatePixViaSharkHubSub(Charge $charge): array
    {
        $sharkhub = $this->factory->sharkhubsubForAccount($charge->account_id);

        if (!$sharkhub) {
            Log::error('SharkHubSub not configured', ['charge_id' => $charge->id]);
            return [
                'success' => false,
                'error' => 'SharkHub Subadquirente não configurada',
            ];
        }

        try {
            Log::info('SharkHubSub PIX request', ['charge_id' => $charge->id]);

            $result = $sharkhub->generatePixQrCode($charge);

            if (!($result['success'] ?? false)) {
                Log::error('SharkHubSub PIX failed', [
                    'charge_id' => $charge->id,
                    'error' => $result['error'] ?? 'Unknown error',
                    'raw_response' => $result['raw_response'] ?? null,
                ]);

                return $result;
            }

            $charge->update([
                'acquirer' => 'sharkhubsub',
                'acquirer_transaction_id' => $result['external_id'] ?? null,
                'external_id' => $result['external_id'] ?? null,
                'pix_qr_code' => $result['pix_qr_code'] ?? null,
                'pix_copy_paste' => $result['pix_copy_paste'] ?? $result['pix_qr_code'] ?? null,
                'pix_qr_code_url' => $result['pix_qr_code_url'] ?? null,
                'pix_end2end_id' => $result['end2end_id'] ?? null,
                'expires_at' => isset($result['pix_expires_at'])
                    ? \Carbon\Carbon::parse($result['pix_expires_at'])
                    : $this->defaultPixExpirationAt(),
                'status' => $result['status'] ?? 'pending',
            ]);

            Transaction::create([
                'id' => Str::uuid()->toString(),
                'charge_id' => $charge->id,
                'acquirer_id' => $this->getOrCreateAcquirerId('sharkhubsub', 'SharkHub Subadquirente'),
                'type' => 'authorization',
                'status' => 'pending',
                'amount' => $charge->amount,
                'net_amount' => $charge->amount,
                'acquirer_tid' => $result['external_id'] ?? null,
                'gateway_response' => $result['data'] ?? null,
            ]);

            return [
                'success' => true,
                'acquirer' => 'sharkhubsub',
                'transaction_id' => $result['external_id'] ?? null,
                'status' => $result['status'] ?? 'pending',
                'pix_qr_code' => $result['pix_qr_code'] ?? null,
                'pix_copy_paste' => $result['pix_copy_paste'] ?? $result['pix_qr_code'] ?? null,
                'pix_qr_code_url' => $result['pix_qr_code_url'] ?? null,
                'expires_at' => $result['pix_expires_at'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('SharkHubSub PIX exception', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate PIX payment via Babylon
     */
    public function generatePix(Charge $charge): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Nenhuma adquirente configurada para processar PIX. Configure as credenciais no painel admin.',
            ];
        }

        try {
            $customer = $charge->customer ?? [];
            $billing = $charge->billing ?? [];
            $metadata = $charge->metadata ?? [];

            $payload = [
                'paymentMethod' => 'PIX',
                'amount' => $charge->amount,
                'description' => $charge->description ?? 'Pagamento via PIX',
                'customer' => [
                    'name' => $customer['name'] ?? 'Cliente',
                    'email' => $customer['email'] ?? 'cliente@email.com',
                    'document' => $customer['document'] ?? '',
                    'phone' => $customer['phone'] ?? null,
                ],
                'postbackUrl' => config('app.url') . '/api/v1/webhooks/babylon',
                'metadata' => [
                    'charge_id' => $charge->id,
                    'account_id' => $charge->account_id,
                    'checkout_id' => $charge->checkout_id,
                    'product_id' => $charge->product_id,
                    ...$metadata,
                ],
            ];

            // Add shipping if available
            if (!empty($billing)) {
                $payload['shipping'] = [
                    'street' => $billing['street'] ?? '',
                    'streetNumber' => $billing['number'] ?? '',
                    'neighborhood' => $billing['neighborhood'] ?? '',
                    'city' => $billing['city'] ?? '',
                    'state' => $billing['state'] ?? '',
                    'zipCode' => $billing['zip_code'] ?? '',
                    'complement' => $billing['complement'] ?? '',
                ];
            }

            // Add items if available in metadata
            if (isset($metadata['products']) && is_array($metadata['products'])) {
                $payload['items'] = array_map(fn($item) => [
                    'title' => $item['name'] ?? 'Produto',
                    'unitPrice' => $item['price'] ?? $charge->amount,
                    'quantity' => $item['quantity'] ?? 1,
                ], $metadata['products']);
            } else {
                $payload['items'] = [[
                    'title' => $charge->description ?? 'Produto',
                    'unitPrice' => $charge->amount,
                    'quantity' => 1,
                ]];
            }

            Log::info('Babylon PIX request', ['charge_id' => $charge->id]);

            $response = $this->getHttpClient()->post("{$this->baseUrl}/transactions", $payload);

            Log::info('Babylon PIX response', [
                'charge_id' => $charge->id,
                'status' => $response->status(),
            ]);

            if (!$response->successful()) {
                $error = $response->json();
                Log::error('Babylon PIX error', [
                    'charge_id' => $charge->id,
                    'error' => $error,
                ]);

                return [
                    'success' => false,
                    'error' => $error['message'] ?? 'Falha ao gerar PIX',
                ];
            }

            $data = $response->json();
            $pixData = $data['pix'] ?? [];

            // Update charge with PIX data
            $charge->update([
                'acquirer' => 'babylon',
                'acquirer_transaction_id' => $data['id'],
                'external_id' => $data['id'],
                'pix_qr_code' => $pixData['qrcode'] ?? null,
                'pix_copy_paste' => $pixData['qrcode'] ?? null,
                'pix_end2end_id' => $pixData['end2EndId'] ?? null,
                'expires_at' => isset($pixData['expirationDate']) 
                    ? \Carbon\Carbon::parse($pixData['expirationDate']) 
                    : $this->defaultPixExpirationAt(),
            ]);

            // Create transaction record
            Transaction::create([
                'id' => Str::uuid()->toString(),
                'charge_id' => $charge->id,
                'acquirer_id' => $this->getAcquirerId(),
                'type' => 'authorization',
                'status' => 'pending',
                'amount' => $charge->amount,
                'net_amount' => $charge->amount,
                'acquirer_tid' => $data['id'],
            ]);

            return [
                'success' => true,
                'acquirer' => 'babylon',
                'transaction_id' => $data['id'],
                'status' => $data['status'] ?? 'waiting_payment',
                'pix_qr_code' => $pixData['qrcode'] ?? null,
                'pix_copy_paste' => $pixData['qrcode'] ?? null,
                'expires_at' => $pixData['expirationDate'] ?? null,
                'end2end_id' => $pixData['end2EndId'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('Babylon PIX exception', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate Boleto payment via Babylon
     */
    public function generateBoleto(Charge $charge): array
    {
        // Check if Babylon is enabled in database
        $isBabylonEnabled = AcquirerConfig::where('acquirer_key', 'babylon')
            ->where('enabled', true)
            ->exists();

        if (!$isBabylonEnabled || !$this->isConfigured()) {
            Log::error('Babylon disabled or not configured for Boleto', [
                'charge_id' => $charge->id,
                'enabled' => $isBabylonEnabled,
                'configured' => $this->isConfigured(),
            ]);
            return [
                'success' => false,
                'error' => 'Nenhuma adquirente disponível para processar Boleto. Entre em contato com o suporte.',
            ];
        }

        try {
            $customer = $charge->customer ?? [];
            $billing = $charge->billing ?? [];

            $payload = [
                'paymentMethod' => 'BOLETO',
                'amount' => $charge->amount,
                'description' => $charge->description ?? 'Pagamento via Boleto',
                'customer' => [
                    'name' => $customer['name'] ?? 'Cliente',
                    'email' => $customer['email'] ?? 'cliente@email.com',
                    'document' => $customer['document'] ?? '',
                    'phone' => $customer['phone'] ?? null,
                ],
                'postbackUrl' => config('app.url') . '/api/v1/webhooks/babylon',
                'metadata' => [
                    'charge_id' => $charge->id,
                    'account_id' => $charge->account_id,
                ],
            ];

            if (!empty($billing)) {
                $payload['shipping'] = [
                    'street' => $billing['street'] ?? '',
                    'streetNumber' => $billing['number'] ?? '',
                    'neighborhood' => $billing['neighborhood'] ?? '',
                    'city' => $billing['city'] ?? '',
                    'state' => $billing['state'] ?? '',
                    'zipCode' => $billing['zip_code'] ?? '',
                    'complement' => $billing['complement'] ?? '',
                ];
            }

            $response = $this->getHttpClient()->post("{$this->baseUrl}/transactions", $payload);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Falha ao gerar Boleto',
                ];
            }

            $data = $response->json();
            $boletoData = $data['boleto'] ?? [];

            $charge->update([
                'acquirer' => 'babylon',
                'acquirer_transaction_id' => $data['id'],
                'external_id' => $data['id'],
                'boleto_url' => $boletoData['url'] ?? null,
                'boleto_barcode' => $boletoData['barcode'] ?? null,
                'boleto_digitable_line' => $boletoData['digitableLine'] ?? null,
                'expires_at' => isset($boletoData['expirationDate']) 
                    ? \Carbon\Carbon::parse($boletoData['expirationDate']) 
                    : now()->addDays(3),
            ]);

            Transaction::create([
                'id' => Str::uuid()->toString(),
                'charge_id' => $charge->id,
                'acquirer_id' => $this->getAcquirerId(),
                'type' => 'authorization',
                'status' => 'pending',
                'amount' => $charge->amount,
                'net_amount' => $charge->amount,
                'acquirer_tid' => $data['id'],
            ]);

            return [
                'success' => true,
                'acquirer' => 'babylon',
                'transaction_id' => $data['id'],
                'status' => $data['status'] ?? 'waiting_payment',
                'boleto_url' => $boletoData['url'] ?? null,
                'boleto_barcode' => $boletoData['barcode'] ?? null,
                'boleto_digitable_line' => $boletoData['digitableLine'] ?? null,
                'expires_at' => $boletoData['expirationDate'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('Babylon Boleto exception', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Route credit card payment to the appropriate acquirer.
     * Priority: Asaas (if configured) → Babylon (legacy fallback)
     */
    private function processCardWithRouting(Charge $charge, array $cardData, string $acquirer): array
    {
        // Respect the globally selected primary acquirer for card routing.
        if ($acquirer === 'asaas') {
            $result = $this->processCardViaAsaas($charge, $cardData);
            if ($result['success'] || $acquirer === 'asaas') {
                return $result;
            }
            // Asaas failed and isn't the primary → fallback to Babylon
            Log::warning('Asaas card payment failed, falling back to Babylon', [
                'charge_id' => $charge->id,
                'error' => $result['error'] ?? 'unknown',
            ]);
        }

        return $this->processCard($charge, $cardData);
    }

    /**
     * Process credit card payment via Asaas
     */
    private function processCardViaAsaas(Charge $charge, array $cardData): array
    {
        try {
            $asaas = $this->factory->asaas();
            if (!$asaas) {
                return ['success' => false, 'error' => 'Asaas acquirer not configured'];
            }

            $result = $asaas->processPayment($charge, $cardData);

            if (!$result['success']) {
                return $result;
            }

            $externalId = $result['external_id'] ?? $result['data']['id'] ?? null;
            $status = $result['status'] ?? 'pending';
            $asaasData = $result['data'] ?? [];

            $charge->update([
                'acquirer' => 'asaas',
                'acquirer_transaction_id' => $externalId,
                'external_id' => $externalId,
                'status' => $status,
                'paid_at' => $status === 'approved' ? now() : null,
            ]);

            Transaction::create([
                'id' => Str::uuid()->toString(),
                'charge_id' => $charge->id,
                'acquirer_id' => $this->getOrCreateAcquirerId('asaas', 'Asaas'),
                'type' => $status === 'approved' ? 'capture' : 'authorization',
                'status' => $status === 'approved' ? 'approved' : 'pending',
                'amount' => $charge->amount,
                'net_amount' => $charge->amount,
                'acquirer_tid' => $externalId,
            ]);

            // If instantly approved (Asaas may confirm immediately for credit card)
            if ($status === 'approved') {
                $this->updateWalletOnPayment($charge);

                try {
                    $splitService = app(SplitService::class);
                    $splitService->createSplitsForCharge($charge);
                } catch (\Exception $e) {
                    Log::error('Auto-split creation failed after Asaas card payment', [
                        'charge_id' => $charge->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $this->dispatchUtmifyEvent($charge);
                $this->dispatchN8nEvent('payment.approved', $charge);
                $this->dispatchAccountWebhook('payment.approved', $charge);
                $this->dispatchAccountWebhook('charge.paid', $charge);
            }

            return [
                'success' => true,
                'acquirer' => 'asaas',
                'transaction_id' => $externalId,
                'status' => $status,
            ];
        } catch (\Exception $e) {
            Log::error('Asaas card exception', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process credit card payment (Babylon - legacy)
     */
    public function processCard(Charge $charge, array $cardData): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Nenhuma adquirente configurada para processar cartão. Configure as credenciais no painel admin.',
            ];
        }

        try {
            $customer = $charge->customer ?? [];

            $payload = [
                'paymentMethod' => 'CREDIT_CARD',
                'amount' => $charge->amount,
                'installments' => $charge->installments ?? 1,
                'description' => $charge->description ?? 'Pagamento com Cartão',
                'customer' => [
                    'name' => $customer['name'] ?? 'Cliente',
                    'email' => $customer['email'] ?? 'cliente@email.com',
                    'document' => $customer['document'] ?? '',
                    'phone' => $customer['phone'] ?? null,
                ],
                'postbackUrl' => config('app.url') . '/api/v1/webhooks/babylon',
                'metadata' => [
                    'charge_id' => $charge->id,
                    'account_id' => $charge->account_id,
                ],
            ];

            // Use token_id if available (from frontend tokenization)
            if (isset($cardData['token_id'])) {
                $payload['card'] = [
                    'token_id' => $cardData['token_id'],
                ];
            } else {
                $payload['card'] = [
                    'number' => $cardData['number'] ?? $cardData['card_number'] ?? '',
                    'holderName' => $cardData['holder_name'] ?? $cardData['holderName'] ?? '',
                    'expMonth' => (int) ($cardData['exp_month'] ?? $cardData['expMonth'] ?? 1),
                    'expYear' => (int) ($cardData['exp_year'] ?? $cardData['expYear'] ?? 2030),
                    'cvv' => $cardData['cvv'] ?? '',
                ];
            }

            $response = $this->getHttpClient()->post("{$this->baseUrl}/transactions", $payload);

            if (!$response->successful()) {
                $error = $response->json();
                return [
                    'success' => false,
                    'error' => $error['message'] ?? $error['refusedReason'] ?? 'Pagamento recusado',
                ];
            }

            $data = $response->json();
            $status = $data['status'] ?? 'pending';

            $charge->update([
                'acquirer' => 'babylon',
                'acquirer_transaction_id' => $data['id'],
                'external_id' => $data['id'],
                'status' => $this->mapBabylonStatus($status),
                'paid_at' => $status === 'paid' ? now() : null,
            ]);

            Transaction::create([
                'id' => Str::uuid()->toString(),
                'charge_id' => $charge->id,
                'acquirer_id' => $this->getAcquirerId(),
                'type' => $status === 'paid' ? 'capture' : 'authorization',
                'status' => $status === 'paid' ? 'approved' : 'pending',
                'amount' => $charge->amount,
                'net_amount' => $charge->amount,
                'acquirer_tid' => $data['id'],
            ]);

            // If paid, update wallet and notify UTMify
            if ($status === 'paid') {
                $this->updateWalletOnPayment($charge);

                // Automatic split creation
                try {
                    $splitService = app(SplitService::class);
                    $splitService->createSplitsForCharge($charge);
                } catch (\Exception $e) {
                    Log::error('Auto-split creation failed after card payment', [
                        'charge_id' => $charge->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Dispatch UTMify tracking for immediate approval (credit card)
                $this->dispatchUtmifyEvent($charge);

                // Dispatch webhooks for immediate card approval
                $this->dispatchN8nEvent('payment.approved', $charge);
                $this->dispatchAccountWebhook('payment.approved', $charge);
                $this->dispatchAccountWebhook('charge.paid', $charge);
            }

            return [
                'success' => $status === 'paid' || $status === 'authorized',
                'acquirer' => 'babylon',
                'transaction_id' => $data['id'],
                'status' => $status,
            ];

        } catch (\Exception $e) {
            Log::error('Babylon Card exception', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process refund
     */
    public function refund(Charge $charge, ?int $amount = null): array
    {
        if (!$this->isConfigured() || !$charge->acquirer_transaction_id) {
            return ['success' => false, 'error' => 'Transação não encontrada'];
        }

        try {
            $refundAmount = $amount ?? $charge->amount;

            $response = $this->getHttpClient()->delete("{$this->baseUrl}/transactions/{$charge->acquirer_transaction_id}", [
                'amount' => $refundAmount,
            ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->json()['message'] ?? 'Falha no estorno',
                ];
            }

            $charge->update([
                'status' => $refundAmount >= $charge->amount ? 'refunded' : 'partially_refunded',
                'refunded_amount' => ($charge->refunded_amount ?? 0) + $refundAmount,
                'refunded_at' => now(),
            ]);

            // Update wallet
            $this->updateWalletOnRefund($charge, $refundAmount);

            return [
                'success' => true,
                'refunded_amount' => $refundAmount,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle webhook payment confirmation
     */
    public function handleWebhookPayment(Charge $charge, array $webhookData): void
    {
        $status = $webhookData['status'] ?? 'unknown';
        $normalizedStatus = strtolower((string) $status);
        $transactionId = $webhookData['id'] ?? $charge->acquirer_transaction_id;
        $previousStatus = $charge->status;

        Log::info('Processing webhook payment', [
            'charge_id' => $charge->id,
            'status' => $status,
            'transaction_id' => $transactionId,
        ]);

        $mappedStatus = $this->mapBabylonStatus($status);

        // Refresh charge from DB to get latest status (avoid stale data from in-memory model)
        $charge->refresh();

        Log::info('handleWebhookPayment status check', [
            'charge_id' => $charge->id,
            'webhook_status' => $status,
            'mapped_status' => $mappedStatus,
            'current_charge_status' => $charge->status,
        ]);

        $isPaymentConfirmed = in_array($normalizedStatus, self::CONFIRMED_WEBHOOK_STATUSES, true)
            || $mappedStatus === 'approved';

        if ($isPaymentConfirmed && $charge->status !== 'approved') {
            // Step 1: Update charge status + transaction record (CRITICAL — must always succeed)
            DB::transaction(function () use ($charge, $transactionId, $webhookData) {
                // Re-lock charge to prevent concurrent webhook processing
                $lockedCharge = Charge::lockForUpdate()->find($charge->id);
                if (!$lockedCharge || $lockedCharge->status === 'approved') {
                    return; // Already approved by concurrent webhook
                }

                // Store PIX end2end data from webhook if available
                $updateData = [
                    'status' => 'approved',
                    'paid_at' => now(),
                    'acquirer_transaction_id' => $transactionId,
                ];

                // Extract PIX-specific fields from webhook
                $pixData = $webhookData['pix'] ?? $webhookData['pixData'] ?? [];
                if (!empty($pixData['endToEndId'])) {
                    $updateData['pix_end2end_id'] = $pixData['endToEndId'];
                } elseif (!empty($pixData['end2EndId'])) {
                    $updateData['pix_end2end_id'] = $pixData['end2EndId'];
                } elseif (!empty($webhookData['endToEndId'])) {
                    $updateData['pix_end2end_id'] = $webhookData['endToEndId'];
                } elseif (!empty($webhookData['end2EndId'])) {
                    $updateData['pix_end2end_id'] = $webhookData['end2EndId'];
                }

                $lockedCharge->update($updateData);

                // Update transaction
                Transaction::where('charge_id', $lockedCharge->id)
                    ->where('type', 'authorization')
                    ->update([
                        'status' => 'approved',
                        'type' => 'capture',
                    ]);
            });

            // Refresh charge after status update transaction
            $charge->refresh();

            // Step 2: Update wallet (SEPARATE transaction — charge stays approved even if this fails)
            if ($charge->status === 'approved') {
                try {
                    $this->updateWalletOnPayment($charge);
                } catch (\Exception $e) {
                    Log::error('Wallet update failed after charge approval — charge is approved but wallet NOT credited', [
                        'charge_id' => $charge->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Charge is already approved. Wallet credit can be retried manually
                    // via php artisan charges:fix-stuck or the admin panel.
                }

                // Update checkout revenue
                $this->updateCheckoutOnPayment($charge);

                // Automatic split creation
                try {
                    $splitService = app(SplitService::class);
                    $splitService->createSplitsForCharge($charge);
                } catch (\Exception $e) {
                    Log::error('Auto-split creation failed after charge approval', [
                        'charge_id' => $charge->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                Log::info('Charge marked as paid via webhook', [
                    'charge_id' => $charge->id,
                    'payment_method' => $charge->payment_method,
                    'amount' => $charge->amount,
                    'acquirer_transaction_id' => $transactionId,
                ]);

                try {
                    app(\App\Services\PixNominalNameService::class)->syncFromCharge($charge);
                } catch (\Throwable $e) {
                    Log::warning('Failed to sync nominal name from webhook', [
                        'charge_id' => $charge->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Dispatch ALL notifications asynchronously via job
                \App\Jobs\DispatchPaymentNotificationsJob::dispatch($charge->id, 'payment.approved');
            }
        } elseif ($isPaymentConfirmed && $charge->status === 'approved') {
            // Duplicate webhook — charge already approved, but still notify UTMify
            // in case the first notification failed
            Log::info('Duplicate paid webhook — re-dispatching UTMify', ['charge_id' => $charge->id]);
            $this->dispatchUtmifyEvent($charge);
        } elseif (in_array($normalizedStatus, ['refused', 'failed', 'declined'], true)) {
            $charge->update([
                'status' => 'failed',
                'failure_reason' => $webhookData['refusedReason'] ?? $webhookData['refused_reason'] ?? 'Pagamento recusado',
            ]);
            \App\Jobs\DispatchPaymentNotificationsJob::dispatch($charge->id, 'payment.rejected');
        } elseif ($normalizedStatus === 'expired') {
            // Prevent terminal regression: keep approved/refunded/chargeback charges as-is.
            if (!in_array($charge->status, ['approved', 'refunded', 'chargeback'], true)) {
                $charge->update(['status' => 'expired']);
                \App\Jobs\DispatchPaymentNotificationsJob::dispatch($charge->id, 'payment.expired');
            }
        } elseif (in_array($normalizedStatus, ['canceled', 'cancelled'], true)) {
            $charge->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
            \App\Jobs\DispatchPaymentNotificationsJob::dispatch($charge->id, 'payment.cancelled');
        } elseif ($normalizedStatus === 'refunded') {
            $refundedAmount = $webhookData['refundedAmount'] ?? $webhookData['refunded_amount'] ?? $charge->amount;
            $charge->update([
                'status' => 'refunded',
                'refunded_amount' => $refundedAmount,
                'refunded_at' => now(),
            ]);
            $this->updateWalletOnRefund($charge, $refundedAmount);
            \App\Jobs\DispatchPaymentNotificationsJob::dispatch($charge->id, 'payment.refunded');
        } elseif (in_array($normalizedStatus, ['chargeback', 'chargedback'], true)) {
            $charge->update(['status' => 'chargeback']);
            $this->updateWalletOnRefund($charge, $charge->amount);
            \App\Jobs\DispatchPaymentNotificationsJob::dispatch($charge->id, 'payment.chargedback');
        } elseif ($normalizedStatus === 'waiting_payment') {
            // PIX/Boleto generated, waiting for payment
            Log::info('Charge waiting for payment', ['charge_id' => $charge->id]);
            $this->dispatchUtmifyEvent($charge);
        }
    }

    /**
     * Update checkout analytics when payment is confirmed (via webhook)
     * This handles PIX/Boleto payments that are confirmed asynchronously
     */
    public function updateCheckoutOnPayment(Charge $charge): void
    {
        if (!$charge->checkout_id) {
            return;
        }

        try {
            $checkout = Checkout::find($charge->checkout_id);
            if ($checkout) {
                // For PIX/Boleto: conversion + revenue weren't counted at checkout time, count now
                if (in_array($charge->payment_method, ['pix', 'boleto'])) {
                    $checkout->incrementConversions($charge->amount);
                    Log::info('Checkout conversion + revenue updated on PIX/Boleto payment', [
                        'checkout_id' => $checkout->id,
                        'charge_id' => $charge->id,
                        'amount' => $charge->amount,
                        'payment_method' => $charge->payment_method,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to update checkout on payment', [
                'checkout_id' => $charge->checkout_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send email notifications when a payment is approved
     */
    protected function sendPaymentApprovedEmails(Charge $charge): void
    {
        try {
            // 1. Send email to the merchant (account owner)
            $account = $charge->account ?? \App\Models\Account::find($charge->account_id);

            if ($account) {
                $merchantUsers = \App\Models\User::where('account_id', $account->id)->get();

                Log::info('Sending merchant approved emails', [
                    'charge_id' => $charge->id,
                    'account_id' => $account->id,
                    'merchant_count' => $merchantUsers->count(),
                ]);

                foreach ($merchantUsers as $merchantUser) {
                    if ($merchantUser->email) {
                        try {
                            Mail::to($merchantUser->email)->queue(
                                new PaymentApprovedMerchantMail($charge, $merchantUser->name ?? $account->name)
                            );
                            Log::info('Merchant email queued', [
                                'charge_id' => $charge->id,
                                'email' => $merchantUser->email,
                            ]);
                        } catch (\Exception $e) {
                            // Fallback to sync send if queue fails
                            Log::warning('Queue failed, sending sync merchant email', [
                                'charge_id' => $charge->id,
                                'email' => $merchantUser->email,
                                'error' => $e->getMessage(),
                            ]);
                            Mail::to($merchantUser->email)->send(
                                new PaymentApprovedMerchantMail($charge, $merchantUser->name ?? $account->name)
                            );
                        }
                    }
                }
            } else {
                Log::warning('Account not found for approved payment email', [
                    'charge_id' => $charge->id,
                    'account_id' => $charge->account_id,
                ]);
            }

            // 2. Send email to the customer who paid
            $customer = $charge->customer ?? [];
            $customerEmail = $customer['email'] ?? null;

            if ($customerEmail) {
                try {
                    Mail::to($customerEmail)->queue(
                        new PaymentApprovedCustomerMail($charge)
                    );
                    Log::info('Customer email queued', [
                        'charge_id' => $charge->id,
                        'email' => $customerEmail,
                    ]);
                } catch (\Exception $e) {
                    // Fallback to sync send
                    Log::warning('Queue failed, sending sync customer email', [
                        'charge_id' => $charge->id,
                        'email' => $customerEmail,
                        'error' => $e->getMessage(),
                    ]);
                    Mail::to($customerEmail)->send(
                        new PaymentApprovedCustomerMail($charge)
                    );
                }
            } else {
                Log::info('No customer email for approved payment', [
                    'charge_id' => $charge->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send payment approved emails', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Dispatch event to per-account webhooks (user-configured webhook URLs)
     */
    protected function dispatchAccountWebhook(string $event, Charge $charge): void
    {
        if (!$this->webhookDispatch) {
            try {
                $this->webhookDispatch = app(WebhookDispatchService::class);
            } catch (\Exception $e) {
                return;
            }
        }

        try {
            $this->webhookDispatch->dispatchPaymentEvent($event, $charge);
        } catch (\Exception $e) {
            Log::warning('Failed to dispatch account webhook', [
                'event' => $event,
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dispatch event to n8n integrations
     */
    protected function dispatchN8nEvent(string $event, Charge $charge): void
    {
        if (!$this->n8nService) {
            try {
                $this->n8nService = app(N8nWebhookService::class);
            } catch (\Exception $e) {
                Log::warning('N8nWebhookService failed to resolve', ['error' => $e->getMessage()]);
                return;
            }
        }

        try {
            $data = [
                'charge_id' => $charge->id,
                'amount' => $charge->amount,
                'currency' => $charge->currency ?? 'BRL',
                'status' => $charge->status,
                'payment_method' => $charge->payment_method,
                'customer' => [
                    'name' => $charge->customer['name'] ?? null,
                    'email' => $charge->customer['email'] ?? $charge->customer_email,
                    'document' => $charge->customer['document'] ?? null,
                ],
                'product' => [
                    'id' => $charge->product_id,
                    'name' => $charge->product?->name ?? $charge->description,
                ],
                'paid_at' => $charge->paid_at?->toIso8601String(),
                'created_at' => $charge->created_at?->toIso8601String(),
            ];

            $this->n8nService->dispatch($event, $data, $charge->account_id);
        } catch (\Exception $e) {
            Log::warning('Failed to dispatch n8n event', [
                'event' => $event,
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dispatch charge to UTMify for tracking attribution.
     * Uses synchronous send to guarantee delivery even without a queue worker.
     * Refreshes the charge from DB to ensure the latest status is sent.
     */
    protected function dispatchUtmifyEvent(Charge $charge): void
    {
        if (!$this->utmifyService) {
            // Attempt to re-initialize the service (it may have failed transiently during constructor)
            try {
                $this->utmifyService = app(UtmifyService::class);
            } catch (\Exception $e) {
                Log::warning('UTMify dispatch skipped – service not initialized and re-init failed', [
                    'charge_id' => $charge->id,
                    'charge_status' => $charge->status,
                    'error' => $e->getMessage(),
                ]);
                return;
            }
        }

        try {
            // Refresh charge from DB to ensure we have the latest status
            $charge->refresh();

            Log::info('UTMify dispatch starting', [
                'charge_id' => $charge->id,
                'charge_status' => $charge->status,
                'account_id' => $charge->account_id,
            ]);

            $result = $this->utmifyService->dispatchSync($charge);

            if ($result === null) {
                Log::info('UTMify dispatch skipped – no active integration', [
                    'charge_id' => $charge->id,
                    'account_id' => $charge->account_id,
                ]);
            } elseif (!$result['success']) {
                Log::warning('UTMify dispatch failed – will retry once', [
                    'charge_id' => $charge->id,
                    'charge_status' => $charge->status,
                    'http_status' => $result['status'] ?? null,
                    'error' => $result['error'] ?? 'Unknown',
                ]);

                // Retry once after a short delay
                usleep(1_000_000); // 1 second
                $retryResult = $this->utmifyService->dispatchSync($charge);

                if ($retryResult && $retryResult['success']) {
                    Log::info('UTMify dispatch succeeded on retry', [
                        'charge_id' => $charge->id,
                        'charge_status' => $charge->status,
                    ]);
                } else {
                    Log::error('UTMify dispatch failed on retry as well', [
                        'charge_id' => $charge->id,
                        'charge_status' => $charge->status,
                        'http_status' => $retryResult['status'] ?? null,
                        'error' => $retryResult['error'] ?? 'Unknown',
                    ]);
                }
            } else {
                Log::info('UTMify dispatch succeeded', [
                    'charge_id' => $charge->id,
                    'charge_status' => $charge->status,
                    'http_status' => $result['status'] ?? null,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to dispatch UTMify event', [
                'charge_id' => $charge->id,
                'charge_status' => $charge->status ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Dispatch notifications for approved payment (called from WebhookController fallback path)
     * This only sends notifications — it does NOT update wallet or charge status.
     */
    public function dispatchApprovedNotifications(Charge $charge): void
    {
        Log::info('Dispatching approved notifications (fallback path)', ['charge_id' => $charge->id]);

        try { $this->dispatchN8nEvent('payment.approved', $charge); } catch (\Exception $e) { Log::error('N8n dispatch failed (fallback)', ['charge_id' => $charge->id, 'error' => $e->getMessage()]); }
        try { $this->dispatchAccountWebhook('payment.approved', $charge); } catch (\Exception $e) { Log::error('Account webhook failed (fallback)', ['charge_id' => $charge->id, 'error' => $e->getMessage()]); }
        try { $this->dispatchAccountWebhook('charge.paid', $charge); } catch (\Exception $e) { Log::error('Account webhook failed (fallback)', ['charge_id' => $charge->id, 'error' => $e->getMessage()]); }
        $this->dispatchUtmifyEvent($charge);
        try { $this->sendPaymentApprovedEmails($charge); } catch (\Exception $e) { Log::error('Email dispatch failed (fallback)', ['charge_id' => $charge->id, 'error' => $e->getMessage()]); }
    }

    /**
     * Dispatch notifications for refund events (called from WebhookController fallback path)
     */
    public function dispatchRefundNotifications(Charge $charge): void
    {
        try { $this->dispatchN8nEvent('payment.refunded', $charge); } catch (\Exception $e) { Log::error('N8n dispatch failed', ['charge_id' => $charge->id, 'error' => $e->getMessage()]); }
        try { $this->dispatchAccountWebhook('payment.refunded', $charge); } catch (\Exception $e) { Log::error('Account webhook failed', ['charge_id' => $charge->id, 'error' => $e->getMessage()]); }
        try { $this->dispatchAccountWebhook('charge.refunded', $charge); } catch (\Exception $e) { Log::error('Account webhook failed', ['charge_id' => $charge->id, 'error' => $e->getMessage()]); }
        $this->dispatchUtmifyEvent($charge);
    }

    /**
     * Dispatch notifications for generic status changes (called from WebhookController fallback path)
     */
    public function dispatchStatusNotifications(string $status, Charge $charge): void
    {
        $eventName = match ($status) {
            'failed' => 'payment.rejected',
            'cancelled' => 'payment.cancelled',
            'expired' => 'payment.expired',
            default => 'payment.updated',
        };
        try { $this->dispatchN8nEvent($eventName, $charge); } catch (\Exception $e) { Log::error('N8n dispatch failed', ['charge_id' => $charge->id, 'error' => $e->getMessage()]); }
        try { $this->dispatchAccountWebhook($eventName, $charge); } catch (\Exception $e) { Log::error('Account webhook failed', ['charge_id' => $charge->id, 'error' => $e->getMessage()]); }
        $this->dispatchUtmifyEvent($charge);
    }

    /**
     * Update wallet when payment is confirmed.
     *
     * Uses DB transaction + row-level lock to prevent double-credit
     * from concurrent/duplicate webhook deliveries.
     */
    public function updateWalletOnPayment(Charge $charge): void
    {
        DB::transaction(function () use ($charge) {
            // Re-fetch charge inside transaction to check for concurrent updates
            $freshCharge = Charge::lockForUpdate()->find($charge->id);
            if (!$freshCharge || $freshCharge->net_amount > 0) {
                // Already credited (net_amount set) — skip to avoid double-credit
                Log::info('Wallet credit skipped (already processed)', ['charge_id' => $charge->id]);
                return;
            }

            $wallet = AccountWallet::lockForUpdate()->firstOrCreate(
                ['account_id' => $charge->account_id],
                [
                    'id' => Str::uuid()->toString(),
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'blocked_balance' => 0,
                    'currency' => 'BRL',
                ]
            );

            // Calculate fees from acquirer config
            $acquirerConfig = AcquirerConfig::findByKey($charge->acquirer);

            $feeAmount = 0;
            $netAmount = $charge->amount;

            if ($acquirerConfig) {
                // Check for per-account custom gateway fee
                $customFee = AccountCustomFee::where('account_id', $charge->account_id)->first();
                $customGatewayFee = $customFee?->calculateGatewayFee($charge->amount, $charge->payment_method);

                if (!is_null($customGatewayFee)) {
                    $acquirerFee = $acquirerConfig->calculateAcquirerFee($charge->amount);
                    $feeAmount = $customGatewayFee + $acquirerFee;
                } else {
                    // Default: total fee from acquirer config
                    $feeAmount = $acquirerConfig->calculateTotalFee($charge->amount);
                }

                $netAmount = $charge->amount - $feeAmount;
            }

            // Update charge with fee information (acts as idempotency marker)
            $freshCharge->update([
                'fee_amount' => $feeAmount,
                'net_amount' => $netAmount,
            ]);

            $wallet->increment('available_balance', $netAmount);

            Log::info('Wallet updated on payment', [
                'account_id' => $charge->account_id,
                'charge_id' => $charge->id,
                'amount' => $charge->amount,
                'fee_amount' => $feeAmount,
                'net_amount' => $netAmount,
                'new_balance' => $wallet->fresh()->available_balance,
            ]);
        });
    }

    /**
     * Update wallet on refund.
     *
     * Uses DB transaction + row-level lock to prevent double-debit.
     */
    public function updateWalletOnRefund(Charge $charge, int $amount): void
    {
        DB::transaction(function () use ($charge, $amount) {
            $freshCharge = Charge::lockForUpdate()->find($charge->id);
            if (!$freshCharge) {
                return;
            }

            $wallet = AccountWallet::lockForUpdate()
                ->where('account_id', $charge->account_id)
                ->first();

            if ($wallet) {
                $creditedNetAmount = (int) ($freshCharge->net_amount ?? 0);
                if ($creditedNetAmount <= 0) {
                    Log::info('Wallet refund skipped (no credited net amount)', [
                        'account_id' => $charge->account_id,
                        'charge_id' => $charge->id,
                    ]);
                    return;
                }

                $grossAmount = max((int) $freshCharge->amount, 1);
                $requestedRefundAmount = max(0, min((int) $amount, $grossAmount));
                if ($requestedRefundAmount <= 0) {
                    return;
                }

                $walletDebitAmount = $requestedRefundAmount >= $grossAmount
                    ? $creditedNetAmount
                    : (int) round($creditedNetAmount * ($requestedRefundAmount / $grossAmount));

                if ($walletDebitAmount <= 0) {
                    return;
                }

                $wallet->decrement('available_balance', $walletDebitAmount);

                Log::info('Wallet updated on refund', [
                    'account_id' => $charge->account_id,
                    'charge_id' => $charge->id,
                    'refund_amount' => $requestedRefundAmount,
                    'wallet_debit_amount' => $walletDebitAmount,
                    'credited_net_amount' => $creditedNetAmount,
                    'new_balance' => $wallet->fresh()->available_balance,
                ]);
            }
        });
    }

    /**
     * Map Babylon status to internal status
     */
    private function mapBabylonStatus(string $status): string
    {
        return match ($status) {
            'paid', 'approved', 'authorized', 'completed', 'confirmed', 'settled', 'success' => 'approved',
            'waiting_payment' => 'pending',
            'pending', 'in_analysis' => 'processing',
            'refused', 'declined' => 'declined',
            'canceled', 'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'chargedback' => 'chargeback',
            'failed', 'error' => 'failed',
            'expired' => 'expired',
            default => 'pending',
        };
    }

    private function defaultPixExpirationAt(): \Illuminate\Support\Carbon
    {
        return now()->addMinutes(self::DEFAULT_PIX_EXPIRATION_MINUTES);
    }

    /**
     * Sync charge status with acquirer (for PIX/Boleto status checks)
     */
    public function syncChargeStatus(Charge $charge): array
    {
        if (!$charge->acquirer_transaction_id) {
            return ['success' => true, 'status' => $charge->status, 'synced' => false];
        }

        $acquirer = $charge->acquirer ?? 'babylon';

        // Route to appropriate acquirer sync
        if (in_array($acquirer, ['payfort'])) {
            return $this->syncPayFortChargeStatus($charge);
        } elseif (in_array($acquirer, ['enki'])) {
            return $this->syncEnkiChargeStatus($charge);
        } elseif (in_array($acquirer, ['blupay'])) {
            return $this->syncBluPayChargeStatus($charge);
        } elseif (in_array($acquirer, ['huntersub'])) {
            return $this->syncHunterSubChargeStatus($charge);
        } elseif (in_array($acquirer, ['sharkhubsub'])) {
            return $this->syncSharkHubSubChargeStatus($charge);
        } elseif (in_array($acquirer, ['asaas'])) {
            return $this->syncAsaasChargeStatus($charge);
        } elseif (in_array($acquirer, ['turbos', 'ascentpay', 'witetec'])) {
            // These acquirers don't have a query API — rely on webhooks only
            return ['success' => true, 'status' => $charge->status, 'synced' => false];
        }

        // Default: Babylon sync
        return $this->syncBabylonChargeStatus($charge);
    }

    /**
     * Sync with PayFort API
     */
    private function syncPayFortChargeStatus(Charge $charge): array
    {
        if (!$this->factory->payfort()) {
            return ['success' => true, 'status' => $charge->status, 'synced' => false];
        }

        try {
            $response = $this->factory->payfort()->getTransaction($charge->acquirer_transaction_id);

            if (!$response['success']) {
                return ['success' => true, 'status' => $charge->status, 'synced' => false];
            }

            $externalStatus = $response['status'] ?? $charge->status;
            $statusMap = [
                'paid' => 'approved', 'approved' => 'approved', 'completed' => 'approved', 'confirmed' => 'approved',
                'pending' => 'pending', 'waiting_payment' => 'pending', 'created' => 'pending',
                'expired' => 'expired', 'cancelled' => 'cancelled', 'canceled' => 'cancelled',
                'refunded' => 'refunded', 'failed' => 'failed', 'refused' => 'failed',
            ];
            $newStatus = $statusMap[strtolower($externalStatus)] ?? $charge->status;

            if ($newStatus === 'approved' && $charge->status !== 'approved') {
                $this->handleWebhookPayment($charge, [
                    'id' => $charge->acquirer_transaction_id,
                    'status' => $externalStatus,
                    'amount' => $response['amount'] ?? null,
                    'pix' => [
                        'endToEndId' => data_get($response, 'data.pix.endToEndId')
                            ?? data_get($response, 'data.pix.end2EndId')
                            ?? data_get($response, 'pix.endToEndId'),
                    ],
                    'metadata' => [
                        'source' => 'payfort_sync',
                        'charge_id' => $charge->id,
                    ],
                ]);

                $charge->refresh();

                return ['success' => true, 'status' => $charge->status, 'synced' => true, 'side_effects_handled' => true];
            }

            if ($newStatus !== $charge->status && !in_array($charge->status, ['approved', 'refunded', 'chargeback'], true)) {
                $charge->update([
                    'status' => $newStatus,
                    'paid_at' => $newStatus === 'approved' ? ($charge->paid_at ?? now()) : $charge->paid_at,
                ]);
            }

            return ['success' => true, 'status' => $newStatus, 'synced' => true];
        } catch (\Exception $e) {
            Log::error('Sync PayFort charge status error', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);
            return ['success' => true, 'status' => $charge->status, 'synced' => false];
        }
    }

    /**
     * Sync with BluPay API
     */
    private function syncBluPayChargeStatus(Charge $charge): array
    {
        if (!$this->factory->blupay()) {
            return ['success' => true, 'status' => $charge->status, 'synced' => false];
        }

        try {
            $response = $this->factory->blupay()->getPixInTransaction($charge->acquirer_transaction_id);

            if (!$response['success']) {
                return ['success' => true, 'status' => $charge->status, 'synced' => false];
            }

            $externalStatus = $response['status'] ?? $charge->status;
            $statusMap = [
                'paid' => 'approved', 'approved' => 'approved', 'completed' => 'approved', 'confirmed' => 'approved',
                'pending' => 'pending', 'waiting_payment' => 'pending', 'created' => 'pending',
                'expired' => 'expired', 'cancelled' => 'cancelled', 'canceled' => 'cancelled',
                'refunded' => 'refunded', 'failed' => 'failed', 'refused' => 'failed',
            ];
            $newStatus = $statusMap[strtolower($externalStatus)] ?? $charge->status;

            if ($newStatus === 'approved' && $charge->status !== 'approved') {
                $this->handleWebhookPayment($charge, [
                    'id' => $charge->acquirer_transaction_id,
                    'status' => $externalStatus,
                    'amount' => $response['amount'] ?? null,
                    'pix' => [
                        'endToEndId' => data_get($response, 'data.pix.endToEndId')
                            ?? data_get($response, 'data.pix.end2EndId')
                            ?? data_get($response, 'pix.endToEndId'),
                    ],
                    'metadata' => [
                        'source' => 'blupay_sync',
                        'charge_id' => $charge->id,
                    ],
                ]);

                $charge->refresh();

                return ['success' => true, 'status' => $charge->status, 'synced' => true, 'side_effects_handled' => true];
            }

            if ($newStatus !== $charge->status && !in_array($charge->status, ['approved', 'refunded', 'chargeback'], true)) {
                $charge->update([
                    'status' => $newStatus,
                    'paid_at' => $newStatus === 'approved' ? ($charge->paid_at ?? now()) : $charge->paid_at,
                ]);
            }

            return ['success' => true, 'status' => $newStatus, 'synced' => true];
        } catch (\Exception $e) {
            Log::error('Sync BluPay charge status error', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);
            return ['success' => true, 'status' => $charge->status, 'synced' => false];
        }
    }

    /**
     * Sync with Enki API
     */
    private function syncEnkiChargeStatus(Charge $charge): array
    {
        $enki = $this->factory->enkiForAccount($charge->account_id);

        if (!$enki) {
            return ['success' => true, 'status' => $charge->status, 'synced' => false];
        }

        try {
            $response = $enki->getTransaction($charge->acquirer_transaction_id);

            if (!$response['success']) {
                return ['success' => true, 'status' => $charge->status, 'synced' => false];
            }

            $externalStatus = $response['status'] ?? $charge->status;
            $statusMap = [
                'paid' => 'approved', 'approved' => 'approved', 'completed' => 'approved', 'confirmed' => 'approved',
                'pending' => 'pending', 'waiting_payment' => 'pending', 'created' => 'pending',
                'expired' => 'expired', 'cancelled' => 'cancelled', 'canceled' => 'cancelled',
                'refunded' => 'refunded', 'failed' => 'failed', 'refused' => 'failed',
            ];
            $newStatus = $statusMap[strtolower((string) $externalStatus)] ?? $charge->status;

            if ($newStatus === 'approved' && $charge->status !== 'approved') {
                $this->handleWebhookPayment($charge, [
                    'id' => $charge->acquirer_transaction_id,
                    'status' => $externalStatus,
                    'amount' => $response['amount'] ?? null,
                    'fee' => $response['fee'] ?? null,
                    'net_amount' => $response['net_amount'] ?? null,
                    'paid_at' => $response['paid_at'] ?? null,
                    'pix' => [
                        'endToEndId' => data_get($response, 'data.pix.end_to_end')
                            ?? data_get($response, 'data.pix.endToEndId')
                            ?? data_get($response, 'data.pix.end2EndId'),
                    ],
                ]);

                $charge->refresh();

                return ['success' => true, 'status' => $charge->status, 'synced' => true, 'side_effects_handled' => true];
            }

            if ($newStatus !== $charge->status) {
                $previousStatus = $charge->status;
                $charge->update([
                    'status' => $newStatus,
                    'paid_at' => $newStatus === 'approved' ? ($charge->paid_at ?? now()) : $charge->paid_at,
                ]);

                if ($newStatus === 'approved' && $previousStatus !== 'approved') {
                    $this->dispatchUtmifyEvent($charge->fresh());
                }
            }

            return ['success' => true, 'status' => $newStatus, 'synced' => true];
        } catch (\Exception $e) {
            Log::error('Sync Enki charge status error', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);
            return ['success' => true, 'status' => $charge->status, 'synced' => false];
        }
    }

    /**
     * Sync with HunterSub API.
     */
    private function syncHunterSubChargeStatus(Charge $charge): array
    {
        $huntersub = $this->factory->huntersubForAccount($charge->account_id);

        if (!$huntersub) {
            return ['success' => true, 'status' => $charge->status, 'synced' => false];
        }

        try {
            $response = $huntersub->getTransaction($charge->acquirer_transaction_id);

            if (!$response) {
                return ['success' => true, 'status' => $charge->status, 'synced' => false];
            }

            $externalStatus = strtolower((string) ($response['status'] ?? $charge->status));
            $statusMap = [
                'paid' => 'approved',
                'approved' => 'approved',
                'completed' => 'approved',
                'confirmed' => 'approved',
                'delivered' => 'approved',
                'pending' => 'pending',
                'waiting_payment' => 'pending',
                'created' => 'pending',
                'expired' => 'expired',
                'cancelled' => 'cancelled',
                'canceled' => 'cancelled',
                'refunded' => 'refunded',
                'chargeback' => 'chargeback',
                'chargedback' => 'chargeback',
                'failed' => 'failed',
                'refused' => 'failed',
                'declined' => 'failed',
            ];
            $newStatus = $statusMap[$externalStatus] ?? $charge->status;

            if ($newStatus === 'approved' && $charge->status !== 'approved') {
                $this->handleWebhookPayment($charge, [
                    'id' => $charge->acquirer_transaction_id,
                    'status' => $externalStatus,
                    'amount' => $response['amount'] ?? null,
                    'paymentMethod' => $response['paymentMethod'] ?? $charge->payment_method,
                    'paid_at' => data_get($response, 'pix.paidAt') ?? $response['paidAt'] ?? null,
                    'pix' => [
                        'endToEndId' => data_get($response, 'pix.endToEndId'),
                    ],
                    'metadata' => [
                        'source' => 'huntersub_sync',
                        'charge_id' => $charge->id,
                    ],
                ]);

                $charge->refresh();

                return ['success' => true, 'status' => $charge->status, 'synced' => true, 'side_effects_handled' => true];
            }

            if ($newStatus !== $charge->status && !in_array($charge->status, ['approved', 'refunded', 'chargeback'], true)) {
                $charge->update([
                    'status' => $newStatus,
                    'paid_at' => $newStatus === 'approved' ? ($charge->paid_at ?? now()) : $charge->paid_at,
                    'pix_end2end_id' => data_get($response, 'pix.endToEndId') ?? $charge->pix_end2end_id,
                ]);
            }

            return ['success' => true, 'status' => $newStatus, 'synced' => true];
        } catch (\Exception $e) {
            Log::error('Sync HunterSub charge status error', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => true, 'status' => $charge->status, 'synced' => false];
        }
    }

    private function syncSharkHubSubChargeStatus(Charge $charge): array
    {
        $sharkhub = $this->factory->sharkhubsubForAccount($charge->account_id);

        if (!$sharkhub) {
            return ['success' => true, 'status' => $charge->status, 'synced' => false];
        }

        try {
            $response = $sharkhub->getPayment($charge->acquirer_transaction_id);

            if (!$response) {
                return ['success' => true, 'status' => $charge->status, 'synced' => false];
            }

            $payment = is_array($response['data'] ?? null) ? $response['data'] : $response;
            $externalStatus = (string) ($payment['status'] ?? $response['status'] ?? $charge->status);
            $newStatus = \App\Infrastructure\Acquirers\SharkHubSubAcquirer::mapWebhookPaymentStatus($externalStatus);

            if ($newStatus === 'approved' && $charge->status !== 'approved') {
                $this->handleWebhookPayment($charge, [
                    'id' => $charge->acquirer_transaction_id,
                    'status' => $newStatus,
                    'amount' => $payment['amount'] ?? $response['amount'] ?? null,
                    'paymentMethod' => $payment['method'] ?? $response['method'] ?? $charge->payment_method,
                    'paidAt' => $payment['paidAt'] ?? $payment['paid_at'] ?? $response['paidAt'] ?? null,
                    'pix' => [
                        'endToEndId' => data_get($payment, 'e2e') ?? data_get($payment, 'pix.endToEndId') ?? data_get($response, 'data.e2e'),
                        'copyPaste' => data_get($payment, 'copypaste') ?? data_get($payment, 'copyPaste') ?? data_get($response, 'data.copypaste'),
                    ],
                    'metadata' => [
                        'source' => 'sharkhubsub_sync',
                        'charge_id' => $charge->id,
                    ],
                ]);

                $charge->refresh();

                return ['success' => true, 'status' => $charge->status, 'synced' => true, 'side_effects_handled' => true];
            }

            if ($newStatus !== $charge->status && !in_array($charge->status, ['approved', 'refunded', 'chargeback'], true)) {
                $charge->update([
                    'status' => $newStatus,
                    'paid_at' => $newStatus === 'approved' ? ($charge->paid_at ?? now()) : $charge->paid_at,
                    'pix_end2end_id' => data_get($payment, 'e2e') ?? data_get($payment, 'pix.endToEndId') ?? $charge->pix_end2end_id,
                ]);
            }

            return ['success' => true, 'status' => $newStatus, 'synced' => true];
        } catch (\Throwable $e) {
            Log::error('Sync SharkHubSub charge status error', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => true, 'status' => $charge->status, 'synced' => false];
        }
    }

    /**
     * Sync with Asaas API
     */
    private function syncAsaasChargeStatus(Charge $charge): array
    {
        $asaas = $this->factory->asaas();

        if (!$asaas) {
            return ['success' => true, 'status' => $charge->status, 'synced' => false];
        }

        try {
            $response = $asaas->getPayment($charge->acquirer_transaction_id);

            if (!$response || !($response['success'] ?? true)) {
                return ['success' => true, 'status' => $charge->status, 'synced' => false];
            }

            $externalStatus = strtolower((string) ($response['status'] ?? ''));
            $confirmedStatuses = ['confirmed', 'received', 'received_in_cash'];

            if (in_array($externalStatus, $confirmedStatuses, true) && $charge->status !== 'approved') {
                $this->handleWebhookPayment($charge, [
                    'id' => $charge->acquirer_transaction_id,
                    'status' => 'approved',
                    'amount' => $response['value'] ?? $response['netValue'] ?? null,
                    'metadata' => [
                        'source' => 'asaas_sync',
                        'charge_id' => $charge->id,
                    ],
                ]);

                $charge->refresh();

                return ['success' => true, 'status' => $charge->status, 'synced' => true, 'side_effects_handled' => true];
            }

            $statusMap = [
                'pending' => 'pending',
                'overdue' => 'expired',
                'refunded' => 'refunded',
                'refund_requested' => 'refunded',
                'chargeback_requested' => 'chargeback',
                'chargeback_dispute' => 'chargeback',
                'awaiting_chargeback_reversal' => 'chargeback',
                'dunning_requested' => 'pending',
                'dunning_received' => 'approved',
            ];
            $newStatus = $statusMap[$externalStatus] ?? $charge->status;

            if ($newStatus !== $charge->status && !in_array($charge->status, ['approved', 'refunded', 'chargeback'], true)) {
                $charge->update(['status' => $newStatus]);
            }

            return ['success' => true, 'status' => $newStatus, 'synced' => true];
        } catch (\Throwable $e) {
            Log::error('Sync Asaas charge status error', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => true, 'status' => $charge->status, 'synced' => false];
        }
    }

    /**
     * Sync with Babylon API (legacy)
     */
    private function syncBabylonChargeStatus(Charge $charge): array
    {
        $baseUrl = config('acquirers.babylon.base_url', 'https://api.bancobabylon.com/functions/v1');
        $secretKey = config('acquirers.babylon.secret_key');
        $companyId = config('acquirers.babylon.company_id');

        if (!$secretKey || !$companyId) {
            return ['success' => true, 'status' => $charge->status, 'synced' => false];
        }

        try {
            $response = Http::withBasicAuth($secretKey, $companyId)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->timeout(15)
                ->get($baseUrl . '/pix/' . $charge->acquirer_transaction_id);

            if ($response->successful()) {
                $data = $response->json();
                $externalStatus = $data['status'] ?? $charge->status;

                if (in_array(strtolower((string) $externalStatus), ['paid', 'approved', 'confirmed', 'concluida'], true)
                    && $charge->status !== 'approved') {
                    $this->handleWebhookPayment($charge, [
                        'id' => $charge->acquirer_transaction_id,
                        'status' => $externalStatus,
                        'amount' => $data['amount'] ?? null,
                        'pix' => [
                            'endToEndId' => $data['endToEndId'] ?? $data['end2EndId'] ?? null,
                        ],
                        'metadata' => [
                            'source' => 'babylon_sync',
                            'charge_id' => $charge->id,
                        ],
                    ]);

                    $charge->refresh();

                    return ['success' => true, 'status' => $charge->status, 'synced' => true, 'side_effects_handled' => true];
                }

                $statusMap = [
                    'waiting_payment' => 'pending',
                    'pending' => 'pending',
                    'ativa' => 'pending',
                    'expired' => 'expired',
                    'cancelled' => 'cancelled',
                    'refunded' => 'refunded',
                ];
                $newStatus = $statusMap[strtolower((string) $externalStatus)] ?? $charge->status;

                if ($newStatus !== $charge->status && !in_array($charge->status, ['approved', 'refunded', 'chargeback'], true)) {
                    $charge->update(['status' => $newStatus]);
                }

                return ['success' => true, 'status' => $newStatus, 'synced' => true];
            }

            // Return current status if API call fails
            return ['success' => true, 'status' => $charge->status, 'synced' => false];
        } catch (\Exception $e) {
            Log::error('Sync charge status error', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);
            // Return current status even on error
            return ['success' => true, 'status' => $charge->status, 'synced' => false];
        }
    }
}
