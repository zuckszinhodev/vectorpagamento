<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SavedCard extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'account_id',
        'customer_email',
        'customer_document',
        'card_brand',
        'card_last_four',
        'card_holder_name',
        'exp_month',
        'exp_year',
        'card_fingerprint',
        'acquirer',
        'acquirer_card_token',
        'is_default',
    ];

    protected $casts = [
        'exp_month' => 'integer',
        'exp_year' => 'integer',
        'is_default' => 'boolean',
    ];

    protected $hidden = [
        'acquirer_card_token',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid()->toString();
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Save card from a successful charge (idempotent — won't duplicate).
     */
    public static function saveFromCharge(Charge $charge, array $cardData, string $acquirer): ?self
    {
        $customer = $charge->customer ?? [];
        $email = $customer['email'] ?? null;

        if (!$email) {
            return null;
        }

        $lastFour = $cardData['last_four']
            ?? (isset($cardData['number']) ? substr($cardData['number'], -4) : null);

        if (!$lastFour) {
            return null;
        }

        $brand = $cardData['brand'] ?? $cardData['card_brand'] ?? self::detectBrand($cardData['number'] ?? '');

        return static::updateOrCreate(
            [
                'account_id' => $charge->account_id,
                'customer_email' => strtolower($email),
                'card_last_four' => $lastFour,
                'card_brand' => $brand,
            ],
            [
                'customer_document' => $customer['document'] ?? null,
                'card_holder_name' => $cardData['holder_name'] ?? $cardData['holderName'] ?? null,
                'exp_month' => (int) ($cardData['exp_month'] ?? $cardData['expMonth'] ?? 0),
                'exp_year' => (int) ($cardData['exp_year'] ?? $cardData['expYear'] ?? 0),
                'card_fingerprint' => $cardData['fingerprint'] ?? null,
                'acquirer' => $acquirer,
                'acquirer_card_token' => $cardData['token_id'] ?? $cardData['credit_card_token'] ?? null,
            ]
        );
    }

    private static function detectBrand(string $number): ?string
    {
        $number = preg_replace('/\D/', '', $number);
        if (!$number) return null;

        return match (true) {
            str_starts_with($number, '4') => 'visa',
            str_starts_with($number, '5') || str_starts_with($number, '2') => 'mastercard',
            str_starts_with($number, '3') => 'amex',
            str_starts_with($number, '6') => 'elo',
            default => null,
        };
    }
}
