<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repositories;

use DateTimeImmutable;
use Domain\Payment\Entities\Transaction;
use Domain\Payment\Repositories\TransactionRepositoryInterface;
use Domain\Payment\ValueObjects\TransactionStatus;
use Domain\Shared\ValueObjects\Money;
use Domain\Shared\ValueObjects\Uuid;
use Illuminate\Support\Facades\DB;

class EloquentTransactionRepository implements TransactionRepositoryInterface
{
    private string $table = 'transactions';

    public function findById(Uuid $id): ?Transaction
    {
        $record = DB::table($this->table)
            ->where('id', $id->toString())
            ->first();

        if (!$record) {
            return null;
        }

        return $this->toDomainEntity($record);
    }

    public function findByChargeId(Uuid $chargeId): array
    {
        $records = DB::table($this->table)
            ->where('charge_id', $chargeId->toString())
            ->orderBy('created_at', 'desc')
            ->get();

        return $records->map(fn ($record) => $this->toDomainEntity($record))->toArray();
    }

    public function findByAcquirerTid(string $tid): ?Transaction
    {
        $record = DB::table($this->table)
            ->where('acquirer_tid', $tid)
            ->first();

        if (!$record) {
            return null;
        }

        return $this->toDomainEntity($record);
    }

    public function save(Transaction $transaction): void
    {
        $data = [
            'id' => $transaction->getId()->toString(),
            'charge_id' => $transaction->getChargeId()->toString(),
            'acquirer_id' => $transaction->getAcquirerId()->toString(),
            'type' => $transaction->getType(),
            'status' => $transaction->getStatus()->value,
            'amount' => $transaction->getAmount()->getAmount(),
            'currency' => $transaction->getAmount()->getCurrency(),
            'fee' => $transaction->getFee()->getAmount(),
            'net_amount' => $transaction->getNetAmount()->getAmount(),
            'acquirer_tid' => $transaction->getAcquirerTid(),
            'acquirer_nsu' => $transaction->getAcquirerNsu(),
            'authorization_code' => $transaction->getAuthorizationCode(),
            'card_brand' => $transaction->getCardBrand(),
            'card_last_digits' => $transaction->getCardLastDigits(),
            'installments' => $transaction->getInstallments(),
            'gateway_response' => $transaction->getGatewayResponse() ? json_encode($transaction->getGatewayResponse()) : null,
            'error_code' => $transaction->getErrorCode(),
            'error_message' => $transaction->getErrorMessage(),
            'processed_at' => $transaction->getProcessedAt()?->format('Y-m-d H:i:s'),
            'updated_at' => now(),
        ];

        $exists = DB::table($this->table)
            ->where('id', $transaction->getId()->toString())
            ->exists();

        if ($exists) {
            DB::table($this->table)
                ->where('id', $transaction->getId()->toString())
                ->update($data);
        } else {
            $data['created_at'] = now();
            DB::table($this->table)->insert($data);
        }
    }

    public function getLatestByChargeId(Uuid $chargeId): ?Transaction
    {
        $record = DB::table($this->table)
            ->where('charge_id', $chargeId->toString())
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$record) {
            return null;
        }

        return $this->toDomainEntity($record);
    }

    private function toDomainEntity(object $record): Transaction
    {
        return Transaction::reconstitute(
            Uuid::fromString($record->id),
            Uuid::fromString($record->charge_id),
            Uuid::fromString($record->acquirer_id ?? $record->id),
            $record->type ?? 'sale',
            TransactionStatus::from($record->status),
            Money::fromCents((int) $record->amount, $record->currency ?? 'BRL'),
            Money::fromCents((int) ($record->fee ?? 0), $record->currency ?? 'BRL'),
            Money::fromCents((int) ($record->net_amount ?? $record->amount), $record->currency ?? 'BRL'),
            $record->acquirer_tid ?? null,
            $record->acquirer_nsu ?? null,
            $record->authorization_code ?? null,
            $record->card_brand ?? null,
            $record->card_last_digits ?? null,
            (int) ($record->installments ?? 1),
            isset($record->gateway_response) ? json_decode($record->gateway_response, true) : null,
            $record->error_code ?? null,
            $record->error_message ?? null,
            isset($record->processed_at) ? new DateTimeImmutable($record->processed_at) : null,
            new DateTimeImmutable($record->created_at),
            new DateTimeImmutable($record->updated_at)
        );
    }
}
