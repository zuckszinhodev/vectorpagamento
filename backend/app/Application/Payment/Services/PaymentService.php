<?php

declare(strict_types=1);

namespace Application\Payment\Services;

use Application\Payment\DTOs\CreateChargeDTO;
use Application\Payment\DTOs\ProcessPaymentDTO;
use Domain\Acquirer\Contracts\AcquirerGatewayInterface;
use Domain\Acquirer\ValueObjects\AcquirerResponse;
use Domain\Payment\Entities\Charge;
use Domain\Payment\Entities\Transaction;
use Domain\Payment\Repositories\ChargeRepositoryInterface;
use Domain\Payment\Repositories\TransactionRepositoryInterface;
use Domain\Payment\ValueObjects\CardData;
use Domain\Payment\ValueObjects\PaymentMethod;
use Domain\Shared\ValueObjects\Money;
use Domain\Shared\ValueObjects\Uuid;
use Infrastructure\Acquirers\Services\AcquirerRoutingService;
use Infrastructure\Queue\Jobs\SendWebhookJob;
use Infrastructure\Security\Services\IdempotencyService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class PaymentService
{
    public function __construct(
        private ChargeRepositoryInterface $chargeRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private AcquirerRoutingService $routingService,
        private IdempotencyService $idempotencyService,
        private WebhookDispatcherService $webhookDispatcher
    ) {}

    public function createCharge(CreateChargeDTO $dto): Charge
    {
        // Check idempotency
        $existingCharge = $this->chargeRepository->findByIdempotencyKey($dto->idempotencyKey);
        if ($existingCharge) {
            return $existingCharge;
        }

        $charge = Charge::create(
            accountId: Uuid::fromString($dto->accountId),
            idempotencyKey: $dto->idempotencyKey,
            amount: Money::fromCents($dto->amount, $dto->currency),
            paymentMethod: PaymentMethod::from($dto->paymentMethod),
            customer: $dto->customer,
            productId: $dto->productId ? Uuid::fromString($dto->productId) : null,
            checkoutId: $dto->checkoutId ? Uuid::fromString($dto->checkoutId) : null,
            affiliateId: $dto->affiliateId ? Uuid::fromString($dto->affiliateId) : null,
            externalId: $dto->externalId,
            billing: $dto->billing,
            metadata: $dto->metadata
        );

        $this->chargeRepository->save($charge);

        // Dispatch webhook
        $this->webhookDispatcher->dispatch(
            $charge->getAccountId()->toString(),
            'charge.created',
            $charge->toArray()
        );

        return $charge;
    }

    public function processPayment(ProcessPaymentDTO $dto): array
    {
        $charge = $this->chargeRepository->findById(Uuid::fromString($dto->chargeId));

        if (!$charge) {
            throw new InvalidArgumentException('Charge not found');
        }

        if (!$charge->isPending()) {
            throw new InvalidArgumentException('Charge is not pending');
        }

        if ($charge->isExpired()) {
            $charge->markAsExpired();
            $this->chargeRepository->save($charge);
            throw new InvalidArgumentException('Charge has expired');
        }

        // Mark as processing
        $charge->markAsProcessing();
        $this->chargeRepository->save($charge);

        // Get the best acquirer
        $acquirer = $this->routingService->getAcquirer(
            $charge->getAccountId()->toString(),
            $charge->getAmount()->getAmount(),
            $charge->getPaymentMethod()->value
        );

        if (!$acquirer) {
            throw new RuntimeException('No available acquirer for this payment');
        }

        // Process based on payment method
        $response = $this->processWithAcquirer($charge, $acquirer, $dto);

        // Create transaction record
        $transaction = $this->createTransaction($charge, $acquirer, $response, $dto);

        // Update charge status based on response
        $this->updateChargeStatus($charge, $response);

        // Dispatch webhooks
        $this->dispatchPaymentWebhooks($charge, $transaction, $response);

        return [
            'charge' => $charge->toArray(),
            'transaction' => $transaction->toArray(),
            'acquirer_response' => $response->toArray(),
        ];
    }

    private function processWithAcquirer(
        Charge $charge,
        AcquirerGatewayInterface $acquirer,
        ProcessPaymentDTO $dto
    ): AcquirerResponse {
        $paymentMethod = $charge->getPaymentMethod();

        return match ($paymentMethod) {
            PaymentMethod::CREDIT_CARD, PaymentMethod::DEBIT_CARD => $this->processCardPayment($charge, $acquirer, $dto),
            PaymentMethod::PIX => $acquirer->generatePix($charge),
            PaymentMethod::BOLETO => $acquirer->generateBoleto($charge),
        };
    }

    private function processCardPayment(
        Charge $charge,
        AcquirerGatewayInterface $acquirer,
        ProcessPaymentDTO $dto
    ): AcquirerResponse {
        if (!$dto->cardData) {
            throw new InvalidArgumentException('Card data is required for card payments');
        }

        $cardData = CardData::create(
            $dto->cardData['holder_name'],
            $dto->cardData['number'],
            $dto->cardData['expiration_month'],
            $dto->cardData['expiration_year'],
            $dto->cardData['cvv']
        );

        return $acquirer->processCard(
            $charge,
            $cardData,
            $dto->installments ?? 1
        );
    }

    private function createTransaction(
        Charge $charge,
        AcquirerGatewayInterface $acquirer,
        AcquirerResponse $response,
        ProcessPaymentDTO $dto
    ): Transaction {
        $acquirerId = Uuid::fromString($this->routingService->getAcquirerId($acquirer->getIdentifier()));

        $transaction = Transaction::createSale(
            chargeId: $charge->getId(),
            acquirerId: $acquirerId,
            amount: $charge->getAmount(),
            installments: $dto->installments ?? 1,
            cardBrand: $dto->cardData ? CardData::create(
                $dto->cardData['holder_name'],
                $dto->cardData['number'],
                $dto->cardData['expiration_month'],
                $dto->cardData['expiration_year'],
                $dto->cardData['cvv']
            )->getBrand() : null,
            cardLastDigits: $dto->cardData ? substr(preg_replace('/\s+/', '', $dto->cardData['number']), -4) : null
        );

        if ($response->isApproved()) {
            $fee = $acquirer->calculateFee(
                $charge->getAmount(),
                $charge->getPaymentMethod()->value,
                $dto->installments ?? 1
            );

            $transaction->approve(
                $response->getTransactionId(),
                $response->getNsu(),
                $response->getAuthorizationCode(),
                $fee,
                $response->getRawResponse()
            );
        } elseif ($response->isPending()) {
            $transaction->markAsProcessing();
        } else {
            $transaction->decline(
                $response->getErrorCode() ?? 'unknown',
                $response->getErrorMessage() ?? 'Transaction declined',
                $response->getRawResponse()
            );
        }

        $this->transactionRepository->save($transaction);

        return $transaction;
    }

    private function updateChargeStatus(Charge $charge, AcquirerResponse $response): void
    {
        if ($response->isApproved()) {
            $charge->markAsPaid();
        } elseif (!$response->isPending()) {
            $charge->markAsDeclined();
        }

        $this->chargeRepository->save($charge);
    }

    private function dispatchPaymentWebhooks(
        Charge $charge,
        Transaction $transaction,
        AcquirerResponse $response
    ): void {
        $eventType = match (true) {
            $response->isApproved() => 'payment.approved',
            $response->isPending() => 'payment.pending',
            default => 'payment.declined',
        };

        $this->webhookDispatcher->dispatch(
            $charge->getAccountId()->toString(),
            $eventType,
            [
                'charge' => $charge->toArray(),
                'transaction' => $transaction->toArray(),
            ]
        );
    }

    public function getCharge(string $chargeId): ?Charge
    {
        return $this->chargeRepository->findById(Uuid::fromString($chargeId));
    }

    public function listCharges(string $accountId, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;
        $accountUuid = Uuid::fromString($accountId);

        $charges = $this->chargeRepository->findByAccountId($accountUuid, $filters, $perPage, $offset);
        $total = $this->chargeRepository->countByAccountId($accountUuid, $filters);

        return [
            'data' => array_map(fn (Charge $charge) => $charge->toArray(), $charges),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage),
            ],
        ];
    }
}
