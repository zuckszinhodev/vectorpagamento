<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Configuration
    |--------------------------------------------------------------------------
    */

    'max_installments' => (int) env('PAYMENT_MAX_INSTALLMENTS', 12),

    'interest_free_installments' => (int) env('PAYMENT_INTEREST_FREE_INSTALLMENTS', 6),

    'installment_interest_rate' => (float) env('PAYMENT_INSTALLMENT_INTEREST_RATE', 0.0199),

    'min_installment_amount' => (int) env('PAYMENT_MIN_INSTALLMENT_AMOUNT', 500), // R$ 5,00 em centavos

];
