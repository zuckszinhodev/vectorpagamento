<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChargeOffer extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'charge_id',
        'account_id',
        'offer_type',
        'offer_id',
        'product_id',
        'amount',
        'status',
        'upsell_charge_id',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function upsellCharge(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'upsell_charge_id');
    }

    /**
     * Resolve the offer (OrderBump or Upsell) polymorphically.
     */
    public function offer(): BelongsTo
    {
        if ($this->offer_type === 'order_bump') {
            return $this->belongsTo(OrderBump::class, 'offer_id');
        }
        return $this->belongsTo(Upsell::class, 'offer_id');
    }
}
