<?php

declare(strict_types=1);

namespace Domain\Payment\Entities;

use DateTimeImmutable;
use Domain\Payment\Events\ChargeCreated;
use Domain\Payment\Events\ChargePaid;
use Domain\Payment\Events\ChargeExpired;
use Domain\Payment\ValueObjects\PaymentMethod;
use Domain\Payment\ValueObjects\TransactionStatus;
use Domain\Shared\Contracts\AggregateRoot;
use Domain\Shared\ValueObjects\Money;
use Domain\Shared\ValueObjects\Uuid;
use InvalidArgumentException;

class Charge extends AggregateRoot
{
    private Uuid $accountId;
    private ?Uuid $productId;
    private ?Uuid $checkoutId;
    private ?Uuid $affiliateId;
    private string $idempotencyKey;
    private ?string $externalId;
    private Money $amount;
    private PaymentMethod $paymentMethod;
    private TransactionStatus $status;
    private array $customer;
    private ?array $billing;
    private array $metadata;
    private ?DateTimeImmutable $expiresAt;
    private ?DateTimeImmutable $paidAt;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    private function __construct(
        Uuid $id,
        Uuid $accountId,
        string $idempotencyKey,
        Money $amount,
        PaymentMethod $paymentMethod,
        array $customer
    ) {
        $this->id = $id;
        $this->accountId = $accountId;
        $this->idempotencyKey = $idempotencyKey;
        $this->amount = $amount;
        $this->paymentMethod = $paymentMethod;
        $this->customer = $customer;
        $this->status = TransactionStatus::PENDING;
        $this->productId = null;
        $this->checkoutId = null;
        $this->affiliateId = null;
        $this->externalId = null;
        $this->billing = null;
        $this->metadata = [];
        $this->expiresAt = null;
        $this->paidAt = null;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public static function create(
        Uuid $accountId,
        string $idempotencyKey,
        Money $amount,
        PaymentMethod $paymentMethod,
        array $customer,
        ?Uuid $productId = null,
        ?Uuid $checkoutId = null,
        ?Uuid $affiliateId = null,
        ?string $externalId = null,
        ?array $billing = null,
        array $metadata = [],
        ?DateTimeImmutable $expiresAt = null
    ): self {
        self::validateCustomer($customer);

        if ($amount->isZero()) {
            throw new InvalidArgumentException('Charge amount must be greater than zero');
        }

        $charge = new self(
            Uuid::generate(),
            $accountId,
            $idempotencyKey,
            $amount,
            $paymentMethod,
            $customer
        );

        $charge->productId = $productId;
        $charge->checkoutId = $checkoutId;
        $charge->affiliateId = $affiliateId;
        $charge->externalId = $externalId;
        $charge->billing = $billing;
        $charge->metadata = $metadata;
        $charge->expiresAt = $expiresAt ?? $charge->calculateDefaultExpiration();

        $charge->recordEvent(new ChargeCreated(
            $charge->id->toString(),
            $charge->accountId->toString(),
            $charge->amount->getAmount(),
            $charge->amount->getCurrency(),
            $charge->paymentMethod->value
        ));

        return $charge;
    }

    public static function reconstitute(
        Uuid $id,
        Uuid $accountId,
        ?Uuid $productId,
        ?Uuid $checkoutId,
        ?Uuid $affiliateId,
        string $idempotencyKey,
        ?string $externalId,
        Money $amount,
        PaymentMethod $paymentMethod,
        TransactionStatus $status,
        array $customer,
        ?array $billing,
        array $metadata,
        ?DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $paidAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt
    ): self {
        $charge = new self($id, $accountId, $idempotencyKey, $amount, $paymentMethod, $customer);
        $charge->productId = $productId;
        $charge->checkoutId = $checkoutId;
        $charge->affiliateId = $affiliateId;
        $charge->externalId = $externalId;
        $charge->status = $status;
        $charge->billing = $billing;
        $charge->metadata = $metadata;
        $charge->expiresAt = $expiresAt;
        $charge->paidAt = $paidAt;
        $charge->createdAt = $createdAt;
        $charge->updatedAt = $updatedAt;

        return $charge;
    }

    private static function validateCustomer(array $customer): void
    {
        $required = ['name', 'email'];
        foreach ($required as $field) {
            if (empty($customer[$field])) {
                throw new InvalidArgumentException("Customer {$field} is required");
            }
        }

        if (!filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid customer email');
        }
    }

    private function calculateDefaultExpiration(): DateTimeImmutable
    {
        $hours = $this->paymentMethod->getDefaultExpirationHours();
        return (new DateTimeImmutable())->modify("+{$hours} hours");
    }

    public function markAsPaid(?DateTimeImmutable $paidAt = null): void
    {
        if (!$this->status->canTransitionTo(TransactionStatus::APPROVED)) {
            throw new InvalidArgumentException(
                "Cannot mark as paid: invalid status transition from {$this->status->value}"
            );
        }

        $this->status = TransactionStatus::APPROVED;
        $this->paidAt = $paidAt ?? new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();

        $this->recordEvent(new ChargePaid(
            $this->id->toString(),
            $this->amount->getAmount(),
            $this->paidAt
        ));
    }

    public function markAsProcessing(): void
    {
        if (!$this->status->canTransitionTo(TransactionStatus::PROCESSING)) {
            throw new InvalidArgumentException(
                "Cannot mark as processing: invalid status transition from {$this->status->value}"
            );
        }

        $this->status = TransactionStatus::PROCESSING;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function markAsDeclined(): void
    {
        if (!$this->status->canTransitionTo(TransactionStatus::DECLINED)) {
            throw new InvalidArgumentException(
                "Cannot mark as declined: invalid status transition from {$this->status->value}"
            );
        }

        $this->status = TransactionStatus::DECLINED;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function markAsCancelled(): void
    {
        if (!$this->status->canTransitionTo(TransactionStatus::CANCELLED)) {
            throw new InvalidArgumentException(
                "Cannot cancel: invalid status transition from {$this->status->value}"
            );
        }

        $this->status = TransactionStatus::CANCELLED;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function markAsExpired(): void
    {
        if (!$this->status->canTransitionTo(TransactionStatus::EXPIRED)) {
            throw new InvalidArgumentException(
                "Cannot expire: invalid status transition from {$this->status->value}"
            );
        }

        $this->status = TransactionStatus::EXPIRED;
        $this->updatedAt = new DateTimeImmutable();

        $this->recordEvent(new ChargeExpired($this->id->toString()));
    }

    public function markAsRefunded(): void
    {
        if (!$this->status->canTransitionTo(TransactionStatus::REFUNDED)) {
            throw new InvalidArgumentException(
                "Cannot refund: invalid status transition from {$this->status->value}"
            );
        }

        $this->status = TransactionStatus::REFUNDED;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function isExpired(): bool
    {
        if ($this->status->isTerminal()) {
            return false;
        }

        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new DateTimeImmutable();
    }

    public function isPaid(): bool
    {
        return $this->status === TransactionStatus::APPROVED;
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isRefundable(): bool
    {
        return $this->status->isRefundable();
    }

    public function setAffiliate(Uuid $affiliateId): void
    {
        $this->affiliateId = $affiliateId;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function addMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
        $this->updatedAt = new DateTimeImmutable();
    }

    // Getters
    public function getAccountId(): Uuid
    {
        return $this->accountId;
    }

    public function getProductId(): ?Uuid
    {
        return $this->productId;
    }

    public function getCheckoutId(): ?Uuid
    {
        return $this->checkoutId;
    }

    public function getAffiliateId(): ?Uuid
    {
        return $this->affiliateId;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function getAmount(): Money
    {
        return $this->amount;
    }

    public function getPaymentMethod(): PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getStatus(): TransactionStatus
    {
        return $this->status;
    }

    public function getCustomer(): array
    {
        return $this->customer;
    }

    public function getBilling(): ?array
    {
        return $this->billing;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getPaidAt(): ?DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'account_id' => $this->accountId->toString(),
            'product_id' => $this->productId?->toString(),
            'checkout_id' => $this->checkoutId?->toString(),
            'affiliate_id' => $this->affiliateId?->toString(),
            'idempotency_key' => $this->idempotencyKey,
            'external_id' => $this->externalId,
            'amount' => $this->amount->toArray(),
            'payment_method' => $this->paymentMethod->value,
            'status' => $this->status->value,
            'customer' => $this->customer,
            'billing' => $this->billing,
            'metadata' => $this->metadata,
            'expires_at' => $this->expiresAt?->format('Y-m-d H:i:s'),
            'paid_at' => $this->paidAt?->format('Y-m-d H:i:s'),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
