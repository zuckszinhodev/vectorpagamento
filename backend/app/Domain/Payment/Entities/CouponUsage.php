<?php

namespace App\Domain\Payment\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CouponUsage extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'coupon_usage';

    protected $fillable = [
        'coupon_id',
        'charge_id',
        'customer_email',
        'discount_amount',
        'used_at',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'used_at' => 'datetime',
    ];

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function charge()
    {
        return $this->belongsTo(Charge::class);
    }
}
