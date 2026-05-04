<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TiktokPixelIntegration extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'account_id',
        'pixel_id',
        'access_token',
        'test_event_code',
        'is_active',
        'track_complete_payment',
        'track_initiate_checkout',
        'track_page_view',
        'last_sent_at',
        'last_status',
        'last_response',
        'success_count',
        'failure_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'track_complete_payment' => 'boolean',
        'track_initiate_checkout' => 'boolean',
        'track_page_view' => 'boolean',
        'last_sent_at' => 'datetime',
        'success_count' => 'integer',
        'failure_count' => 'integer',
    ];

    protected $hidden = [
        'access_token',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeActive($query)
    {
        return $query->whereRaw('"is_active" = true');
    }

    public function scopeForAccount($query, string $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    public function markSuccess(string $status, ?string $response = null): void
    {
        $this->update([
            'last_sent_at' => now(),
            'last_status' => $status,
            'last_response' => $response,
            'success_count' => $this->success_count + 1,
        ]);
    }

    public function markFailure(string $status, ?string $response = null): void
    {
        $this->update([
            'last_sent_at' => now(),
            'last_status' => $status,
            'last_response' => $response,
            'failure_count' => $this->failure_count + 1,
        ]);
    }

    public function getMaskedTokenAttribute(): string
    {
        $token = $this->access_token;
        if (!$token || strlen($token) < 12) {
            return '••••••••';
        }

        return substr($token, 0, 6) . '••••••' . substr($token, -4);
    }
}
