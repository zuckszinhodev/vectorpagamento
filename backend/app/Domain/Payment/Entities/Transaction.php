<?php

declare(strict_types=1);

namespace Domain\Payment\Entities;

use DateTimeImmutable;
use Domain\Payment\Events\TransactionApproved;
use Domain\Payment\Events\TransactionDeclined;
use Domain\Payment\ValueObjects\PaymentMethod;
use Domain\Payment\ValueObjects\TransactionStatus;
use Domain\Shared\Contracts\AggregateRoot;
use Domain\Shared\ValueObjects\Money;
use Domain\Shared\ValueObjects\Uuid;
use InvalidArgumentException;

class Transaction extends AggregateRoot
{
    private Uuid $chargeId;
    private Uuid $acquirerId;
    private string $type;
    private TransactionStatus $status;
    private Money $amount;
    private Money $fee;
    private Money $netAmount;
    private ?string $acquirerTid;
    private ?string $acquirerNsu;
    private ?string $authorizationCode;
    private ?string $cardBrand;
    private ?string $cardLastDigits;
    private int $installments;
    private ?array $gatewayResponse;
    private ?string $errorCode;
    private ?string $errorMessage;
    private ?DateTimeImmutable $processedAt;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    private const TYPE_AUTHORIZATION = 'authorization';
    private const TYPE_CAPTURE = 'capture';
    private const TYPE_SALE = 'sale';
    private const TYPE_REFUND = 'refund';

