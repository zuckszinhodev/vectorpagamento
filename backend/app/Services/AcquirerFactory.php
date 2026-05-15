<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Acquirers\BluPayAcquirer;
use App\Infrastructure\Acquirers\EnkiAcquirer;
use App\Infrastructure\Acquirers\PayFortAcquirer;
use App\Models\AcquirerConfig;
use App\Models\AccountAcquirer;
use App\Domain\Acquirer\Entities\Acquirer;
use Illuminate\Support\Facades\Log;

/**
 * Centralized Acquirer Factory
 *
 * Single source of truth for instantiating and caching acquirer instances.
 * Eliminates duplicate initialization code across PaymentService / WithdrawalService.
 *
 * Credentials resolution order:
 * 1. Config/ENV (.env variables)
 * 2. Database (encrypted credentials stored via admin panel)
 */
class AcquirerFactory
{
    private static ?self $instance = null;

    private ?PayFortAcquirer $payfort = null;
    private ?BluPayAcquirer $blupay = null;
    private ?EnkiAcquirer $enki = null;
    private ?\App\Infrastructure\Acquirers\WitetecAcquirer $witetec = null;
    private ?\App\Infrastructure\Acquirers\AsaasAcquirer $asaas = null;
    private ?\App\Infrastructure\Acquirers\HunterSubAcquirer $huntersub = null;
    private ?\App\Infrastructure\Acquirers\SharkHubSubAcquirer $sharkhubsub = null;
    private ?\App\Infrastructure\Acquirers\BabylonAcquirer $babylon = null;

    private bool $payfortInitialized = false;
    private bool $blupayInitialized = false;
    private bool $enkiInitialized = false;
    private bool $witetecInitialized = false;
    private bool $asaasInitialized = false;
    private bool $huntersubInitialized = false;
    private bool $sharkhubsubInitialized = false;
    private bool $babylonInitialized = false;
    private ?AcquirerRotationService $rotationService = null;

    /**
     * Per-account acquirer instances to avoid leaking credentials across accounts.
     * @var array<string, PayFortAcquirer|null>
     */
    private array $payfortByAccount = [];
    /**
     * @var array<string, EnkiAcquirer|null>
     */
    private array $enkiByAccount = [];
    /**
     * @var array<string, \App\Infrastructure\Acquirers\HunterSubAcquirer|null>
     */
    private array $huntersubByAccount = [];
    /**
     * @var array<string, \App\Infrastructure\Acquirers\SharkHubSubAcquirer|null>
     */
    private array $sharkhubsubByAccount = [];

    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── Credential resolution (ENV → DB) ─────────────────────────

