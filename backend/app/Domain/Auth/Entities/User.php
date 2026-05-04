<?php

declare(strict_types=1);

namespace Domain\Auth\Entities;

use DateTimeImmutable;
use Domain\Auth\Events\UserCreated;
use Domain\Auth\Events\UserPasswordChanged;
use Domain\Auth\ValueObjects\Email;
use Domain\Auth\ValueObjects\Password;
use Domain\Shared\Contracts\AggregateRoot;
use Domain\Shared\ValueObjects\Uuid;

class User extends AggregateRoot
{
    private Uuid $accountId;
    private Email $email;
    private Password $password;
    private string $name;
    private ?string $phone;
    private ?Uuid $roleId;
    private ?DateTimeImmutable $emailVerifiedAt;
    private bool $mfaEnabled;
    private ?string $mfaSecret;
    private ?DateTimeImmutable $lastLoginAt;
    private ?string $lastLoginIp;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;
    private ?DateTimeImmutable $deletedAt;

    private function __construct(
        Uuid $id,
        Uuid $accountId,
        Email $email,
        Password $password,
        string $name,
        ?string $phone = null,
        ?Uuid $roleId = null
    ) {
        $this->id = $id;
        $this->accountId = $accountId;
        $this->email = $email;
        $this->password = $password;
        $this->name = $name;
        $this->phone = $phone;
        $this->roleId = $roleId;
        $this->emailVerifiedAt = null;
        $this->mfaEnabled = false;
        $this->mfaSecret = null;
        $this->lastLoginAt = null;
        $this->lastLoginIp = null;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        $this->deletedAt = null;
    }

    public static function create(
        Uuid $accountId,
        Email $email,
        Password $password,
        string $name,
        ?string $phone = null,
        ?Uuid $roleId = null
    ): self {
        $user = new self(
            Uuid::generate(),
            $accountId,
            $email,
            $password,
            $name,
            $phone,
            $roleId
        );

        $user->recordEvent(new UserCreated(
            $user->id->toString(),
            $user->email->getValue(),
            $user->name
        ));

        return $user;
    }

    public static function reconstitute(
        Uuid $id,
        Uuid $accountId,
        Email $email,
        Password $password,
        string $name,
        ?string $phone,
        ?Uuid $roleId,
        ?DateTimeImmutable $emailVerifiedAt,
        bool $mfaEnabled,
        ?string $mfaSecret,
        ?DateTimeImmutable $lastLoginAt,
        ?string $lastLoginIp,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?DateTimeImmutable $deletedAt
    ): self {
        $user = new self($id, $accountId, $email, $password, $name, $phone, $roleId);
        $user->emailVerifiedAt = $emailVerifiedAt;
        $user->mfaEnabled = $mfaEnabled;
        $user->mfaSecret = $mfaSecret;
        $user->lastLoginAt = $lastLoginAt;
        $user->lastLoginIp = $lastLoginIp;
        $user->createdAt = $createdAt;
        $user->updatedAt = $updatedAt;
        $user->deletedAt = $deletedAt;

        return $user;
    }

    public function verifyPassword(string $plainPassword): bool
    {
        return $this->password->verify($plainPassword);
    }

    public function changePassword(Password $newPassword): void
    {
        $this->password = $newPassword;
        $this->updatedAt = new DateTimeImmutable();

        $this->recordEvent(new UserPasswordChanged($this->id->toString()));
    }

    public function updateProfile(string $name, ?string $phone): void
    {
        $this->name = $name;
        $this->phone = $phone;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function verifyEmail(): void
    {
        $this->emailVerifiedAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function enableMfa(string $secret): void
    {
        $this->mfaSecret = $secret;
        $this->mfaEnabled = true;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function disableMfa(): void
    {
        $this->mfaSecret = null;
        $this->mfaEnabled = false;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function recordLogin(string $ipAddress): void
    {
        $this->lastLoginAt = new DateTimeImmutable();
        $this->lastLoginIp = $ipAddress;
    }

    public function assignRole(Uuid $roleId): void
    {
        $this->roleId = $roleId;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function softDelete(): void
    {
        $this->deletedAt = new DateTimeImmutable();
    }

    public function restore(): void
    {
        $this->deletedAt = null;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function isMfaEnabled(): bool
    {
        return $this->mfaEnabled;
    }

    // Getters
    public function getAccountId(): Uuid
    {
        return $this->accountId;
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getPassword(): Password
    {
        return $this->password;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getRoleId(): ?Uuid
    {
        return $this->roleId;
    }

    public function getEmailVerifiedAt(): ?DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function getMfaSecret(): ?string
    {
        return $this->mfaSecret;
    }

    public function getLastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function getLastLoginIp(): ?string
    {
        return $this->lastLoginIp;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'account_id' => $this->accountId->toString(),
            'email' => $this->email->getValue(),
            'name' => $this->name,
            'phone' => $this->phone,
            'role_id' => $this->roleId?->toString(),
            'email_verified_at' => $this->emailVerifiedAt?->format('Y-m-d H:i:s'),
            'mfa_enabled' => $this->mfaEnabled,
            'last_login_at' => $this->lastLoginAt?->format('Y-m-d H:i:s'),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
