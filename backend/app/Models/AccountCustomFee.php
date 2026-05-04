<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountCustomFee extends Model
{
    use HasUuids;

    protected $table = 'account_custom_fees';

    protected $fillable = [
        'id',
        'account_id',
        'set_by_user_id',
        'pix_fee_percent',
        'pix_fee_fixed',
        'credit_card_fee_percent',
        'credit_card_fee_fixed',
        'boleto_fee_percent',
        'boleto_fee_fixed',
        'withdrawal_pix_fee',
        'withdrawal_ted_fee',
        'instant_withdrawal_fee_percent',
        'antifraude_fee_percent',
        'antifraude_fee_fixed',
        'installment_fee_percent',
        'min_withdrawal',
        'holdback_percent',
        'holdback_days',
        'notes',
    ];

    protected $casts = [
        'pix_fee_percent' => 'decimal:2',
        'pix_fee_fixed' => 'decimal:2',
        'credit_card_fee_percent' => 'decimal:2',
        'credit_card_fee_fixed' => 'decimal:2',
        'boleto_fee_percent' => 'decimal:2',
        'boleto_fee_fixed' => 'decimal:2',
        'withdrawal_pix_fee' => 'decimal:2',
        'withdrawal_ted_fee' => 'decimal:2',
        'instant_withdrawal_fee_percent' => 'decimal:2',
        'antifraude_fee_percent' => 'decimal:2',
        'antifraude_fee_fixed' => 'decimal:2',
        'installment_fee_percent' => 'decimal:2',
        'min_withdrawal' => 'decimal:2',
        'holdback_percent' => 'decimal:2',
        'holdback_days' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Calculate custom gateway fee for a payment method.
     * Returns fee in centavos, or null if no custom fee is set.
     */
    public function calculateGatewayFee(int $amountCents, string $paymentMethod): ?int
    {
        $feePercent = null;
        $feeFixedReais = null;

        switch ($paymentMethod) {
            case 'pix':
                $feePercent = $this->pix_fee_percent;
                $feeFixedReais = $this->pix_fee_fixed;
                break;
            case 'credit_card':
                $feePercent = $this->credit_card_fee_percent;
                $feeFixedReais = $this->credit_card_fee_fixed;
                break;
            case 'boleto':
                $feePercent = $this->boleto_fee_percent;
                $feeFixedReais = $this->boleto_fee_fixed;
                break;
        }

        if (is_null($feePercent) && is_null($feeFixedReais)) {
            return null;
        }

        $percentagePart = $feePercent ? (int) round($amountCents * ((float) $feePercent / 100)) : 0;
        $fixedPart = $feeFixedReais ? (int) round((float) $feeFixedReais * 100) : 0;

        return $percentagePart + $fixedPart;
    }

    /**
     * Get withdrawal fee in centavos for a given method.
     * Returns null if no custom fee is set.
     */
    public function getWithdrawalFeeCents(string $method): ?int
    {
        $reais = ($method === 'bank_transfer') ? $this->withdrawal_ted_fee : $this->withdrawal_pix_fee;

        return is_null($reais) ? null : (int) round((float) $reais * 100);
    }

    /**
     * Get minimum withdrawal in centavos.
     * Returns null if no custom value is set.
     */
    public function getMinWithdrawalCents(): ?int
    {
        return is_null($this->min_withdrawal) ? null : (int) round((float) $this->min_withdrawal * 100);
    }
}
