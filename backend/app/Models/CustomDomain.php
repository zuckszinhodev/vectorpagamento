<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CustomDomain extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    public const TYPE_CUSTOM = 'custom';
    public const TYPE_VECTOR = 'vectorpagamentos';
    public const TYPE_GATEPRO = self::TYPE_VECTOR;

    protected $fillable = [
        'id',
        'account_id',
        'domain',
        'type',
        'subdomain_slug',
        'verification_token',
        'status',
        'is_primary',
        'use_for',
        'ssl_enabled',
        'verified_at',
        'last_check_at',
        'last_check_error',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'use_for' => 'array',
        'ssl_enabled' => 'boolean',
        'verified_at' => 'datetime',
        'last_check_at' => 'datetime',
    ];

    protected $hidden = [
        'verification_token',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($domain) {
            if (empty($domain->verification_token)) {
                $domain->verification_token = Str::random(32);
            }
        });
    }

    /**
     * Get the account that owns the domain.
     */
    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Check if domain is verified.
     */
    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    /**
     * Check if domain is pending verification.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Get the CNAME target for verification.
     */
    public function getCnameTarget(): string
    {
        return config('app.checkout_domain', 'checkout.vectorpagamentos.com');
    }

    /**
     * Get the TXT record name for verification.
     */
    public function getTxtRecordName(): string
    {
        return '_vector-verification.' . $this->domain;
    }

    /**
     * Get the TXT record value for verification.
     */
    public function getTxtRecordValue(): string
    {
        return 'vector-verify=' . $this->verification_token;
    }

    /**
     * Verify the domain DNS configuration.
     */
    public function verifyDns(): array
    {
        $result = [
            'cname_valid' => false,
            'txt_valid' => false,
            'errors' => [],
        ];

        // Check CNAME record
        $cnameRecords = @dns_get_record($this->domain, DNS_CNAME);
        if ($cnameRecords && count($cnameRecords) > 0) {
            foreach ($cnameRecords as $record) {
                if (isset($record['target']) && str_contains($record['target'], 'vectorpagamentos')) {
                    $result['cname_valid'] = true;
                    break;
                }
            }
        }

        if (!$result['cname_valid']) {
            // Also check A record pointing to our IP
            $aRecords = @dns_get_record($this->domain, DNS_A);
            if ($aRecords && count($aRecords) > 0) {
                $ourIps = explode(',', config('app.checkout_ips', ''));
                foreach ($aRecords as $record) {
                    if (isset($record['ip']) && in_array($record['ip'], $ourIps)) {
                        $result['cname_valid'] = true;
                        break;
                    }
                }
            }
        }

        if (!$result['cname_valid']) {
            $result['errors'][] = 'CNAME ou A record nÃ£o encontrado ou invÃ¡lido';
        }

        // Check TXT record for verification
        $txtRecords = @dns_get_record($this->getTxtRecordName(), DNS_TXT);
        if ($txtRecords && count($txtRecords) > 0) {
            foreach ($txtRecords as $record) {
                if (isset($record['txt']) && $record['txt'] === $this->getTxtRecordValue()) {
                    $result['txt_valid'] = true;
                    break;
                }
            }
        }

        if (!$result['txt_valid']) {
            $result['errors'][] = 'TXT de verificaÃ§Ã£o nÃ£o encontrado';
        }

        return $result;
    }

    /**
     * Mark domain as verified.
     */
    public function markAsVerified(): void
    {
        $this->update([
            'status' => 'verified',
            'verified_at' => now(),
            'last_check_at' => now(),
            'last_check_error' => null,
        ]);
    }

    /**
     * Mark domain verification as failed.
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'last_check_at' => now(),
            'last_check_error' => $error,
        ]);
    }

    /**
     * Set this domain as primary.
     */
    public function setAsPrimary(): void
    {
        // Remove primary from other domains of same account
        static::where('account_id', $this->account_id)
            ->where('id', '!=', $this->id)
            ->update(['is_primary' => false]);

        $this->update(['is_primary' => true]);
    }

    /**
     * Check if domain is used for a specific purpose.
     */
    public function isUsedFor(string $purpose): bool
    {
        $useFor = $this->use_for ?? ['checkout', 'members'];
        return in_array($purpose, $useFor);
    }

    /**
     * Scope: domains used for checkout.
     */
    public function scopeForCheckout($query)
    {
        return $query->whereJsonContains('use_for', 'checkout');
    }

    /**
     * Scope: domains used for member area.
     */
    public function scopeForMembers($query)
    {
        return $query->whereJsonContains('use_for', 'members');
    }

    /**
     * Check if this is a Vector Pagamentos subdomain.
     */
    public function isGateProSubdomain(): bool
    {
        return $this->type === self::TYPE_VECTOR;
    }

    /**
     * Check if this is a custom domain.
     */
    public function isCustomDomain(): bool
    {
        return $this->type === self::TYPE_CUSTOM;
    }

    /**
     * Get the Vector Pagamentos base domain for subdomains.
     */
    public static function getGateProBaseDomain(): string
    {
        return config('app.vectorpagamentos_subdomain_base', 'vectorpagamentos.com');
    }

    /**
     * Generate a full domain from a subdomain slug.
     */
    public static function buildSubdomainFull(string $slug): string
    {
        return $slug . '.' . static::getGateProBaseDomain();
    }

    /**
     * Scope: Vector Pagamentos subdomains only.
     */
    public function scopeGateProSubdomains($query)
    {
        return $query->where('type', self::TYPE_VECTOR);
    }

    /**
     * Scope: custom domains only.
     */
    public function scopeCustomDomains($query)
    {
        return $query->where('type', self::TYPE_CUSTOM);
    }
}
