<?php

declare(strict_types=1);

namespace Domain\Auth\ValueObjects;

use InvalidArgumentException;

final class Email
{
    private string $value;

    private function __construct(string $email)
    {
        $this->validate($email);
        $this->value = strtolower(trim($email));
    }

    public static function fromString(string $email): self
    {
        return new self($email);
    }

    private function validate(string $email): void
    {
        if (empty($email)) {
            throw new InvalidArgumentException('Email cannot be empty');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email format: {$email}");
        }

        if (strlen($email) > 255) {
            throw new InvalidArgumentException('Email is too long');
        }
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getDomain(): string
    {
        return substr($this->value, strpos($this->value, '@') + 1);
    }

    public function getLocalPart(): string
    {
        return substr($this->value, 0, strpos($this->value, '@'));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
