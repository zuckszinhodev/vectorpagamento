<?php

declare(strict_types=1);

namespace Domain\Auth\ValueObjects;

use InvalidArgumentException;

final class Password
{
    private string $hashedValue;

    private function __construct(string $hashedValue)
    {
        $this->hashedValue = $hashedValue;
    }

    public static function fromPlainText(string $plainText): self
    {
        self::validateStrength($plainText);

        $hash = password_hash($plainText, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3,
        ]);

        if ($hash === false) {
            throw new InvalidArgumentException('Failed to hash password');
        }

        return new self($hash);
    }

    public static function fromHash(string $hash): self
    {
        return new self($hash);
    }

    private static function validateStrength(string $password): void
    {
        $errors = [];

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters';
        }

        if (strlen($password) > 128) {
            $errors[] = 'Password must not exceed 128 characters';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }

        if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password)) {
            $errors[] = 'Password must contain at least one special character';
        }

        if (!empty($errors)) {
            throw new InvalidArgumentException(implode('. ', $errors));
        }
    }

    public function verify(string $plainText): bool
    {
        return password_verify($plainText, $this->hashedValue);
    }

    public function needsRehash(): bool
    {
        return password_needs_rehash($this->hashedValue, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3,
        ]);
    }

    public function getHash(): string
    {
        return $this->hashedValue;
    }

    public function __toString(): string
    {
        return '[PROTECTED]';
    }
}
