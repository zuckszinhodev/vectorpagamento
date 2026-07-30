<?php

declare(strict_types=1);

namespace App\Domain\Routing\Enums;

/**
 * Payment methods the router knows how to dispatch.
 *
 * {@see self::normalize()} is the single place that resolves the many aliases
 * floating around the codebase ('card', 'pixout', 'withdraw', ...) so that
 * routing, metrics and policies always agree on the same key.
 */
enum PaymentMethod: string
{
    case PIX = 'pix';
    case PIX_OUT = 'pix_out';
    case CREDIT_CARD = 'credit_card';
    case DEBIT_CARD = 'debit_card';
    case BOLETO = 'boleto';

    public static function normalize(?string $value): self
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'pix', 'pix_in', 'pix-in' => self::PIX,
            'pix_out', 'pixout', 'pix-out', 'withdraw', 'withdrawal', 'transfer', 'cashout' => self::PIX_OUT,
            'card', 'cartao', 'cartão', 'creditcard', 'credit-card', 'credit_card' => self::CREDIT_CARD,
            'debit', 'debit_card', 'debitcard', 'debit-card' => self::DEBIT_CARD,
            'boleto', 'bank_slip', 'billet' => self::BOLETO,
            default => self::PIX,
        };
    }

    /**
     * Aliases an acquirer's supported_methods column may legitimately use.
     *
     * @return array<int, string>
     */
    public function aliases(): array
    {
        return match ($this) {
            self::PIX => ['pix', 'pix_in'],
            self::PIX_OUT => ['pix_out', 'pixout', 'withdraw', 'withdrawal', 'transfer', 'cashout'],
            self::CREDIT_CARD => ['credit_card', 'card', 'creditcard'],
            self::DEBIT_CARD => ['debit_card', 'debit', 'debitcard'],
            self::BOLETO => ['boleto', 'bank_slip'],
        };
    }

    public function isCashOut(): bool
    {
        return $this === self::PIX_OUT;
    }

    public function label(): string
    {
        return match ($this) {
            self::PIX => 'PIX',
            self::PIX_OUT => 'PIX Out',
            self::CREDIT_CARD => 'Cartão de Crédito',
            self::DEBIT_CARD => 'Cartão de Débito',
            self::BOLETO => 'Boleto',
        };
    }
}