    /**
     * Get credentials for an acquirer, checking DB (admin panel).
     * Returns validated credential array or null.
     */
    private static function resolveCredentials(string $acquirerKey): ?array
    {
        try {
            $config = AcquirerConfig::findByKey($acquirerKey);

            if (!$config || !$config->enabled) {
                Log::debug("AcquirerFactory: no enabled DB config for '{$acquirerKey}'");
                return null;
            }

            $dbCreds = $config->getDecryptedCredentials();

            if (!$dbCreds) {
                Log::debug("AcquirerFactory: DB config for '{$acquirerKey}' exists but credentials are empty or cannot be decrypted");
                return null;
            }

            if (!$config->validateCredentialsByKey($acquirerKey, $dbCreds)) {
                Log::warning("AcquirerFactory: DB credentials for '{$acquirerKey}' are incomplete (missing required fields)", [
                    'fields_present' => array_keys(array_filter($dbCreds, fn($v) => !empty($v))),
                ]);
                return null;
            }

            Log::debug("AcquirerFactory: valid DB credentials found for '{$acquirerKey}'");
            return $dbCreds;
        } catch (\Exception $e) {
            Log::warning("AcquirerFactory: DB credentials lookup failed for '{$acquirerKey}'", ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Get credentials for a specific account from account_acquirers.
     */
    private static function resolveAccountCredentials(?string $accountId, string $acquirerKey): ?array
    {
        if (!$accountId) {
            return null;
        }

        try {
            $accountAcquirer = AccountAcquirer::where('account_acquirers.account_id', $accountId)
                ->where('account_acquirers.is_active', true)
                ->join('acquirers', 'acquirers.id', '=', 'account_acquirers.acquirer_id')
                ->where('acquirers.slug', $acquirerKey)
                ->select('account_acquirers.*')
                ->first();

            if (!$accountAcquirer) {
                return null;
            }

            $creds = $accountAcquirer->getDecryptedCredentials();
            if (!$creds) {
                return null;
            }

            $config = AcquirerConfig::where('acquirer_key', $acquirerKey)->first();
            if ($config && !$config->validateCredentialsByKey($acquirerKey, $creds)) {
                Log::warning("AcquirerFactory: account credentials for '{$acquirerKey}' are incomplete", [
                    'account_id' => $accountId,
                    'fields_present' => array_keys(array_filter($creds, fn($v) => !empty($v))),
                ]);
                return null;
            }

            return $creds;
        } catch (\Exception $e) {
            Log::warning("AcquirerFactory: account credentials lookup failed for '{$acquirerKey}'", [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private static function isConfiguredForAccount(?string $accountId, string $acquirerKey): bool
    {
        $accountCreds = self::resolveAccountCredentials($accountId, $acquirerKey);
        if ($accountCreds) {
            return true;
        }

        return match ($acquirerKey) {
            'payfort' => self::isPayFortConfigured(),
            'blupay' => self::isBluPayConfigured(),
            'enki' => self::isEnkiConfigured(),
            'babylon' => self::isBabylonConfigured(),
            'witetec' => self::isWitetecConfigured(),
            'asaas' => self::isAsaasConfigured(),
            'huntersub' => self::isHunterSubConfigured(),
            'sharkhubsub' => self::isSharkHubSubConfigured(),
            default => false,
        };
    }

    private function getForcedAcquirer(): ?string
    {
        $forced = strtolower(trim((string) config('acquirers.routing.force_acquirer', '')));
        if ($forced === '') {
            return null;
        }

        if (!$this->getAcquirerInstance($forced)) {
            Log::warning('Forced acquirer unavailable, falling back to priority routing', [
                'force_acquirer' => $forced,
            ]);
            return null;
        }

        return $forced;
    }

    private function rotation(): AcquirerRotationService
    {
        if ($this->rotationService === null) {
            $this->rotationService = app(AcquirerRotationService::class);
        }

        return $this->rotationService;
    }

    private function normalizePaymentMethod(?string $paymentMethod): string
    {
        $normalized = strtolower(trim((string) ($paymentMethod ?? 'pix')));

        return match ($normalized) {
            'card' => 'credit_card',
            'pixout', 'pix-out', 'withdrawal', 'withdraw' => 'pix_out',
            default => $normalized !== '' ? $normalized : 'pix',
        };
    }

    private function isAcquirerAvailable(string $key, ?AcquirerConfig $acquirerConfig = null): bool
    {
        $isConfigured = match ($key) {
            'payfort'   => self::isPayFortConfigured(),
            'blupay'    => self::isBluPayConfigured(),
            'enki'      => self::isEnkiConfigured(),
            'babylon'   => self::isBabylonConfigured(),
            'witetec'   => self::isWitetecConfigured(),
            'asaas'     => self::isAsaasConfigured(),
            'huntersub' => self::isHunterSubConfigured(),
            'sharkhubsub' => self::isSharkHubSubConfigured(),
            default     => false,
        };

        if (!$isConfigured && $acquirerConfig) {
            try {
                $isConfigured = $acquirerConfig->hasValidCredentials();
            } catch (\Throwable $e) {
                $isConfigured = false;
            }
        }

        return $isConfigured;
    }

    /**
     * List available acquirer keys ordered by DB priority.
     * Falls back to all enabled acquirers if none match the payment method.
     *
     * @return array<int, string>
     */
    private function listConfiguredAcquirerKeys(?string $paymentMethod = null): array
    {
        $normalizedMethod = $this->normalizePaymentMethod($paymentMethod);
        $lookupMethods = $normalizedMethod !== '' ? [$normalizedMethod] : [];

        $keys = [];

        try {
            $query = AcquirerConfig::where('enabled', true);

            if ($lookupMethods !== []) {
                $query->where(function ($builder) use ($lookupMethods) {
                    foreach ($lookupMethods as $index => $method) {
                        if ($index === 0) {
                            $builder->where(function ($nested) use ($method) {
                                $nested->whereJsonContains('supported_methods', strtolower($method))
                                    ->orWhereJsonContains('supported_methods', strtoupper($method));
                            });
                            continue;
                        }

                        $builder->orWhere(function ($nested) use ($method) {
                            $nested->whereJsonContains('supported_methods', strtolower($method))
                                ->orWhereJsonContains('supported_methods', strtoupper($method));
                        });
                    }
                });
            }

            $acquirers = $query->orderBy('priority', 'asc')->get();

            // Backward compatibility: PIX OUT used to be inferred from PIX.
            // If no gateway is explicitly marked with pix_out, use legacy pix support.
            if ($acquirers->isEmpty() && $normalizedMethod === 'pix_out') {
                $acquirers = AcquirerConfig::where('enabled', true)
                    ->where(function ($builder) {
                        $builder->whereJsonContains('supported_methods', 'pix')
                            ->orWhereJsonContains('supported_methods', 'PIX');
                    })
                    ->orderBy('priority', 'asc')
                    ->get();
            }

            if ($acquirers->isEmpty() && $paymentMethod !== null) {
                $acquirers = AcquirerConfig::where('enabled', true)
                    ->orderBy('priority', 'asc')
                    ->get();
            }

            foreach ($acquirers as $acquirer) {
                $key = $acquirer->acquirer_key;
                if (!$this->isAcquirerAvailable($key, $acquirer)) {
                    continue;
                }

                if (!in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to list configured acquirers from DB', [
                'payment_method' => $paymentMethod,
                'error' => $e->getMessage(),
            ]);
        }

        return $keys;
    }

    /**
     * Add acquirers that are enabled in DB but missing from the current list.
     * Only adds acquirers that are both enabled in acquirer_configs AND have valid credentials.
     *
     * @param array<int, string> $keys
     * @return array<int, string>
     */
    private function appendConfiguredFallbacks(array $keys): array
    {
        try {
            $enabledInDb = \App\Models\AcquirerConfig::where('enabled', true)
                ->orderBy('priority', 'asc')
                ->pluck('acquirer_key')
                ->toArray();

            foreach ($enabledInDb as $name) {
                if (in_array($name, $keys, true)) {
                    continue;
                }

                if ($this->isAcquirerAvailable($name)) {
                    $keys[] = $name;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('appendConfiguredFallbacks: DB lookup failed', ['error' => $e->getMessage()]);
        }

        return $keys;
    }

    // ── Configuration checks ─────────────────────────────────────

    public static function isPayFortConfigured(): bool
    {
        // Check if enabled in database first
        $config = AcquirerConfig::where('acquirer_key', 'payfort')->first();
        if (!$config || !$config->enabled) {
            return false;
        }
        
        if (config('acquirers.payfort.enabled', false)
            && !empty(config('acquirers.payfort.api_key'))
            && !empty(config('acquirers.payfort.api_secret'))) {
            return true;
        }

        $creds = self::resolveCredentials('payfort');
        return $creds !== null;
    }

    public static function isBluPayConfigured(): bool
    {
        $config = AcquirerConfig::where('acquirer_key', 'blupay')->first();
        if (!$config || !$config->enabled) {
            return false;
        }

        if (config('acquirers.blupay.enabled', false)
            && !empty(config('acquirers.blupay.secret_key'))
            && !empty(config('acquirers.blupay.public_key'))) {
            return true;
        }

        $creds = self::resolveCredentials('blupay');
        return $creds !== null;
    }

    public static function isBabylonConfigured(): bool
    {
        // Check if enabled in database first
        $config = AcquirerConfig::where('acquirer_key', 'babylon')->first();
        if (!$config || !$config->enabled) {
            return false;
        }
        
        return !empty(config('acquirers.babylon.secret_key'))
            && !empty(config('acquirers.babylon.company_id'));
    }

    public static function isEnkiConfigured(): bool
    {
        $config = AcquirerConfig::where('acquirer_key', 'enki')->first();
        if (!$config || !$config->enabled) {
            return false;
        }

        if (config('acquirers.enki.enabled', false)
            && !empty(config('acquirers.enki.public_key'))
            && !empty(config('acquirers.enki.secret_key'))) {
            return true;
        }

        $creds = self::resolveCredentials('enki');
        return $creds !== null;
    }

    public static function isAsaasConfigured(): bool
    {
        $config = AcquirerConfig::where('acquirer_key', 'asaas')->first();
        if (!$config || !$config->enabled) {
            return false;
        }

        if (config('acquirers.asaas.enabled', false)
            && !empty(config('acquirers.asaas.api_key'))) {
            return true;
        }

        $creds = self::resolveCredentials('asaas');
        return $creds !== null;
    }

    public static function isHunterSubConfigured(): bool
    {
        // Check if enabled in database first
        $config = AcquirerConfig::where('acquirer_key', 'huntersub')->first();
        if (!$config || !$config->enabled) {
            return false;
        }
        
        if (config('acquirers.huntersub.enabled', false)
            && !empty(config('acquirers.huntersub.api_key'))
            && !empty(config('acquirers.huntersub.company_id'))) {
            return true;
        }

        $creds = self::resolveCredentials('huntersub');
        return $creds !== null;
    }

    public static function isSharkHubSubConfigured(): bool
    {
        $config = AcquirerConfig::where('acquirer_key', 'sharkhubsub')->first();
        if (!$config || !$config->enabled) {
            return false;
        }

        if (config('acquirers.sharkhubsub.enabled', false)
            && !empty(config('acquirers.sharkhubsub.api_key'))) {
            return true;
        }

        $creds = self::resolveCredentials('sharkhubsub');
        return $creds !== null;
    }

    public static function isWitetecConfigured(): bool
    {
        $config = AcquirerConfig::where('acquirer_key', 'witetec')->first();
        if (!$config || !$config->enabled) {
            return false;
        }

        if (config('acquirers.witetec.enabled', false)
            && !empty(config('acquirers.witetec.api_key'))) {
            return true;
        }

        $creds = self::resolveCredentials('witetec');
        return $creds !== null;
    }

    public static function isTurbosConfigured(): bool
    {
        $config = AcquirerConfig::where('acquirer_key', 'turbos')->first();
        if (!$config || !$config->enabled) {
            return false;
        }

        if (config('acquirers.turbos.enabled', false)
            && !empty(config('acquirers.turbos.public_key'))
            && !empty(config('acquirers.turbos.secret_key'))) {
            return true;
        }

        $creds = self::resolveCredentials('turbos');
        return $creds !== null;
    }

    // ── Lazy getters (ENV → DB credentials) ────────────────────

    public function payfort(): ?PayFortAcquirer
    {
        if (!$this->payfortInitialized) {
            $this->payfortInitialized = true;

            $envConfigured = config('acquirers.payfort.enabled', false)
                && !empty(config('acquirers.payfort.api_key'))
                && !empty(config('acquirers.payfort.api_secret'));

            if ($envConfigured) {
                $this->payfort = new PayFortAcquirer([
                    'api_key'        => config('acquirers.payfort.api_key', ''),
                    'api_secret'     => config('acquirers.payfort.api_secret', ''),
                    'base_url'       => config('acquirers.payfort.base_url', 'https://api.payfortbank.com'),
                    'webhook_secret' => config('acquirers.payfort.webhook_secret', ''),
                ]);
                $this->payfort->warmAuthToken();
            } else {
                $creds = self::resolveCredentials('payfort');
                if ($creds) {
                    Log::info('PayFort: using DB-stored credentials');
                    $this->payfort = new PayFortAcquirer([
                        'api_key'        => $creds['api_key'] ?? '',
                        'api_secret'     => $creds['api_secret'] ?? '',
                        'base_url'       => $creds['base_url'] ?? config('acquirers.payfort.base_url', 'https://api.payfortbank.com'),
                        'webhook_secret' => $creds['webhook_secret'] ?? '',
                    ]);
                    $this->payfort->warmAuthToken();
                }
            }
        }
        return $this->payfort;
    }

    public function payfortForAccount(?string $accountId): ?PayFortAcquirer
    {
        if (!$accountId) {
            return $this->payfort();
        }

        if (!array_key_exists($accountId, $this->payfortByAccount)) {
            $creds = self::resolveAccountCredentials($accountId, 'payfort');
            if ($creds) {
                Log::info('PayFort: using account-level DB credentials', ['account_id' => $accountId]);
                $this->payfortByAccount[$accountId] = new PayFortAcquirer([
                    'api_key'        => $creds['api_key'] ?? '',
                    'api_secret'     => $creds['api_secret'] ?? '',
                    'base_url'       => $creds['base_url'] ?? config('acquirers.payfort.base_url', 'https://api.payfortbank.com'),
                    'webhook_secret' => $creds['webhook_secret'] ?? '',
                ]);
                $this->payfortByAccount[$accountId]->warmAuthToken();
            } else {
                $this->payfortByAccount[$accountId] = $this->payfort();
            }
        }

        return $this->payfortByAccount[$accountId];
    }

    public function blupay(): ?BluPayAcquirer
    {
        if (!$this->blupayInitialized) {
            $this->blupayInitialized = true;

            $envConfigured = config('acquirers.blupay.enabled', false)
                && !empty(config('acquirers.blupay.secret_key'))
                && !empty(config('acquirers.blupay.public_key'));

            if ($envConfigured) {
                $this->blupay = new BluPayAcquirer([
                    'secret_key'     => config('acquirers.blupay.secret_key', ''),
                    'public_key'     => config('acquirers.blupay.public_key', ''),
                    'base_url'       => config('acquirers.blupay.base_url', 'https://api.blupayip.io'),
                    'webhook_secret' => config('acquirers.blupay.webhook_secret', ''),
                ]);
            } else {
                $creds = self::resolveCredentials('blupay');
                if ($creds) {
                    Log::info('BluPay: using DB-stored credentials');
                    $this->blupay = new BluPayAcquirer([
                        'secret_key'     => $creds['secret_key'] ?? '',
                        'public_key'     => $creds['public_key'] ?? '',
                        'base_url'       => $creds['base_url'] ?? config('acquirers.blupay.base_url', 'https://api.blupayip.io'),
                        'webhook_secret' => $creds['webhook_secret'] ?? '',
                    ]);
                }
            }
        }
        return $this->blupay;
    }

    public function witetec(): ?\App\Infrastructure\Acquirers\WitetecAcquirer
    {
        if (!$this->witetecInitialized) {
            $this->witetecInitialized = true;

            $envConfigured = config('acquirers.witetec.enabled', false)
                && !empty(config('acquirers.witetec.api_key'));

            if ($envConfigured) {
                $this->witetec = new \App\Infrastructure\Acquirers\WitetecAcquirer([
                    'api_key'        => config('acquirers.witetec.api_key', ''),
                    'base_url'       => config('acquirers.witetec.base_url', 'https://api.witetec.net'),
                    'webhook_secret' => config('acquirers.witetec.webhook_secret', ''),
                ]);
            } else {
                $creds = self::resolveCredentials('witetec');
                if ($creds) {
                    Log::info('Witetec: using DB-stored credentials');
                    $this->witetec = new \App\Infrastructure\Acquirers\WitetecAcquirer([
                        'api_key'        => $creds['api_key'] ?? '',
                        'base_url'       => $creds['base_url'] ?? config('acquirers.witetec.base_url', 'https://api.witetec.net'),
                        'webhook_secret' => $creds['webhook_secret'] ?? '',
                    ]);
                }
            }
        }
        return $this->witetec;
    }

    public function enki(): ?EnkiAcquirer
    {
        if (!$this->enkiInitialized) {
            $this->enkiInitialized = true;

            $envConfigured = config('acquirers.enki.enabled', false)
                && !empty(config('acquirers.enki.public_key'))
                && !empty(config('acquirers.enki.secret_key'));

            if ($envConfigured) {
                $this->enki = new EnkiAcquirer([
                    'public_key' => config('acquirers.enki.public_key', ''),
                    'secret_key' => config('acquirers.enki.secret_key', ''),
                    'withdrawal_key' => config('acquirers.enki.withdrawal_key', ''),
                    'base_url' => config('acquirers.enki.base_url', 'https://api.enki-bank.com'),
                    'webhook_url' => config('acquirers.enki.webhook_url'),
                ]);
            } else {
                $creds = self::resolveCredentials('enki');
                if ($creds) {
                    Log::info('Enki: using DB-stored credentials');
                    $this->enki = new EnkiAcquirer([
                        'public_key' => $creds['public_key'] ?? '',
                        'secret_key' => $creds['secret_key'] ?? '',
                        'withdrawal_key' => $creds['withdrawal_key'] ?? '',
                        'base_url' => $creds['base_url'] ?? config('acquirers.enki.base_url', 'https://api.enki-bank.com'),
                        'webhook_url' => $creds['webhook_url'] ?? config('acquirers.enki.webhook_url'),
                    ]);
                }
            }
        }

        return $this->enki;
    }

    public function enkiForAccount(?string $accountId): ?EnkiAcquirer
    {
        if (!$accountId) {
            return $this->enki();
        }

        if (!array_key_exists($accountId, $this->enkiByAccount)) {
            $creds = self::resolveAccountCredentials($accountId, 'enki');
            if ($creds) {
                Log::info('Enki: using account-level DB credentials', ['account_id' => $accountId]);
                $this->enkiByAccount[$accountId] = new EnkiAcquirer([
                    'public_key' => $creds['public_key'] ?? '',
                    'secret_key' => $creds['secret_key'] ?? '',
                    'withdrawal_key' => $creds['withdrawal_key'] ?? '',
                    'base_url' => $creds['base_url'] ?? config('acquirers.enki.base_url', 'https://api.enki-bank.com'),
                    'webhook_url' => $creds['webhook_url'] ?? config('acquirers.enki.webhook_url'),
                ]);
            } else {
                $this->enkiByAccount[$accountId] = $this->enki();
            }
        }

        return $this->enkiByAccount[$accountId];
    }

    public function asaas(): ?\App\Infrastructure\Acquirers\AsaasAcquirer
    {
        if (!$this->asaasInitialized) {
            $this->asaasInitialized = true;

            $envConfigured = config('acquirers.asaas.enabled', false)
                && !empty(config('acquirers.asaas.api_key'));

            if ($envConfigured) {
                $this->asaas = new \App\Infrastructure\Acquirers\AsaasAcquirer([
                    'api_key'        => config('acquirers.asaas.api_key', ''),
                    'base_url'       => config('acquirers.asaas.base_url', 'https://api-sandbox.asaas.com/v3'),
                    'webhook_token'  => config('acquirers.asaas.webhook_token', ''),
                ]);
            } else {
                $creds = self::resolveCredentials('asaas');
                if ($creds) {
                    Log::info('Asaas: using DB-stored credentials');
                    $this->asaas = new \App\Infrastructure\Acquirers\AsaasAcquirer([
                        'api_key'        => $creds['api_key'] ?? '',
                        'base_url'       => $creds['base_url'] ?? config('acquirers.asaas.base_url', 'https://api-sandbox.asaas.com/v3'),
                        'webhook_token'  => $creds['webhook_token'] ?? '',
                    ]);
                }
            }
        }
        return $this->asaas;
    }

    public function huntersub(): ?\App\Infrastructure\Acquirers\HunterSubAcquirer
    {
        if (!$this->huntersubInitialized) {
            $this->huntersubInitialized = true;

            $envConfigured = config('acquirers.huntersub.enabled', false)
                && !empty(config('acquirers.huntersub.api_key'))
                && !empty(config('acquirers.huntersub.company_id'));

            if ($envConfigured) {
                $this->huntersub = new \App\Infrastructure\Acquirers\HunterSubAcquirer([
                    'client_id'      => config('acquirers.huntersub.client_id', ''),
                    'api_key'        => config('acquirers.huntersub.api_key', ''),
                    'company_id'     => config('acquirers.huntersub.company_id', ''),
                    'base_url'       => config('acquirers.huntersub.base_url', 'https://api.huntersubv2.com/functions/v1'),
                    'webhook_url'    => config('acquirers.huntersub.webhook_url', ''),
                    'webhook_secret' => config('acquirers.huntersub.webhook_secret', ''),
                ]);
            } else {
                $creds = self::resolveCredentials('huntersub');
                if ($creds) {
                    Log::info('HunterSub: using DB-stored credentials');
                    $this->huntersub = new \App\Infrastructure\Acquirers\HunterSubAcquirer([
                        'client_id'      => $creds['client_id'] ?? $creds['company_id'] ?? '',
                        'api_key'        => $creds['api_key'] ?? '',
                        'company_id'     => $creds['company_id'] ?? '',
                        'base_url'       => $creds['base_url'] ?? config('acquirers.huntersub.base_url', 'https://api.huntersubv2.com/functions/v1'),
                        'webhook_url'    => $creds['webhook_url'] ?? config('acquirers.huntersub.webhook_url', ''),
                        'webhook_secret' => $creds['webhook_secret'] ?? '',
                    ]);
                }
            }
        }
        return $this->huntersub;
    }

    public function huntersubForAccount(?string $accountId): ?\App\Infrastructure\Acquirers\HunterSubAcquirer
    {
        if (!$accountId) {
            return $this->huntersub();
        }

        if (!array_key_exists($accountId, $this->huntersubByAccount)) {
            $creds = self::resolveAccountCredentials($accountId, 'huntersub');
            if ($creds) {
                Log::info('HunterSub: using account-level DB credentials', ['account_id' => $accountId]);
                $this->huntersubByAccount[$accountId] = new \App\Infrastructure\Acquirers\HunterSubAcquirer([
                    'client_id'      => $creds['client_id'] ?? $creds['company_id'] ?? '',
                    'api_key'        => $creds['api_key'] ?? '',
                    'company_id'     => $creds['company_id'] ?? '',
                    'base_url'       => $creds['base_url'] ?? config('acquirers.huntersub.base_url', 'https://api.huntersubv2.com/functions/v1'),
                    'webhook_url'    => $creds['webhook_url'] ?? config('acquirers.huntersub.webhook_url', ''),
                    'webhook_secret' => $creds['webhook_secret'] ?? '',
                ]);
            } else {
                $this->huntersubByAccount[$accountId] = $this->huntersub();
            }
        }

        return $this->huntersubByAccount[$accountId];
    }

    public function sharkhubsub(): ?\App\Infrastructure\Acquirers\SharkHubSubAcquirer
    {
        if (!$this->sharkhubsubInitialized) {
            $this->sharkhubsubInitialized = true;

            $envConfigured = config('acquirers.sharkhubsub.enabled', false)
                && !empty(config('acquirers.sharkhubsub.api_key'));

            if ($envConfigured) {
                $this->sharkhubsub = new \App\Infrastructure\Acquirers\SharkHubSubAcquirer([
                    'api_key' => config('acquirers.sharkhubsub.api_key', ''),
                    'withdrawal_key' => config('acquirers.sharkhubsub.withdrawal_key', ''),
                    'base_url' => config('acquirers.sharkhubsub.base_url', 'https://api.sharkhubsubadquirente.com'),
                    'webhook_url' => config('acquirers.sharkhubsub.webhook_url', ''),
                    'transfer_webhook_url' => config('acquirers.sharkhubsub.transfer_webhook_url', ''),
                    'webhook_secret' => config('acquirers.sharkhubsub.webhook_secret', ''),
                    'transfer_webhook_secret' => config('acquirers.sharkhubsub.transfer_webhook_secret', ''),
                ]);
            } else {
                $creds = self::resolveCredentials('sharkhubsub');
                if ($creds) {
                    Log::info('SharkHubSub: using DB-stored credentials');
                    $this->sharkhubsub = new \App\Infrastructure\Acquirers\SharkHubSubAcquirer([
                        'api_key' => $creds['api_key'] ?? '',
                        'withdrawal_key' => $creds['withdrawal_key'] ?? $creds['transfer_api_key'] ?? '',
                        'base_url' => $creds['base_url'] ?? config('acquirers.sharkhubsub.base_url', 'https://api.sharkhubsubadquirente.com'),
                        'webhook_url' => $creds['webhook_url'] ?? config('acquirers.sharkhubsub.webhook_url', ''),
                        'transfer_webhook_url' => $creds['transfer_webhook_url'] ?? config('acquirers.sharkhubsub.transfer_webhook_url', ''),
                        'webhook_secret' => $creds['webhook_secret'] ?? '',
                        'transfer_webhook_secret' => $creds['transfer_webhook_secret'] ?? '',
                    ]);
                }
            }
        }

        return $this->sharkhubsub;
    }

    public function sharkhubsubForAccount(?string $accountId): ?\App\Infrastructure\Acquirers\SharkHubSubAcquirer
    {
        if (!$accountId) {
            return $this->sharkhubsub();
        }

        if (!array_key_exists($accountId, $this->sharkhubsubByAccount)) {
            $creds = self::resolveAccountCredentials($accountId, 'sharkhubsub');
            if ($creds) {
                Log::info('SharkHubSub: using account-level DB credentials', ['account_id' => $accountId]);
                $this->sharkhubsubByAccount[$accountId] = new \App\Infrastructure\Acquirers\SharkHubSubAcquirer([
                    'api_key' => $creds['api_key'] ?? '',
                    'withdrawal_key' => $creds['withdrawal_key'] ?? $creds['transfer_api_key'] ?? '',
                    'base_url' => $creds['base_url'] ?? config('acquirers.sharkhubsub.base_url', 'https://api.sharkhubsubadquirente.com'),
                    'webhook_url' => $creds['webhook_url'] ?? config('acquirers.sharkhubsub.webhook_url', ''),
                    'transfer_webhook_url' => $creds['transfer_webhook_url'] ?? config('acquirers.sharkhubsub.transfer_webhook_url', ''),
                    'webhook_secret' => $creds['webhook_secret'] ?? '',
                    'transfer_webhook_secret' => $creds['transfer_webhook_secret'] ?? '',
                ]);
            } else {
                $this->sharkhubsubByAccount[$accountId] = $this->sharkhubsub();
            }
        }

        return $this->sharkhubsubByAccount[$accountId];
    }

    public function babylon(): ?\App\Infrastructure\Acquirers\BabylonAcquirer
    {
        if (!$this->babylonInitialized) {
            $this->babylonInitialized = true;

            $envConfigured = config('acquirers.babylon.enabled', false)
                && !empty(config('acquirers.babylon.secret_key'))
                && !empty(config('acquirers.babylon.company_id'));

            if ($envConfigured) {
                $this->babylon = new \App\Infrastructure\Acquirers\BabylonAcquirer([
                    'secret_key'     => config('acquirers.babylon.secret_key', ''),
                    'company_id'     => config('acquirers.babylon.company_id', ''),
                    'base_url'       => config('acquirers.babylon.base_url', 'https://api.bancobabylon.com/functions/v1'),
                    'webhook_secret' => config('acquirers.babylon.webhook_secret', ''),
                ]);
            } else {
                $creds = self::resolveCredentials('babylon');
                if ($creds) {
                    Log::info('Babylon: using DB-stored credentials');
                    $this->babylon = new \App\Infrastructure\Acquirers\BabylonAcquirer([
                        'secret_key'     => $creds['secret_key'] ?? '',
                        'company_id'     => $creds['company_id'] ?? '',
                        'base_url'       => $creds['base_url'] ?? config('acquirers.babylon.base_url', 'https://api.bancobabylon.com/functions/v1'),
                        'webhook_secret' => $creds['webhook_secret'] ?? '',
                    ]);
                }
            }
        }
        return $this->babylon;
    }

    public function getAcquirerInstance(string $acquirerKey)
    {
        return match ($acquirerKey) {
            'payfort' => $this->payfort(),
            'blupay' => $this->blupay(),
            'enki' => $this->enki(),
            'babylon' => $this->babylon(),
            'huntersub' => $this->huntersub(),
            'sharkhubsub' => $this->sharkhubsub(),
            'witetec' => $this->witetec(),
            'asaas' => $this->asaas(),
            default => null,
        };
    }

    /**
     * Get ordered list of payout-capable PIX OUT acquirers using global admin priority.
     */
    public function getPixOutAcquirerOrder(): array
    {
        $forced = $this->getForcedAcquirer();
        if ($forced !== null) {
            return [$forced];
        }

        $fallbackCapable = ['payfort', 'blupay', 'enki', 'babylon', 'huntersub', 'sharkhubsub'];

        $candidates = $this->appendConfiguredFallbacks($this->listConfiguredAcquirerKeys('pix_out'));
        $candidates = array_values(array_filter($candidates, function (string $key) use ($fallbackCapable): bool {
            if (!in_array($key, $fallbackCapable, true)) {
                return false;
            }

            $instance = $this->getAcquirerInstance($key);
            return $instance && method_exists($instance, 'sendPixTransfer');
        }));

        if ($candidates === []) {
            return [];
        }

        try {
            $order = $this->rotation()->getOrderedAcquirers('pix_out', $candidates);
            return $order !== [] ? $order : $candidates;
        } catch (\Throwable $e) {
            Log::warning('Failed to apply PIX OUT rotation strategy', ['error' => $e->getMessage()]);
            return $candidates;
        }
    }

    /**
     * Get the active acquirer name based on database priority, then config fallback.
     * Lower priority number = higher precedence (0 is highest).
     */

    public function getActiveAcquirerName(?string $paymentMethod = null): string
    {
        $forced = $this->getForcedAcquirer();
        if ($forced !== null) {
            return $forced;
        }

        $normalizedMethod = $this->normalizePaymentMethod($paymentMethod);
        $candidates = $this->appendConfiguredFallbacks($this->listConfiguredAcquirerKeys($normalizedMethod));

        if ($candidates !== []) {
            try {
                $selected = $this->rotation()->choosePrimaryAcquirer($normalizedMethod, $candidates);
                if ($selected !== null) {
                    Log::debug('Active acquirer selected by rotation strategy', [
                        'acquirer' => $selected,
                        'payment_method' => $normalizedMethod,
                    ]);

                    return $selected;
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to apply rotation strategy for active acquirer selection', [
                    'payment_method' => $normalizedMethod,
                    'error' => $e->getMessage(),
                ]);
            }

            return $candidates[0];
        }

        // Fallback to config default
        $default = config('acquirers.default', 'payfort');

        if ($default === 'payfort' && self::isPayFortConfigured()) return 'payfort';
        if ($default === 'blupay' && self::isBluPayConfigured()) return 'blupay';
        if ($default === 'enki' && self::isEnkiConfigured()) return 'enki';
        if ($default === 'witetec' && self::isWitetecConfigured()) return 'witetec';
        if ($default === 'asaas' && self::isAsaasConfigured()) return 'asaas';
        if ($default === 'sharkhubsub' && self::isSharkHubSubConfigured()) return 'sharkhubsub';
        if (self::isBabylonConfigured()) return 'babylon';

        return 'mock';
    }

    /**
     * Get ordered list of available PIX acquirers (by DB priority, then fallbacks).
     */
    public function getPixAcquirerOrder(string $primary): array
    {
        $forced = $this->getForcedAcquirer();
        if ($forced !== null) {
            return [$forced];
        }

        $candidates = $this->appendConfiguredFallbacks($this->listConfiguredAcquirerKeys('pix'));

        if ($candidates === []) {
            return [];
        }

        try {
            $order = $this->rotation()->getOrderedAcquirers('pix', $candidates, $primary);
            if ($order !== []) {
                return $order;
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to apply PIX rotation strategy', ['error' => $e->getMessage()]);
        }

        if (in_array($primary, $candidates, true)) {
            $candidates = array_values(array_diff($candidates, [$primary]));
            array_unshift($candidates, $primary);
        }

        return $candidates;
    }

    /**
     * Get the active acquirer name for a specific account.
     * If the account has an active acquirer assigned in account_acquirers, use it.
     * Falls back to global admin priority.
     */
    public function getActiveAcquirerNameForAccount(?string $accountId, string $paymentMethod = 'pix'): string
    {
        if ($accountId) {
            try {
                $normalizedMethod = $this->normalizePaymentMethod($paymentMethod);

                // Look for an account-specific acquirer assignment ordered by priority
                $accountAcquirer = \App\Models\AccountAcquirer::where('account_id', $accountId)
                    ->where('is_active', true)
                    ->join('acquirers', 'acquirers.id', '=', 'account_acquirers.acquirer_id')
                    ->select('account_acquirers.*', 'acquirers.slug as acquirer_slug')
                    ->orderBy('account_acquirers.priority', 'asc')
                    ->get();

                foreach ($accountAcquirer as $aa) {
                    $slug = $aa->acquirer_slug;
                    if (!$slug) {
                        continue;
                    }

                    // Check that the global acquirer config is enabled for this acquirer
                    $globalConfig = \App\Models\AcquirerConfig::where('acquirer_key', $slug)->first();
                    if (!$globalConfig || !$globalConfig->enabled) {
                        continue;
                    }

                    // Check that this acquirer supports the payment method
                    $supportedMethods = array_map('strtolower', $globalConfig->supported_methods ?? []);
                    if (!empty($supportedMethods) && !in_array($normalizedMethod, $supportedMethods, true)) {
                        continue;
                    }

                    // Check that the acquirer instance is actually available
                    if ($this->isAcquirerAvailable($slug, $globalConfig)) {
                        Log::info('Using account-specific acquirer', [
                            'account_id' => $accountId,
                            'acquirer' => $slug,
                            'payment_method' => $normalizedMethod,
                        ]);
                        return $slug;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to resolve account-specific acquirer, falling back to global', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->getActiveAcquirerName($paymentMethod);
    }

    /**
     * Get ordered list of PIX acquirers for a specific account.
     * Uses account_acquirers if assigned, otherwise falls back to global order.
     */
    public function getPixAcquirerOrderForAccount(?string $accountId, string $primary): array
    {
        if ($accountId) {
            try {
                $accountAcquirers = \App\Models\AccountAcquirer::where('account_id', $accountId)
                    ->where('is_active', true)
                    ->join('acquirers', 'acquirers.id', '=', 'account_acquirers.acquirer_id')
                    ->select('account_acquirers.*', 'acquirers.slug as acquirer_slug')
                    ->orderBy('account_acquirers.priority', 'asc')
                    ->get();

                $accountKeys = [];
                foreach ($accountAcquirers as $aa) {
                    $slug = $aa->acquirer_slug;
                    if (!$slug) {
                        continue;
                    }

                    $globalConfig = \App\Models\AcquirerConfig::where('acquirer_key', $slug)->first();
                    if (!$globalConfig || !$globalConfig->enabled) {
                        continue;
                    }

                    $supportedMethods = array_map('strtolower', $globalConfig->supported_methods ?? []);
                    if (!empty($supportedMethods) && !in_array('pix', $supportedMethods, true)) {
                        continue;
                    }

                    if ($this->isAcquirerAvailable($slug, $globalConfig)) {
                        $accountKeys[] = $slug;
                    }
                }

                if (!empty($accountKeys)) {
                    Log::info('Using account-specific PIX acquirer order', [
                        'account_id' => $accountId,
                        'order' => $accountKeys,
                    ]);
                    // Put preferred primary first if it's in the list
                    if (in_array($primary, $accountKeys, true)) {
                        $accountKeys = array_values(array_diff($accountKeys, [$primary]));
                        array_unshift($accountKeys, $primary);
                    }
                    return $accountKeys;
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to resolve account-specific PIX order, falling back to global', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->getPixAcquirerOrder($primary);
    }

    /**
     * Reset singleton (for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
