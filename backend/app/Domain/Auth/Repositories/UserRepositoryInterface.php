<?php

declare(strict_types=1);

namespace Domain\Auth\Repositories;

use Domain\Auth\Entities\User;
use Domain\Auth\ValueObjects\Email;
use Domain\Shared\ValueObjects\Uuid;

interface UserRepositoryInterface
{
    public function findById(Uuid $id): ?User;
    
    public function findByEmail(Email $email): ?User;
    
    public function findByAccountId(Uuid $accountId): array;
    
    public function save(User $user): void;
    
    public function delete(User $user): void;
    
    public function emailExists(Email $email, ?Uuid $excludeId = null): bool;
}
