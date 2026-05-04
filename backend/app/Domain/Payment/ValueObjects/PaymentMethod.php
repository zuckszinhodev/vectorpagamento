<?php

declare(strict_types=1);

namespace Domain\Payment\ValueObjects;

enum PaymentMethod: string
{
    case CREDIT_CARD = 'credit_card';
    case DEBIT_CARD = 'debit_card';
    case PIX = 'pix';
    case BOLETO = 'boleto';

    public function getLabel(): string
    {
        return match ($this) {
            self::CREDIT_CARD => 'Cartão de Crédito',
            self::DEBIT_CARD => 'Cartão de Débito',
            self::PIX => 'PIX',
            self::BOLETO => 'Boleto Bancário',
        };
    }

    public function supportsInstallments(): bool
    {
        return $this === self::CREDIT_CARD;
    }

    public function isInstant(): bool
    {
        return in_array($this, [
            self::CREDIT_CARD,
            self::DEBIT_CARD,
            self::PIX,
        ], true);
    }

    public function getDefaultExpirationHours(): int
    {
        return match ($this) {
            self::PIX => 1,
            self::BOLETO => 72,
            default => 24,
        };
    }

    public function requiresCardData(): bool
    {
        return in_array($this, [
            self::CREDIT_CARD,
            self::DEBIT_CARD,
        ], true);
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::CREDIT_CARD => 'credit-card',
            self::DEBIT_CARD => 'credit-card',
            self::PIX => 'qr-code',
            self::BOLETO => 'document-text',
        };
    }
}
