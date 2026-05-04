<?php

declare(strict_types=1);

namespace Domain\Payment\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;

final class CardData implements JsonSerializable
{
    private string $holderName;
    private string $number;
    private string $expirationMonth;
    private string $expirationYear;
    private string $cvv;
    private ?string $brand;
    private ?string $lastFourDigits;
    private ?string $firstSixDigits;

    private function __construct(
        string $holderName,
        string $number,
        string $expirationMonth,
        string $expirationYear,
        string $cvv
    ) {
        $this->validate($holderName, $number, $expirationMonth, $expirationYear, $cvv);

        $this->holderName = strtoupper(trim($holderName));
        $this->number = preg_replace('/\s+/', '', $number);
        $this->expirationMonth = str_pad($expirationMonth, 2, '0', STR_PAD_LEFT);
        $this->expirationYear = $this->normalizeYear($expirationYear);
        $this->cvv = $cvv;
        $this->brand = $this->detectBrand();
        $this->lastFourDigits = substr($this->number, -4);
        $this->firstSixDigits = substr($this->number, 0, 6);
    }

    public static function create(
        string $holderName,
        string $number,
        string $expirationMonth,
        string $expirationYear,
        string $cvv
    ): self {
        return new self($holderName, $number, $expirationMonth, $expirationYear, $cvv);
    }

    private function validate(
        string $holderName,
        string $number,
        string $expirationMonth,
        string $expirationYear,
        string $cvv
    ): void {
        if (empty(trim($holderName))) {
            throw new InvalidArgumentException('Card holder name is required');
        }

        if (strlen($holderName) < 3) {
            throw new InvalidArgumentException('Card holder name must be at least 3 characters');
        }

        $cleanNumber = preg_replace('/\s+/', '', $number);
        if (!$this->isValidLuhn($cleanNumber)) {
            throw new InvalidArgumentException('Invalid card number');
        }

        $month = (int) $expirationMonth;
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('Invalid expiration month');
        }

        $year = $this->normalizeYear($expirationYear);
        $currentYear = (int) date('Y');
        $currentMonth = (int) date('m');

        if ((int) $year < $currentYear || ((int) $year === $currentYear && $month < $currentMonth)) {
            throw new InvalidArgumentException('Card has expired');
        }

        if (!preg_match('/^\d{3,4}$/', $cvv)) {
            throw new InvalidArgumentException('Invalid CVV');
        }
    }

    private function isValidLuhn(string $number): bool
    {
        if (!preg_match('/^\d{13,19}$/', $number)) {
            return false;
        }

        $sum = 0;
        $length = strlen($number);
        $parity = $length % 2;

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $number[$i];

            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    private function normalizeYear(string $year): string
    {
        if (strlen($year) === 2) {
            return '20' . $year;
        }

        return $year;
    }

    private function detectBrand(): string
    {
        $patterns = [
            'visa' => '/^4[0-9]{12}(?:[0-9]{3})?$/',
            'mastercard' => '/^5[1-5][0-9]{14}$|^2(?:2(?:2[1-9]|[3-9][0-9])|[3-6][0-9][0-9]|7(?:[01][0-9]|20))[0-9]{12}$/',
            'amex' => '/^3[47][0-9]{13}$/',
            'elo' => '/^((509091)|(636368)|(636297)|(504175)|(438935)|(40117[8-9])|(45763[1-2])|(457393)|(431274)|(50990[0-2])|(5099[7-9][0-9])|(50996[4-9])|(509[1-8][0-9][0-9])|(5090(0[0-2]|0[4-9]|1[2-9]|[24589][0-9]|3[1-9]|6[0-46-9]|7[0-24-9]))|(5067(0[0-24-8]|1[0-24-9]|2[014-9]|3[0-379]|4[0-9]|5[0-3]|6[0-5]|7[0-8]))|(6504(0[5-9]|1[0-9]|2[0-9]|3[0-9]))|(6504(8[5-9]|9[0-9])|6505(0[0-9]|1[0-9]|2[0-9]|3[0-8]))|(6505(4[1-9]|5[0-9]|6[0-9]|7[0-9]|8[0-9]|9[0-8]))|(6507(0[0-9]|1[0-8]))|(65072[0-7])|(6509(0[1-9]|1[0-9]|20))|(6516(5[2-9]|6[0-9]|7[0-9]))|(6550(0[0-9]|1[0-9]))|(6550(2[1-9]|3[0-9]|4[0-9]|5[0-8])))[0-9]{0,}$/',
            'hipercard' => '/^(606282\d{10}(\d{3})?)|(3841\d{15})$/',
            'diners' => '/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/',
            'discover' => '/^6(?:011|5[0-9]{2})[0-9]{12}$/',
            'jcb' => '/^(?:2131|1800|35\d{3})\d{11}$/',
        ];

        foreach ($patterns as $brand => $pattern) {
            if (preg_match($pattern, $this->number)) {
                return $brand;
            }
        }

        return 'unknown';
    }

    public function getHolderName(): string
    {
        return $this->holderName;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getMaskedNumber(): string
    {
        return str_repeat('*', strlen($this->number) - 4) . $this->lastFourDigits;
    }

    public function getExpirationMonth(): string
    {
        return $this->expirationMonth;
    }

    public function getExpirationYear(): string
    {
        return $this->expirationYear;
    }

    public function getExpiration(): string
    {
        return $this->expirationMonth . '/' . substr($this->expirationYear, -2);
    }

    public function getCvv(): string
    {
        return $this->cvv;
    }

    public function getBrand(): string
    {
        return $this->brand;
    }

    public function getLastFourDigits(): string
    {
        return $this->lastFourDigits;
    }

    public function getFirstSixDigits(): string
    {
        return $this->firstSixDigits;
    }

    public function isExpired(): bool
    {
        $expDate = \DateTime::createFromFormat(
            'Y-m',
            $this->expirationYear . '-' . $this->expirationMonth
        );
        $now = new \DateTime('first day of this month');

        return $expDate < $now;
    }

    public function jsonSerialize(): array
    {
        return [
            'holder_name' => $this->holderName,
            'last_four_digits' => $this->lastFourDigits,
            'first_six_digits' => $this->firstSixDigits,
            'brand' => $this->brand,
            'expiration' => $this->getExpiration(),
        ];
    }

    public function toSafeArray(): array
    {
        return $this->jsonSerialize();
    }
}
