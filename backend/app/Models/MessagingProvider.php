<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessagingProvider extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'account_id',
        'channel',
        'provider_name',
        'api_url',
        'api_key',
        'instance_name',
        'instance_token',
        'sender_number',
        'is_active',
        'settings',
        'messages_sent',
        'messages_failed',
        'last_sent_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'messages_sent' => 'integer',
        'messages_failed' => 'integer',
        'last_sent_at' => 'datetime',
    ];

    protected $hidden = [
        'api_key',
        'instance_token',
    ];

    // ---- constants ----

    const CHANNEL_WHATSAPP = 'whatsapp';
    const CHANNEL_SMS = 'sms';

    const PROVIDER_EVOLUTION_API = 'evolution_api';
    const PROVIDER_ZAPI = 'z-api';
    const PROVIDER_TWILIO = 'twilio';
    const PROVIDER_VONAGE = 'vonage';
    const PROVIDER_GATEPRO = 'wazerpay';
    const PROVIDER_CUSTOM = 'custom';

    // ---- relationships ----

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class, 'provider_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MessageLog::class, 'provider_id');
    }

    // ---- scopes ----

    public function scopeActive($query)
    {
        return $query->whereRaw('"is_active" = true');
    }

    public function scopeForAccount($query, string $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeWhatsapp($query)
    {
        return $query->where('channel', self::CHANNEL_WHATSAPP);
    }

    public function scopeSms($query)
    {
        return $query->where('channel', self::CHANNEL_SMS);
    }

    // ---- helpers ----

    public function incrementSent(): void
    {
        $this->update([
            'messages_sent' => $this->messages_sent + 1,
            'last_sent_at' => now(),
        ]);
    }

    public function incrementFailed(): void
    {
        $this->update([
            'messages_failed' => $this->messages_failed + 1,
            'last_sent_at' => now(),
        ]);
    }

    public function getMaskedApiKeyAttribute(): string
    {
        $key = $this->api_key;
        if (!$key || strlen($key) <= 8) {
            return $key ? str_repeat('*', strlen($key)) : '';
        }
        return substr($key, 0, 4) . str_repeat('*', strlen($key) - 8) . substr($key, -4);
    }

    public function getMaskedTokenAttribute(): string
    {
        $token = $this->instance_token;
        if (!$token || strlen($token) <= 8) {
            return $token ? str_repeat('*', strlen($token)) : '';
        }
        return substr($token, 0, 4) . str_repeat('*', strlen($token) - 8) . substr($token, -4);
    }
}
