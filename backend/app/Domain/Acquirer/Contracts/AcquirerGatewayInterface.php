<?php

declare(strict_types=1);

namespace Domain\Acquirer\Contracts;

use Domain\Acquirer\ValueObjects\AcquirerResponse;
use Domain\Payment\Entities\Charge;
use Domain\Payment\ValueObjects\CardData;
use Domain\Shared\ValueObjects\Money;

interface AcquirerGatewayInterface
{
    /**
     * Get the acquirer identifier
     */
    public function getIdentifier(): string;

    /**
     * Get the acquirer display name
     */
    public function getName(): string;

    /**
     * Check if the acquirer is available
     */
    public function isAvailable(): bool;

    /**
     * Get supported payment methods
     * @return array<string>
     */
    public function getSupportedMethods(): array;

    /**
     * Process a credit card payment
     */
    public function processCard(
        Charge $charge,
        CardData $cardData,
        int $installments = 1
    ): AcquirerResponse;

    /**
     * Generate a PIX payment
     */
    public function generatePix(Charge $charge): AcquirerResponse;

    /**
     * Generate a Boleto payment
     */
    public function generateBoleto(Charge $charge): AcquirerResponse;

    /**
     * Process a refund
     */
    public function refund(
        string $transactionId,
        Money $amount
    ): AcquirerResponse;

    /**
     * Capture an authorized transaction
     */
    public function capture(
        string $transactionId,
        Money $amount
    ): AcquirerResponse;

    /**
     * Cancel/void a transaction
     */
    public function cancel(string $transactionId): AcquirerResponse;

    /**
     * Check transaction status
     */
    public function getStatus(string $transactionId): AcquirerResponse;

    /**
     * Verify webhook signature
     */
    public function verifyWebhookSignature(
        string $payload,
        string $signature
    ): bool;

    /**
     * Calculate transaction fee
     */
    public function calculateFee(
        Money $amount,
        string $paymentMethod,
        int $installments = 1
    ): Money;
}
