<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageLog extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'account_id',
        'provider_id',
        'template_id',
        'channel',
        'recipient_phone',
        'recipient_name',
        'message_body',
        'trigger_event',
        'charge_id',
        'status',
        'provider_response',
        'external_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ---- constants ----

    const STATUS_PENDING = 'pending';
    const STATUS_SENT = 'sent';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_FAILED = 'failed';
    const STATUS_READ = 'read';

    // ---- relationships ----

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(MessagingProvider::class, 'provider_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'template_id');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    // ---- scopes ----

    public function scopeForAccount($query, string $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeForChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    // ---- helpers ----

    public function markAsSent(string $externalId = null, string $response = null): void
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'external_id' => $externalId,
            'provider_response' => $response,
        ]);
    }

    public function markAsFailed(string $response = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'provider_response' => $response,
        ]);
    }
}
