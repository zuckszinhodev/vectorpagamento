<?php

declare(strict_types=1);

namespace Domain\Payment\Events;

use Domain\Shared\Contracts\DomainEvent;

final class ChargeCreated extends DomainEvent
{
    private string $accountId;
    private int $amount;
    private string $currency;
    private string $paymentMethod;

    public function __construct(
        string $chargeId,
        string $accountId,
        int $amount,
        string $currency,
        string $paymentMethod
    ) {
        parent::__construct($chargeId);
        $this->accountId = $accountId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->paymentMethod = $paymentMethod;
    }

    public function getEventName(): string
    {
        return 'charge.created';
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getPaymentMethod(): string
    {
        return $this->paymentMethod;
    }

    public function toArray(): array
    {
        return [
            'event' => $this->getEventName(),
            'charge_id' => $this->getAggregateId(),
            'account_id' => $this->accountId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'payment_method' => $this->paymentMethod,
            'occurred_at' => $this->getOccurredAt()->format('Y-m-d H:i:s'),
        ];
    }
}
