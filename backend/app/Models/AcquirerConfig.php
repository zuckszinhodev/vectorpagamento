<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class AcquirerConfig extends Model
{
    use HasUuids, SoftDeletes;

    public static function normalizeAcquirerKey(string $acquirerKey): string
    {
        return str_starts_with($acquirerKey, 'sharkhubsub') ? 'sharkhubsub' : $acquirerKey;
    }

    protected $keyType = 'string';
    public $incrementing = false;

    /**
     * Get an AcquirerConfig by key with Redis caching (5 min TTL).
     * Avoids repeated DB queries for the same small dataset.
     */
    public static function findByKey(string $acquirerKey): ?self
    {
        return Cache::remember(
            "acquirer_config:{$acquirerKey}",
            300,
            fn () => static::where('acquirer_key', $acquirerKey)->first()
        );
    }

    /**
     * Get all AcquirerConfigs keyed by acquirer_key with Redis caching.
     */
    public static function allCached(): \Illuminate\Support\Collection
    {
        return Cache::remember(
            'acquirer_configs:all',
            300,
            fn () => static::all()->keyBy('acquirer_key')
        );
    }

    /**
     * Clear the acquirer config cache (call on config updates).
     */
    public static function clearCache(): void
    {
        try {
            Cache::forget('acquirer_configs:all');
            static::all()->each(fn ($c) => Cache::forget("acquirer_config:{$c->acquirer_key}"));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to clear acquirer config cache', ['error' => $e->getMessage()]);
        }
    }

    protected static function booted(): void
    {
        static::saved(function () {
            try { static::clearCache(); } catch (\Throwable $e) {}
        });
        static::deleted(function () {
            try { static::clearCache(); } catch (\Throwable $e) {}
        });
    }

    protected $fillable = [
        'acquirer_key',
        'display_name',
        'nominal_name',
        'type',
        'enabled',
        'credentials',
        'supported_methods',
        'credential_schema',
        'acquirer_fee_percentage',
        'acquirer_fee_fixed',
        'acquirer_fee_pix_out_percentage',
        'acquirer_fee_pix_out_fixed',
        'gateway_fee_percentage',
        'gateway_fee_fixed',
        'gateway_fee_pix_out_percentage',
        'gateway_fee_pix_out_fixed',
        'priority',
        'settings',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'supported_methods' => 'array',
        'credential_schema' => 'array',
        'settings' => 'array',
        'acquirer_fee_percentage' => 'decimal:2',
        'acquirer_fee_fixed' => 'decimal:2',
        'acquirer_fee_pix_out_percentage' => 'decimal:2',
        'acquirer_fee_pix_out_fixed' => 'decimal:2',
        'gateway_fee_percentage' => 'decimal:2',
        'gateway_fee_fixed' => 'decimal:2',
        'gateway_fee_pix_out_percentage' => 'decimal:2',
        'gateway_fee_pix_out_fixed' => 'decimal:2',
    ];

    protected $hidden = [
        'credentials',
    ];

    /**
     * Get credentials (decrypted)
     */
    public function getDecryptedCredentials(): ?array
    {
        $raw = $this->attributes['credentials'] ?? null;
        
        if (!$raw || $raw === 'null') {
            return null;
        }

        // Try decrypting (credentials stored via Eloquent mutator are encrypted)
        try {
            $decrypted = Crypt::decryptString($raw);
            $result = json_decode($decrypted, true);
            if (is_array($result)) {
                // Decrypt each individual value if it's encrypted
                foreach ($result as $key => $value) {
                    if (is_string($value) && !empty($value)) {
                        try {
                            // Try to decrypt the value
                            $result[$key] = Crypt::decryptString($value);
                        } catch (\Exception $e) {
                            // Value is not encrypted, keep as is
                        }
                    }
                }
                return $result;
            }
        } catch (\Exception $e) {
            // Not encrypted — try parsing as plain JSON
        }

        // Fallback: decrypt legacy payloads encrypted via Crypt::encrypt/decrypt helpers
        try {
            $decrypted = Crypt::decrypt($raw);
            if (is_array($decrypted)) {
                return $decrypted;
            }
            $result = json_decode((string) $decrypted, true);
            if (is_array($result)) {
                foreach ($result as $key => $value) {
                    if (is_string($value) && !empty($value)) {
                        try {
                            $result[$key] = Crypt::decryptString($value);
                        } catch (\Exception $e) {
                            // Value is not encrypted, keep as is
                        }
                    }
                }
                return $result;
            }
        } catch (\Exception $e) {
            // Not decryptable with legacy method
        }

        // Fallback: try parsing as plain JSON (e.g., inserted via raw DB query)
        try {
            $result = json_decode($raw, true);
            if (is_array($result)) {
                // Decrypt each individual value if it's encrypted
                foreach ($result as $key => $value) {
                    if (is_string($value) && !empty($value)) {
                        try {
                            // Try to decrypt the value
                            $result[$key] = Crypt::decryptString($value);
                        } catch (\Exception $e) {
                            // Value is not encrypted, keep as is
                        }
                    }
                }
                return $result;
            }
        } catch (\Exception $e) {
            // Not valid JSON either
        }

        return null;
    }

    /**
     * Set credentials (encrypted)
     */
    public function setCredentialsAttribute($value): void
    {
        if ($value === null) {
            $this->attributes['credentials'] = null;
            return;
        }

        $jsonValue = is_string($value) ? $value : json_encode($value);
        $this->attributes['credentials'] = Crypt::encryptString($jsonValue);
    }

    /**
     * Check if acquirer has valid credentials
     * Checks both database credentials AND config/env credentials
     */
    public function hasValidCredentials(): bool
    {
        // First check database-stored credentials
        $credentials = $this->getDecryptedCredentials();
        
        if ($credentials && $this->validateCredentialsByKey($this->acquirer_key, $credentials)) {
            return true;
        }

        // Fallback: check config/env credentials
        $envCredentials = $this->getEnvCredentials();
        if ($envCredentials) {
            return $this->validateCredentialsByKey($this->acquirer_key, $envCredentials);
        }

        return false;
    }

    /**
     * Get credentials from config/env
     */
    protected function getEnvCredentials(): ?array
    {
        $key = self::normalizeAcquirerKey($this->acquirer_key);
        $config = config("acquirers.{$key}");
        
        if (!$config || !is_array($config)) {
            return null;
        }

        // Map config keys to credential keys for each acquirer
        return match ($key) {
            'stripe' => [
                'secret_key' => $config['api_key'] ?? $config['secret_key'] ?? null,
                'publishable_key' => $config['public_key'] ?? $config['publishable_key'] ?? null,
                'webhook_secret' => $config['webhook_secret'] ?? null,
            ],
            'pagarme' => [
                'api_key' => $config['api_key'] ?? null,
                'encryption_key' => $config['encryption_key'] ?? null,
            ],
            'mercadopago' => [
                'access_token' => $config['access_token'] ?? null,
                'public_key' => $config['public_key'] ?? null,
            ],
            'babylon' => [
                'client_id' => $config['company_id'] ?? $config['client_id'] ?? null,
                'client_secret' => $config['secret_key'] ?? $config['client_secret'] ?? null,
                'environment' => 'production',
            ],
            'payfort' => [
                'api_key' => $config['api_key'] ?? null,
                'api_secret' => $config['api_secret'] ?? null,
                'webhook_secret' => $config['webhook_secret'] ?? null,
                'base_url' => $config['base_url'] ?? null,
            ],
            'blupay' => [
                'secret_key' => $config['secret_key'] ?? null,
                'public_key' => $config['public_key'] ?? null,
                'webhook_secret' => $config['webhook_secret'] ?? null,
                'base_url' => $config['base_url'] ?? null,
            ],
            'turbos' => [
                'public_key' => $config['public_key'] ?? null,
                'secret_key' => $config['secret_key'] ?? null,
                'withdraw_key' => $config['withdraw_key'] ?? null,
                'webhook_secret' => $config['webhook_secret'] ?? null,
                'base_url' => $config['base_url'] ?? null,
            ],
            'enki' => [
                'public_key' => $config['public_key'] ?? null,
                'secret_key' => $config['secret_key'] ?? null,
                'withdrawal_key' => $config['withdrawal_key'] ?? null,
                'base_url' => $config['base_url'] ?? null,
                'webhook_url' => $config['webhook_url'] ?? null,
            ],
            'huntersub' => [
                'client_id' => $config['client_id'] ?? $config['company_id'] ?? null,
                'api_key' => $config['api_key'] ?? null,
                'company_id' => $config['company_id'] ?? null,
                'webhook_secret' => $config['webhook_secret'] ?? null,
                'base_url' => $config['base_url'] ?? null,
                'webhook_url' => $config['webhook_url'] ?? null,
            ],
            'sharkhubsub' => [
                'api_key' => $config['api_key'] ?? null,
                'withdrawal_key' => $config['withdrawal_key'] ?? null,
                'webhook_secret' => $config['webhook_secret'] ?? null,
                'transfer_webhook_secret' => $config['transfer_webhook_secret'] ?? null,
                'base_url' => $config['base_url'] ?? null,
                'webhook_url' => $config['webhook_url'] ?? null,
                'transfer_webhook_url' => $config['transfer_webhook_url'] ?? null,
            ],
            'witetec' => [
                'api_key' => $config['api_key'] ?? null,
                'webhook_secret' => $config['webhook_secret'] ?? null,
                'base_url' => $config['base_url'] ?? null,
            ],
            default => null,
        };
    }

    /**
     * Validate credentials array by acquirer key
     */
    public function validateCredentialsByKey(string $key, array $credentials): bool
    {
        $key = self::normalizeAcquirerKey($key);

        return match ($key) {
            'stripe' => !empty($credentials['secret_key']) && !empty($credentials['publishable_key']),
            'pagarme' => !empty($credentials['api_key']),
            'mercadopago' => !empty($credentials['access_token']),
            'babylon' => !empty($credentials['client_id']) && !empty($credentials['client_secret']),
            'payfort' => !empty($credentials['api_key']) && !empty($credentials['api_secret']),
            'blupay' => !empty($credentials['secret_key']) && !empty($credentials['public_key']),
            'turbos' => !empty($credentials['public_key']) && !empty($credentials['secret_key']),
            'ascentpay' => !empty($credentials['secret_key']) && !empty($credentials['public_key']),
            'huntersub' => !empty($credentials['api_key']) && !empty($credentials['company_id']),
            'sharkhubsub' => !empty($credentials['api_key']),
            'enki' => !empty($credentials['public_key']) && !empty($credentials['secret_key']),
            'witetec' => !empty($credentials['api_key']),
            default => false,
        };
    }

    /**
     * Get credential fields required for each acquirer
     */
    public static function getCredentialFields(string $acquirerKey): array
    {
        $acquirerKey = self::normalizeAcquirerKey($acquirerKey);

        return match ($acquirerKey) {
            'stripe' => [
                ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password'],
                ['key' => 'publishable_key', 'label' => 'Publishable Key', 'type' => 'text'],
                ['key' => 'webhook_secret', 'label' => 'Webhook Secret', 'type' => 'password'],
            ],
            'pagarme' => [
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password'],
                ['key' => 'encryption_key', 'label' => 'Encryption Key', 'type' => 'password'],
            ],
            'mercadopago' => [
                ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password'],
                ['key' => 'public_key', 'label' => 'Public Key', 'type' => 'text'],
            ],
            'babylon' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text'],
                ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
                ['key' => 'environment', 'label' => 'Ambiente', 'type' => 'select', 'options' => ['sandbox', 'production']],
            ],
            'payfort' => [
                ['key' => 'api_key', 'label' => 'API Key (pk_live_*)', 'type' => 'password'],
                ['key' => 'api_secret', 'label' => 'API Secret (sk_live_*)', 'type' => 'password'],
                ['key' => 'webhook_secret', 'label' => 'Webhook Signature Secret (whk_live_*)', 'type' => 'password'],
                ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'text'],
            ],
            'blupay' => [
                ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password'],
                ['key' => 'public_key', 'label' => 'Public Key', 'type' => 'text'],
                ['key' => 'webhook_secret', 'label' => 'Webhook HMAC Secret', 'type' => 'password'],
                ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'text'],
            ],
            'turbos' => [
                ['key' => 'public_key', 'label' => 'Public Key', 'type' => 'text'],
                ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password'],
                ['key' => 'withdraw_key', 'label' => 'Withdraw Key (x-withdraw-key)', 'type' => 'password'],
                ['key' => 'webhook_secret', 'label' => 'Webhook Secret', 'type' => 'password'],
                ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'text'],
            ],
            'ascentpay' => [
                ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password'],
                ['key' => 'public_key', 'label' => 'Public Key', 'type' => 'text'],
                ['key' => 'company_id', 'label' => 'Company ID', 'type' => 'text'],
                ['key' => 'webhook_secret', 'label' => 'Webhook Secret', 'type' => 'password'],
                ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'text'],
            ],
            'enki' => [
                ['key' => 'public_key', 'label' => 'Public Key', 'type' => 'text'],
                ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password'],
                ['key' => 'withdrawal_key', 'label' => 'Withdrawal Key', 'type' => 'password'],
                ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'text'],
                ['key' => 'webhook_url', 'label' => 'Webhook URL', 'type' => 'text'],
            ],
            'huntersub' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text'],
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password'],
                ['key' => 'company_id', 'label' => 'Company ID', 'type' => 'text'],
                ['key' => 'webhook_secret', 'label' => 'Webhook Secret', 'type' => 'password'],
                ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'text'],
                ['key' => 'webhook_url', 'label' => 'Webhook URL', 'type' => 'text'],
            ],
            'sharkhubsub' => [
                ['key' => 'api_key', 'label' => 'API Key Pagamento', 'type' => 'password'],
                ['key' => 'withdrawal_key', 'label' => 'API Key Saque', 'type' => 'password'],
                ['key' => 'webhook_secret', 'label' => 'Webhook Secret Pagamento', 'type' => 'password'],
                ['key' => 'transfer_webhook_secret', 'label' => 'Webhook Secret Saque', 'type' => 'password'],
                ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'text'],
                ['key' => 'webhook_url', 'label' => 'Webhook Pagamento URL', 'type' => 'text'],
                ['key' => 'transfer_webhook_url', 'label' => 'Webhook Saque URL', 'type' => 'text'],
            ],
            'witetec' => [
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password'],
                ['key' => 'webhook_secret', 'label' => 'Webhook Secret', 'type' => 'password'],
                ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'text'],
            ],
            default => [],
        };
    }

    /**
     * Scope: Only enabled acquirers
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope: Order by priority
     */
    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'asc');
    }

    /**
     * Calculate the acquirer fee for a given amount
     * This is what the acquirer (e.g., Babylon) charges us
     * 
     * @param int $amountCents Amount in cents
     * @return int Fee in cents
     */
    public function calculateAcquirerFee(int $amountCents): int
    {
        $percentageFee = (int) round($amountCents * ($this->acquirer_fee_percentage / 100));
        $fixedFee = (int) round($this->acquirer_fee_fixed * 100); // Convert to cents
        
        return $percentageFee + $fixedFee;
    }

    private function resolveGatewayFeeRule(?string $paymentMethod = null, int $installments = 1): array
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        $feeRules = is_array($settings['gateway_fee_rules'] ?? null) ? $settings['gateway_fee_rules'] : [];
        $method = strtolower(trim((string) ($paymentMethod ?? '')));

        if ($method === '') {
            return [
                'percentage' => (float) $this->gateway_fee_percentage,
                'fixed' => (float) $this->gateway_fee_fixed,
            ];
        }

        if ($method === 'card') {
            $method = 'credit_card';
        }

        $rule = $feeRules[$method] ?? null;
        if (!is_array($rule)) {
            return [
                'percentage' => (float) $this->gateway_fee_percentage,
                'fixed' => (float) $this->gateway_fee_fixed,
            ];
        }

        $percentage = isset($rule['percentage']) ? (float) $rule['percentage'] : null;
        $fixed = isset($rule['fixed']) ? (float) $rule['fixed'] : null;

        if ($method === 'credit_card') {
            $installmentRules = is_array($rule['installments'] ?? null) ? $rule['installments'] : [];
            $installmentKey = (string) max(1, $installments);
            $installmentRule = $installmentRules[$installmentKey] ?? null;

            if (is_array($installmentRule)) {
                if (isset($installmentRule['percentage'])) {
                    $percentage = (float) $installmentRule['percentage'];
                }

                if (isset($installmentRule['fixed'])) {
                    $fixed = (float) $installmentRule['fixed'];
                }
            }
        }

        return [
            'percentage' => $percentage ?? (float) $this->gateway_fee_percentage,
            'fixed' => $fixed ?? (float) $this->gateway_fee_fixed,
        ];
    }

    /**
     * Calculate the gateway fee for a given amount
     * This is what Vector Pagamentos charges customers
     * 
     * @param int $amountCents Amount in cents
     * @return int Fee in cents
     */
    public function calculateGatewayFee(int $amountCents, ?string $paymentMethod = null, int $installments = 1): int
    {
        $rule = $this->resolveGatewayFeeRule($paymentMethod, $installments);
        $percentageFee = (int) round($amountCents * (($rule['percentage'] ?? 0) / 100));
        $fixedFee = (int) round(($rule['fixed'] ?? 0) * 100); // Convert to cents
        
        return $percentageFee + $fixedFee;
    }

    /**
     * Calculate the total fee (gateway + acquirer) for a given amount
     * This is the full fee that should be deducted from the transaction
     * 
     * @param int $amountCents Amount in cents
     * @return int Total fee in cents
     */
    public function calculateTotalFee(int $amountCents, ?string $paymentMethod = null, int $installments = 1): int
    {
        $gatewayFee = $this->calculateGatewayFee($amountCents, $paymentMethod, $installments);
        $acquirerFee = $this->calculateAcquirerFee($amountCents);
        
        return $gatewayFee + $acquirerFee;
    }

    /**
     * Calculate the net amount after total fees (gateway + acquirer)
     * This is what the customer receives
     * 
     * @param int $amountCents Transaction amount in cents
     * @return int Net amount in cents
     */
    public function calculateNetAmount(int $amountCents, ?string $paymentMethod = null, int $installments = 1): int
    {
        $totalFee = $this->calculateTotalFee($amountCents, $paymentMethod, $installments);
        return $amountCents - $totalFee;
    }

    /**
     * Calculate our profit (gateway fee - acquirer fee)
     * 
     * @param int $amountCents Transaction amount in cents
     * @return int Profit in cents
     */
    public function calculatePlatformProfit(int $amountCents, ?string $paymentMethod = null, int $installments = 1): int
    {
        $gatewayFee = $this->calculateGatewayFee($amountCents, $paymentMethod, $installments);
        $acquirerFee = $this->calculateAcquirerFee($amountCents);
        
        return $gatewayFee - $acquirerFee;
    }
}
