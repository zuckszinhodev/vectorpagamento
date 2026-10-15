<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UtmifyIntegration extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'account_id',
        'api_token',
        'is_active',
        'last_sent_at',
        'last_status',
        'last_response',
        'success_count',
        'failure_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_sent_at' => 'datetime',
        'success_count' => 'integer',
        'failure_count' => 'integer',
    ];

    protected $hidden = [
        'api_token',
    ];

    // ---- relationships ----

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    // ---- scopes ----

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForAccount($query, string $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    // ---- helpers ----

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

    /**
     * Get the masked API token for display
     */
    public function getMaskedTokenAttribute(): string
    {
        $token = $this->api_token;
        if (strlen($token) <= 8) {
            return str_repeat('*', strlen($token));
        }
        return substr($token, 0, 4) . str_repeat('*', strlen($token) - 8) . substr($token, -4);
    }
}
