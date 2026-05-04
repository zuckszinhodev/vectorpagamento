<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApiKey extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'account_id',
        'name',
        'type',
        'key',
        'key_prefix',
        'key_hash',
        'secret_hash',
        'permissions',
        'environment',
        'ip_whitelist',
        'rate_limit',
        'last_used_at',
        'expires_at',
        'is_active',
    ];

    /**
     * Check if this is a secret key (sk_live_ or sk_test_)
     */
    public function isSecretKey(): bool
    {
        return $this->type === 'secret';
    }

    /**
     * Check if this is a public key (pk_live_ or pk_test_)
     */
    public function isPublicKey(): bool
    {
        return $this->type === 'public';
    }

    /**
     * Check if the key has a specific permission.
     * Secret keys with ['*'] have all permissions.
     * Public keys are limited to read-only operations.
     */
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions ?? [];

        // Wildcard = all permissions
        if (in_array('*', $permissions)) {
            return true;
        }

        return in_array($permission, $permissions);
    }

    protected $casts = [
        'permissions' => 'array',
        'ip_whitelist' => 'array',
        'rate_limit' => 'integer',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'secret_hash',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
