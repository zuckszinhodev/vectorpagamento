<?php

declare(strict_types=1);

namespace Domain\Payment\Repositories;

use Domain\Payment\Entities\Charge;
use Domain\Payment\ValueObjects\TransactionStatus;
use Domain\Shared\ValueObjects\Uuid;

interface ChargeRepositoryInterface
{
    public function findById(Uuid $id): ?Charge;
    
    public function findByIdempotencyKey(string $key): ?Charge;
    
    public function findByAccountId(Uuid $accountId, array $filters = [], int $limit = 50, int $offset = 0): array;
    
    public function findByStatus(Uuid $accountId, TransactionStatus $status): array;
    
    public function save(Charge $charge): void;
    
    public function countByAccountId(Uuid $accountId, array $filters = []): int;
    
    public function findExpired(): array;
}