    private function __construct(
        Uuid $id,
        Uuid $chargeId,
        Uuid $acquirerId,
        string $type,
        Money $amount,
        int $installments = 1
    ) {
        $this->id = $id;
        $this->chargeId = $chargeId;
        $this->acquirerId = $acquirerId;
        $this->type = $type;
        $this->amount = $amount;
        $this->installments = $installments;
        $this->status = TransactionStatus::PENDING;
        $this->fee = Money::zero($amount->getCurrency());
        $this->netAmount = $amount;
        $this->acquirerTid = null;
        $this->acquirerNsu = null;
        $this->authorizationCode = null;
        $this->cardBrand = null;
        $this->cardLastDigits = null;
        $this->gatewayResponse = null;
        $this->errorCode = null;
        $this->errorMessage = null;
        $this->processedAt = null;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public static function createSale(
        Uuid $chargeId,
        Uuid $acquirerId,
        Money $amount,
        int $installments = 1,
        ?string $cardBrand = null,
        ?string $cardLastDigits = null
    ): self {
        $transaction = new self(
            Uuid::generate(),
            $chargeId,
            $acquirerId,
            self::TYPE_SALE,
            $amount,
            $installments
        );

        $transaction->cardBrand = $cardBrand;
        $transaction->cardLastDigits = $cardLastDigits;

        return $transaction;
    }

    public static function createAuthorization(
        Uuid $chargeId,
        Uuid $acquirerId,
        Money $amount,
        int $installments = 1
    ): self {
        return new self(
            Uuid::generate(),
            $chargeId,
            $acquirerId,
            self::TYPE_AUTHORIZATION,
            $amount,
            $installments
        );
    }

    public static function createRefund(
        Uuid $chargeId,
        Uuid $acquirerId,
        Money $amount
    ): self {
        return new self(
            Uuid::generate(),
            $chargeId,
            $acquirerId,
            self::TYPE_REFUND,
            $amount
        );
    }

    public static function reconstitute(
        Uuid $id,
        Uuid $chargeId,
        Uuid $acquirerId,
        string $type,
        TransactionStatus $status,
        Money $amount,
        Money $fee,
        Money $netAmount,
        ?string $acquirerTid,
        ?string $acquirerNsu,
        ?string $authorizationCode,
        ?string $cardBrand,
        ?string $cardLastDigits,
        int $installments,
        ?array $gatewayResponse,
        ?string $errorCode,
        ?string $errorMessage,
        ?DateTimeImmutable $processedAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt
    ): self {
        $transaction = new self($id, $chargeId, $acquirerId, $type, $amount, $installments);
        $transaction->status = $status;
        $transaction->fee = $fee;
        $transaction->netAmount = $netAmount;
        $transaction->acquirerTid = $acquirerTid;
        $transaction->acquirerNsu = $acquirerNsu;
        $transaction->authorizationCode = $authorizationCode;
        $transaction->cardBrand = $cardBrand;
        $transaction->cardLastDigits = $cardLastDigits;
        $transaction->gatewayResponse = $gatewayResponse;
        $transaction->errorCode = $errorCode;
        $transaction->errorMessage = $errorMessage;
        $transaction->processedAt = $processedAt;
        $transaction->createdAt = $createdAt;
        $transaction->updatedAt = $updatedAt;

        return $transaction;
    }

    public function markAsProcessing(): void
    {
        $this->status = TransactionStatus::PROCESSING;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function approve(
        string $acquirerTid,
        string $acquirerNsu,
        string $authorizationCode,
        Money $fee,
        ?array $gatewayResponse = null
    ): void {
        if (!$this->status->canTransitionTo(TransactionStatus::APPROVED)) {
            throw new InvalidArgumentException(
                "Cannot approve: invalid status transition from {$this->status->value}"
            );
        }

        $this->status = TransactionStatus::APPROVED;
        $this->acquirerTid = $acquirerTid;
        $this->acquirerNsu = $acquirerNsu;
        $this->authorizationCode = $authorizationCode;
        $this->fee = $fee;
        $this->netAmount = $this->amount->subtract($fee);
        $this->gatewayResponse = $gatewayResponse;
        $this->processedAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();

        $this->recordEvent(new TransactionApproved(
            $this->id->toString(),
            $this->chargeId->toString(),
            $this->amount->getAmount(),
            $this->authorizationCode
        ));
    }

    public function decline(
        string $errorCode,
        string $errorMessage,
        ?array $gatewayResponse = null
    ): void {
        if (!$this->status->canTransitionTo(TransactionStatus::DECLINED)) {
            throw new InvalidArgumentException(
                "Cannot decline: invalid status transition from {$this->status->value}"
            );
        }

        $this->status = TransactionStatus::DECLINED;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
        $this->gatewayResponse = $gatewayResponse;
        $this->processedAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();

        $this->recordEvent(new TransactionDeclined(
            $this->id->toString(),
            $this->chargeId->toString(),
            $this->errorCode,
            $this->errorMessage
        ));
    }

    public function markAsFailed(string $errorCode, string $errorMessage): void
    {
        $this->status = TransactionStatus::FAILED;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
        $this->processedAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function setCardInfo(string $brand, string $lastDigits): void
    {
        $this->cardBrand = $brand;
        $this->cardLastDigits = $lastDigits;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function isApproved(): bool
    {
        return $this->status === TransactionStatus::APPROVED;
    }

    public function isDeclined(): bool
    {
        return $this->status === TransactionStatus::DECLINED;
    }

    public function isFailed(): bool
    {
        return $this->status === TransactionStatus::FAILED;
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isSale(): bool
    {
        return $this->type === self::TYPE_SALE;
    }

    public function isRefund(): bool
    {
        return $this->type === self::TYPE_REFUND;
    }

    // Getters
    public function getChargeId(): Uuid
    {
        return $this->chargeId;
    }

    public function getAcquirerId(): Uuid
    {
        return $this->acquirerId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): TransactionStatus
    {
        return $this->status;
    }

    public function getAmount(): Money
    {
        return $this->amount;
    }

    public function getFee(): Money
    {
        return $this->fee;
    }

    public function getNetAmount(): Money
    {
        return $this->netAmount;
    }

    public function getAcquirerTid(): ?string
    {
        return $this->acquirerTid;
    }

    public function getAcquirerNsu(): ?string
    {
        return $this->acquirerNsu;
    }

    public function getAuthorizationCode(): ?string
    {
        return $this->authorizationCode;
    }

    public function getCardBrand(): ?string
    {
        return $this->cardBrand;
    }

    public function getCardLastDigits(): ?string
    {
        return $this->cardLastDigits;
    }

    public function getInstallments(): int
    {
        return $this->installments;
    }

    public function getGatewayResponse(): ?array
    {
        return $this->gatewayResponse;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getProcessedAt(): ?DateTimeImmutable
    {
        return $this->processedAt;
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
            'charge_id' => $this->chargeId->toString(),
            'acquirer_id' => $this->acquirerId->toString(),
            'type' => $this->type,
            'status' => $this->status->value,
            'amount' => $this->amount->toArray(),
            'fee' => $this->fee->toArray(),
            'net_amount' => $this->netAmount->toArray(),
            'acquirer_tid' => $this->acquirerTid,
            'acquirer_nsu' => $this->acquirerNsu,
            'authorization_code' => $this->authorizationCode,
            'card_brand' => $this->cardBrand,
            'card_last_digits' => $this->cardLastDigits,
            'installments' => $this->installments,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'processed_at' => $this->processedAt?->format('Y-m-d H:i:s'),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
