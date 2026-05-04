<?php

declare(strict_types=1);

namespace Domain\Acquirer\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;

final class RoutingRule implements JsonSerializable
{
    private string $acquirerId;
    private int $priority;
    private ?string $paymentMethod;
    private ?int $minAmount;
    private ?int $maxAmount;
    private array $conditions;
    private bool $isActive;

    public function __construct(
        string $acquirerId,
        int $priority,
        ?string $paymentMethod = null,
        ?int $minAmount = null,
        ?int $maxAmount = null,
        array $conditions = [],
        bool $isActive = true
    ) {
        $this->validate($priority, $minAmount, $maxAmount);

        $this->acquirerId = $acquirerId;
        $this->priority = $priority;
        $this->paymentMethod = $paymentMethod;
        $this->minAmount = $minAmount;
        $this->maxAmount = $maxAmount;
        $this->conditions = $conditions;
        $this->isActive = $isActive;
    }

    private function validate(int $priority, ?int $minAmount, ?int $maxAmount): void
    {
        if ($priority < 0) {
            throw new InvalidArgumentException('Priority must be non-negative');
        }

        if ($minAmount !== null && $minAmount < 0) {
            throw new InvalidArgumentException('Minimum amount must be non-negative');
        }

        if ($maxAmount !== null && $maxAmount < 0) {
            throw new InvalidArgumentException('Maximum amount must be non-negative');
        }

        if ($minAmount !== null && $maxAmount !== null && $minAmount > $maxAmount) {
            throw new InvalidArgumentException('Minimum amount cannot be greater than maximum amount');
        }
    }

    public function matches(int $amount, string $paymentMethod): bool
    {
        if (!$this->isActive) {
            return false;
        }

        // Check payment method
        if ($this->paymentMethod !== null && $this->paymentMethod !== $paymentMethod) {
            return false;
        }

        // Check amount range
        if ($this->minAmount !== null && $amount < $this->minAmount) {
            return false;
        }

        if ($this->maxAmount !== null && $amount > $this->maxAmount) {
            return false;
        }

        // Check additional conditions
        return $this->evaluateConditions();
    }

    private function evaluateConditions(): bool
    {
        // Evaluate custom conditions (time-based, percentage-based, etc.)
        foreach ($this->conditions as $condition) {
            if (!$this->evaluateCondition($condition)) {
                return false;
            }
        }

        return true;
    }

    private function evaluateCondition(array $condition): bool
    {
        $type = $condition['type'] ?? null;

        return match ($type) {
            'time_range' => $this->evaluateTimeRange($condition),
            'percentage' => $this->evaluatePercentage($condition),
            'card_brand' => $this->evaluateCardBrand($condition),
            default => true,
        };
    }

    private function evaluateTimeRange(array $condition): bool
    {
        $now = new \DateTime();
        $currentHour = (int) $now->format('H');

        $startHour = $condition['start_hour'] ?? 0;
        $endHour = $condition['end_hour'] ?? 23;

        return $currentHour >= $startHour && $currentHour <= $endHour;
    }

    private function evaluatePercentage(array $condition): bool
    {
        $percentage = $condition['percentage'] ?? 100;
        return rand(1, 100) <= $percentage;
    }

    private function evaluateCardBrand(array $condition): bool
    {
        // This would need card brand passed in context
        return true;
    }

    public function getAcquirerId(): string
    {
        return $this->acquirerId;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    public function getMinAmount(): ?int
    {
        return $this->minAmount;
    }

    public function getMaxAmount(): ?int
    {
        return $this->maxAmount;
    }

    public function getConditions(): array
    {
        return $this->conditions;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function jsonSerialize(): array
    {
        return [
            'acquirer_id' => $this->acquirerId,
            'priority' => $this->priority,
            'payment_method' => $this->paymentMethod,
            'min_amount' => $this->minAmount,
            'max_amount' => $this->maxAmount,
            'conditions' => $this->conditions,
            'is_active' => $this->isActive,
        ];
    }
}
