<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Domain\Payment\Entities\Refund;

class Charge extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'account_id',
        'product_id',
        'checkout_id',
        'affiliate_id',
        'idempotency_key',
        'external_id',
        'amount',
        'currency',
        'payment_method',
        'status',
        'customer',
        'billing',
        'metadata',
        'expires_at',
        'paid_at',
        // PIX fields
        'pix_qr_code',
        'pix_copy_paste',
        'pix_qr_code_url',
        'pix_end2end_id',
        // Boleto fields
        'boleto_url',
        'boleto_barcode',
        'boleto_digitable_line',
        // General payment fields
        'payment_url',
        'acquirer',
        'acquirer_transaction_id',
        // Refund/Status fields
        'refunded_amount',
        'refunded_at',
        'cancelled_at',
        'cancellation_reason',
        'failure_reason',
        // Additional fields
        'installments',
        'description',
        'fee_amount',
        'net_amount',
    ];

    /**
     * NOTE: status, fee_amount, net_amount, paid_at are set only by internal service logic.
     * Controller-level validation ensures user input cannot override these fields.
     */

    protected $casts = [
        'amount' => 'integer',
        'refunded_amount' => 'integer',
        'fee_amount' => 'integer',
        'net_amount' => 'integer',
        'installments' => 'integer',
        'customer' => 'array',
        'billing' => 'array',
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected $appends = ['acquirer_name'];

    /**
     * Status constants
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_AUTHORIZED = 'authorized';
    const STATUS_APPROVED = 'approved'; // Paid
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REFUNDED = 'refunded';
    const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';
    const STATUS_CHARGEBACK = 'chargeback';
    const STATUS_EXPIRED = 'expired';
    const STATUS_FAILED = 'failed';
    const STATUS_DECLINED = 'declined';

    /**
     * Check if charge is paid
     */
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if charge is pending
     */
    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    /**
     * Mark as paid
     */
    public function markAsPaid(?string $transactionId = null): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'paid_at' => now(),
            'acquirer_transaction_id' => $transactionId ?? $this->acquirer_transaction_id,
        ]);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(?string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'failure_reason' => $reason,
        ]);
    }

    /**
     * Mark as cancelled
     */
    public function markAsCancelled(): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Mark as expired
     */
    public function markAsExpired(): void
    {
        $this->update([
            'status' => self::STATUS_EXPIRED,
        ]);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function getAcquirerNameAttribute(): ?string
    {
        if (!$this->acquirer) {
            return null;
        }

        $config = AcquirerConfig::findByKey($this->acquirer);

        if (!$config) {
            return $this->acquirer;
        }

        $nominalName = trim((string) ($config->nominal_name ?? ''));

        if ($nominalName !== '') {
            return $nominalName;
        }

        $displayName = trim((string) ($config->display_name ?? ''));

        return $displayName !== '' ? $displayName : $this->acquirer;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function chargeOffers(): HasMany
    {
        return $this->hasMany(ChargeOffer::class);
    }
}
