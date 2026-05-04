<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repositories;

use DateTimeImmutable;
use Domain\Auth\Entities\User;
use Domain\Auth\Repositories\UserRepositoryInterface;
use Domain\Auth\ValueObjects\Email;
use Domain\Auth\ValueObjects\Password;
use Domain\Shared\ValueObjects\Uuid;
use Illuminate\Support\Facades\DB;

class EloquentUserRepository implements UserRepositoryInterface
{
    private string $table = 'users';

    public function findById(Uuid $id): ?User
    {
        $record = DB::table($this->table)
            ->where('id', $id->toString())
            ->whereNull('deleted_at')
            ->first();

        if (!$record) {
            return null;
        }

        return $this->toDomainEntity($record);
    }

    public function findByEmail(Email $email): ?User
    {
        $record = DB::table($this->table)
            ->where('email', $email->getValue())
            ->whereNull('deleted_at')
            ->first();

        if (!$record) {
            return null;
        }

        return $this->toDomainEntity($record);
    }

    public function findByAccountId(Uuid $accountId): array
    {
        $records = DB::table($this->table)
            ->where('account_id', $accountId->toString())
            ->whereNull('deleted_at')
            ->get();

        return $records->map(fn ($record) => $this->toDomainEntity($record))->toArray();
    }

    public function save(User $user): void
    {
        $data = [
            'id' => $user->getId()->toString(),
            'account_id' => $user->getAccountId()->toString(),
            'role_id' => $user->getRoleId()?->toString(),
            'email' => $user->getEmail()->getValue(),
            'password' => $user->getPassword()->getHash(),
            'name' => $user->getName(),
            'phone' => $user->getPhone(),
            'email_verified_at' => $user->getEmailVerifiedAt()?->format('Y-m-d H:i:s'),
            'mfa_enabled' => $user->isMfaEnabled(),
            'mfa_secret' => $user->getMfaSecret(),
            'last_login_at' => $user->getLastLoginAt()?->format('Y-m-d H:i:s'),
            'last_login_ip' => $user->getLastLoginIp(),
            'updated_at' => now(),
            'deleted_at' => $user->getDeletedAt()?->format('Y-m-d H:i:s'),
        ];

        $exists = DB::table($this->table)
            ->where('id', $user->getId()->toString())
            ->exists();

        if ($exists) {
            DB::table($this->table)
                ->where('id', $user->getId()->toString())
                ->update($data);
        } else {
            $data['created_at'] = now();
            DB::table($this->table)->insert($data);
        }
    }

    public function delete(User $user): void
    {
        $user->softDelete();
        $this->save($user);
    }

    public function emailExists(Email $email, ?Uuid $excludeId = null): bool
    {
        $query = DB::table($this->table)
            ->where('email', $email->getValue())
            ->whereNull('deleted_at');

        if ($excludeId) {
            $query->where('id', '!=', $excludeId->toString());
        }

        return $query->exists();
    }

    private function toDomainEntity(object $record): User
    {
        return User::reconstitute(
            Uuid::fromString($record->id),
            Uuid::fromString($record->account_id),
            Email::fromString($record->email),
            Password::fromHash($record->password),
            $record->name,
            $record->phone,
            $record->role_id ? Uuid::fromString($record->role_id) : null,
            $record->email_verified_at ? new DateTimeImmutable($record->email_verified_at) : null,
            (bool) $record->mfa_enabled,
            $record->mfa_secret,
            $record->last_login_at ? new DateTimeImmutable($record->last_login_at) : null,
            $record->last_login_ip,
            new DateTimeImmutable($record->created_at),
            new DateTimeImmutable($record->updated_at),
            $record->deleted_at ? new DateTimeImmutable($record->deleted_at) : null
        );
    }
}
