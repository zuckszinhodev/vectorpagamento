<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'account_id',
        'type',
        'title',
        'message',
        'data',
        'read',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function markAsRead(): void
    {
        if (!$this->read) {
            $this->update([
                'read' => true,
                'read_at' => now(),
            ]);
        }
    }

    public function scopeVisibleInInbox($query)
    {
        return $query->where(function ($query) {
            $query->whereNull('data->event')
                ->orWhere('data->event', '!=', 'withdrawal.failed');
        });
    }

    public function scopeUnread($query)
    {
        return $query->where('read', false);
    }

    public function scopeForUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Create a sale notification
     */
    public static function createSaleNotification(User $user, string $productName, float $amount, ?string $chargeId = null): self
    {
        return self::create([
            'user_id' => $user->id,
            'account_id' => $user->account_id,
            'type' => 'sale',
            'title' => 'Nova venda!',
            'message' => "Produto \"{$productName}\" - R$ " . number_format($amount, 2, ',', '.'),
            'data' => [
                'charge_id' => $chargeId,
                'product_name' => $productName,
                'amount' => $amount,
            ],
        ]);
    }

    /**
     * Create a system notification
     */
    public static function createSystemNotification(User $user, string $title, string $message, ?array $data = null): self
    {
        return self::create([
            'user_id' => $user->id,
            'account_id' => $user->account_id,
            'type' => 'system',
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * Create a warning notification
     */
    public static function createWarningNotification(User $user, string $title, string $message, ?array $data = null): self
    {
        return self::create([
            'user_id' => $user->id,
            'account_id' => $user->account_id,
            'type' => 'warning',
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }
}
