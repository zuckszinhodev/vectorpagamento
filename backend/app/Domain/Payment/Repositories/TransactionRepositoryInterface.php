<?php

declare(strict_types=1);

namespace Domain\Payment\Repositories;

use Domain\Payment\Entities\Transaction;
use Domain\Shared\ValueObjects\Uuid;

interface TransactionRepositoryInterface
{
    public function findById(Uuid $id): ?Transaction;
    
    public function findByChargeId(Uuid $chargeId): array;
    
    public function findByAcquirerTid(string $tid): ?Transaction;
    
    public function save(Transaction $transaction): void;
    
    public function getLatestByChargeId(Uuid $chargeId): ?Transaction;
}
