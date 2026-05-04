<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repositories;

use DateTimeImmutable;
use Domain\Payment\Entities\Charge;
use Domain\Payment\Repositories\ChargeRepositoryInterface;
use Domain\Payment\ValueObjects\PaymentMethod;
use Domain\Payment\ValueObjects\TransactionStatus;
use Domain\Shared\ValueObjects\Money;
use Domain\Shared\ValueObjects\Uuid;
use Illuminate\Support\Facades\DB;

class EloquentChargeRepository implements ChargeRepositoryInterface
{
    private string $table = 'charges';

    public function findById(Uuid $id): ?Charge
    {
        $record = DB::table($this->table)
            ->where('id', $id->toString())
            ->first();

        if (!$record) {
            return null;
        }

        return $this->toDomainEntity($record);
    }

    public function findByIdempotencyKey(string $key): ?Charge
    {
        $record = DB::table($this->table)
            ->where('idempotency_key', $key)
            ->first();

        if (!$record) {
            return null;
        }

        return $this->toDomainEntity($record);
    }

    public function findByAccountId(Uuid $accountId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $query = DB::table($this->table)
            ->where('account_id', $accountId->toString());

        $this->applyFilters($query, $filters);

        $records = $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return $records->map(fn ($record) => $this->toDomainEntity($record))->toArray();
    }

    public function findByStatus(Uuid $accountId, TransactionStatus $status): array
    {
        $records = DB::table($this->table)
            ->where('account_id', $accountId->toString())
            ->where('status', $status->value)
            ->orderBy('created_at', 'desc')
            ->get();

        return $records->map(fn ($record) => $this->toDomainEntity($record))->toArray();
    }

    public function save(Charge $charge): void
    {
        $data = [
            'id' => $charge->getId()->toString(),
            'account_id' => $charge->getAccountId()->toString(),
            'product_id' => $charge->getProductId()?->toString(),
            'checkout_id' => $charge->getCheckoutId()?->toString(),
            'affiliate_id' => $charge->getAffiliateId()?->toString(),
            'idempotency_key' => $charge->getIdempotencyKey(),
            'external_id' => $charge->getExternalId(),
            'amount' => $charge->getAmount()->getAmount(),
            'currency' => $charge->getAmount()->getCurrency(),
            'payment_method' => $charge->getPaymentMethod()->value,
            'status' => $charge->getStatus()->value,
            'customer' => json_encode($charge->getCustomer()),
            'billing' => $charge->getBilling() ? json_encode($charge->getBilling()) : null,
            'metadata' => json_encode($charge->getMetadata()),
            'expires_at' => $charge->getExpiresAt()?->format('Y-m-d H:i:s'),
            'paid_at' => $charge->getPaidAt()?->format('Y-m-d H:i:s'),
            'updated_at' => now(),
        ];

        $exists = DB::table($this->table)
            ->where('id', $charge->getId()->toString())
            ->exists();

        if ($exists) {
            DB::table($this->table)
                ->where('id', $charge->getId()->toString())
                ->update($data);
        } else {
            $data['created_at'] = now();
            DB::table($this->table)->insert($data);
        }
    }

    public function countByAccountId(Uuid $accountId, array $filters = []): int
    {
        $query = DB::table($this->table)
            ->where('account_id', $accountId->toString());

        $this->applyFilters($query, $filters);

        return $query->count();
    }

    public function findExpired(): array
    {
        $records = DB::table($this->table)
            ->where('status', TransactionStatus::PENDING->value)
            ->where('expires_at', '<', now())
            ->get();

        return $records->map(fn ($record) => $this->toDomainEntity($record))->toArray();
    }

    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        if (!empty($filters['min_amount'])) {
            $query->where('amount', '>=', $filters['min_amount']);
        }

        if (!empty($filters['max_amount'])) {
            $query->where('amount', '<=', $filters['max_amount']);
        }
    }

    private function toDomainEntity(object $record): Charge
    {
        return Charge::reconstitute(
            Uuid::fromString($record->id),
            Uuid::fromString($record->account_id),
            $record->product_id ? Uuid::fromString($record->product_id) : null,
            $record->checkout_id ? Uuid::fromString($record->checkout_id) : null,
            $record->affiliate_id ? Uuid::fromString($record->affiliate_id) : null,
            $record->idempotency_key,
            $record->external_id,
            Money::fromCents((int) $record->amount, $record->currency),
            PaymentMethod::from($record->payment_method),
            TransactionStatus::from($record->status),
            json_decode($record->customer, true),
            $record->billing ? json_decode($record->billing, true) : null,
            json_decode($record->metadata, true) ?? [],
            $record->expires_at ? new DateTimeImmutable($record->expires_at) : null,
            $record->paid_at ? new DateTimeImmutable($record->paid_at) : null,
            new DateTimeImmutable($record->created_at),
            new DateTimeImmutable($record->updated_at)
        );
    }
}
