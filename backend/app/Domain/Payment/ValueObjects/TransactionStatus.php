<?php

declare(strict_types=1);

namespace Domain\Payment\ValueObjects;

use InvalidArgumentException;

enum TransactionStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case AUTHORIZED = 'authorized';
    case APPROVED = 'approved';
    case DECLINED = 'declined';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';
    case PARTIALLY_REFUNDED = 'partially_refunded';
    case CHARGEBACK = 'chargeback';
    case EXPIRED = 'expired';
    case FAILED = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::APPROVED,
            self::DECLINED,
            self::CANCELLED,
            self::REFUNDED,
            self::CHARGEBACK,
            self::EXPIRED,
            self::FAILED,
        ], true);
    }

    public function canTransitionTo(self $newStatus): bool
    {
        $allowedTransitions = [
            self::PENDING->value => [
                self::PROCESSING,
                self::AUTHORIZED,
                self::APPROVED,
                self::DECLINED,
                self::CANCELLED,
                self::EXPIRED,
                self::FAILED,
            ],
            self::PROCESSING->value => [
                self::AUTHORIZED,
                self::APPROVED,
                self::DECLINED,
                self::FAILED,
            ],
            self::AUTHORIZED->value => [
                self::APPROVED,
                self::DECLINED,
                self::CANCELLED,
            ],
            self::APPROVED->value => [
                self::REFUNDED,
                self::PARTIALLY_REFUNDED,
                self::CHARGEBACK,
            ],
            self::PARTIALLY_REFUNDED->value => [
                self::REFUNDED,
                self::CHARGEBACK,
            ],
        ];

        $allowed = $allowedTransitions[$this->value] ?? [];

        return in_array($newStatus, $allowed, true);
    }

    public function isSuccessful(): bool
    {
        return $this === self::APPROVED;
    }

    public function isPending(): bool
    {
        return in_array($this, [
            self::PENDING,
            self::PROCESSING,
            self::AUTHORIZED,
        ], true);
    }

    public function isRefundable(): bool
    {
        return in_array($this, [
            self::APPROVED,
            self::PARTIALLY_REFUNDED,
        ], true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::PROCESSING => 'Processando',
            self::AUTHORIZED => 'Autorizado',
            self::APPROVED => 'Aprovado',
            self::DECLINED => 'Recusado',
            self::CANCELLED => 'Cancelado',
            self::REFUNDED => 'Estornado',
            self::PARTIALLY_REFUNDED => 'Parcialmente Estornado',
            self::CHARGEBACK => 'Chargeback',
            self::EXPIRED => 'Expirado',
            self::FAILED => 'Falhou',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PENDING, self::PROCESSING, self::AUTHORIZED => 'yellow',
            self::APPROVED => 'green',
            self::DECLINED, self::CANCELLED, self::FAILED, self::EXPIRED => 'red',
            self::REFUNDED, self::PARTIALLY_REFUNDED => 'blue',
            self::CHARGEBACK => 'purple',
        };
    }
}
