<?php

namespace App\Domain\Acquirer\Contracts;

use App\Models\Charge;

interface AcquirerInterface
{
    /**
     * Get acquirer name
     */
    public function getName(): string;

    /**
     * Process card payment
     */
    public function processPayment(Charge $charge, array $cardData): array;

    /**
     * Process refund
     */
    public function refund(Charge $charge, float $amount): array;

    /**
     * Generate PIX QR Code
     */
    public function generatePixQrCode(Charge $charge): array;

    /**
     * Generate Boleto
     */
    public function generateBoleto(Charge $charge): array;
}
