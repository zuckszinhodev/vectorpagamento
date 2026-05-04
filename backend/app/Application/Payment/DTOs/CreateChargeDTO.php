<?php

declare(strict_types=1);

namespace Application\Payment\DTOs;

final class CreateChargeDTO
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $idempotencyKey,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $paymentMethod,
        public readonly array $customer,
        public readonly ?string $productId = null,
        public readonly ?string $checkoutId = null,
        public readonly ?string $affiliateId = null,
        public readonly ?string $externalId = null,
        public readonly ?array $billing = null,
        public readonly array $metadata = []
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            accountId: $data['account_id'],
            idempotencyKey: $data['idempotency_key'],
            amount: (int) $data['amount'],
            currency: $data['currency'] ?? 'BRL',
            paymentMethod: $data['payment_method'],
            customer: $data['customer'],
            productId: $data['product_id'] ?? null,
            checkoutId: $data['checkout_id'] ?? null,
            affiliateId: $data['affiliate_id'] ?? null,
            externalId: $data['external_id'] ?? null,
            billing: $data['billing'] ?? null,
            metadata: $data['metadata'] ?? []
        );
    }
}
