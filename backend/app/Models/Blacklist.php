<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Blacklist extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'account_id',
        'type',
        'value',
        'reason',
        'is_global',
        'expires_at',
    ];

    protected $casts = [
        'is_global' => 'boolean',
        'expires_at' => 'datetime',
    ];

    const TYPE_EMAIL = 'email';
    const TYPE_DOCUMENT = 'document';
    const TYPE_IP = 'ip';
    const TYPE_BIN = 'bin';
    const TYPE_FINGERPRINT = 'fingerprint';

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    public function scopeGlobal($query)
    {
        return $query->where('is_global', true);
    }

    public function scopeForAccount($query, string $accountId)
    {
        return $query->where(function ($q) use ($accountId) {
            $q->where('account_id', $accountId)
              ->orWhere('is_global', true);
        });
    }
}
