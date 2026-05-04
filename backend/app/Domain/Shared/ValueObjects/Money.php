<?php

declare(strict_types=1);

namespace Domain\Shared\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;

final class Money implements JsonSerializable
{
    private int $amount; // Amount in cents
    private string $currency;

    private const SUPPORTED_CURRENCIES = ['BRL', 'USD', 'EUR'];
    private const CURRENCY_DECIMALS = [
        'BRL' => 2,
        'USD' => 2,
        'EUR' => 2,
    ];

    private function __construct(int $amount, string $currency)
    {
        $this->validateCurrency($currency);
        $this->validateAmount($amount);

        $this->amount = $amount;
        $this->currency = strtoupper($currency);
    }

    public static function fromCents(int $cents, string $currency = 'BRL'): self
    {
        return new self($cents, $currency);
    }

    public static function fromDecimal(float $decimal, string $currency = 'BRL'): self
    {
        $decimals = self::CURRENCY_DECIMALS[$currency] ?? 2;
        $cents = (int) round($decimal * pow(10, $decimals));

        return new self($cents, $currency);
    }

    public static function zero(string $currency = 'BRL'): self
    {
        return new self(0, $currency);
    }

    private function validateCurrency(string $currency): void
    {
        if (!in_array(strtoupper($currency), self::SUPPORTED_CURRENCIES, true)) {
            throw new InvalidArgumentException(
                "Unsupported currency: {$currency}. Supported: " . implode(', ', self::SUPPORTED_CURRENCIES)
            );
        }
    }

    private function validateAmount(int $amount): void
    {
        if ($amount < 0) {
            throw new InvalidArgumentException("Amount cannot be negative: {$amount}");
        }
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function toDecimal(): float
    {
        $decimals = self::CURRENCY_DECIMALS[$this->currency];
        return $this->amount / pow(10, $decimals);
    }

    public function format(): string
    {
        $symbols = [
            'BRL' => 'R$',
            'USD' => '$',
            'EUR' => '€',
        ];

        $symbol = $symbols[$this->currency] ?? $this->currency;
        $decimal = $this->toDecimal();

        return sprintf('%s %.2f', $symbol, $decimal);
    }

    public function add(self $other): self
    {
        $this->ensureSameCurrency($other);
        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->ensureSameCurrency($other);

        if ($other->amount > $this->amount) {
            throw new InvalidArgumentException('Cannot subtract: result would be negative');
        }

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(float $multiplier): self
    {
        if ($multiplier < 0) {
            throw new InvalidArgumentException('Multiplier cannot be negative');
        }

        $newAmount = (int) round($this->amount * $multiplier);
        return new self($newAmount, $this->currency);
    }

    public function percentage(float $percent): self
    {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('Percentage must be between 0 and 100');
        }

        return $this->multiply($percent / 100);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->ensureSameCurrency($other);
        return $this->amount > $other->amount;
    }

    public function isGreaterThanOrEqual(self $other): bool
    {
        $this->ensureSameCurrency($other);
        return $this->amount >= $other->amount;
    }

    public function isLessThan(self $other): bool
    {
        $this->ensureSameCurrency($other);
        return $this->amount < $other->amount;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    private function ensureSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Currency mismatch: {$this->currency} vs {$other->currency}"
            );
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'formatted' => $this->format(),
        ];
    }

    public function toArray(): array
    {
        return $this->jsonSerialize();
    }
}
