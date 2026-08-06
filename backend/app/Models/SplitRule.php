<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SplitRule extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'account_id',
        'name',
        'product_id',
        'checkout_id',
        'is_default',
        'is_active',
        'priority',
        'recipients',
        'charge_fee_to_producer',
        'minimum_amount',
        'auto_process',
        'transfer_delay_hours',
    ];

    protected $casts = [
        'recipients' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'charge_fee_to_producer' => 'boolean',
        'auto_process' => 'boolean',
        'minimum_amount' => 'integer',
        'priority' => 'integer',
        'transfer_delay_hours' => 'integer',
    ];

    // ── Relationships ──────────────────────────────────

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    // ── Scopes ─────────────────────────────────────────

    public function scopeActive($query)
    {
        // `whereRaw('"is_active" = true')` quebra no MySQL (aspas duplas viram
        // string fora do modo ANSI). O builder resolve o dialeto sozinho.
        return $query->where('is_active', true);
    }

    public function scopeForAccount($query, string $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeForProduct($query, ?string $productId)
    {
        return $query->where(function ($q) use ($productId) {
            $q->where('product_id', $productId)
              ->orWhereNull('product_id');
        });
    }

    // ── Helpers ────────────────────────────────────────

    /**
     * Check if all recipient percentages sum to 100%.
     */
    public function isValid(): bool
    {
        $recipients = $this->recipients ?? [];
        if (empty($recipients)) {
            return false;
        }

        $percentageRecipients = array_filter($recipients, fn($r) => ($r['type'] ?? 'percentage') === 'percentage');
        $totalPercentage = array_sum(array_column($percentageRecipients, 'value'));

        // Allow up to 100% for percentage-based splits
        return $totalPercentage > 0 && $totalPercentage <= 100;
    }

    /**
     * Get the total percentage sum of all recipients.
     */
    public function getTotalPercentage(): float
    {
        $recipients = $this->recipients ?? [];
        $percentageRecipients = array_filter($recipients, fn($r) => ($r['type'] ?? 'percentage') === 'percentage');
        return (float) array_sum(array_column($percentageRecipients, 'value'));
    }

    /**
     * Check if rule applies to a given charge amount.
     */
    public function appliesToAmount(int $amount): bool
    {
        return $amount >= $this->minimum_amount;
    }
}
