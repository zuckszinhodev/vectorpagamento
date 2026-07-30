<?php

declare(strict_types=1);

namespace App\Domain\Routing\Contracts;

use App\Domain\Routing\Enums\PaymentMethod;
use App\Models\AcquirerRoutingPolicy;

/**
 * Resolves which balancing policy applies to a transaction.
 *
 * Precedence: account-scoped policy → global policy → config defaults.
 */
interface RoutingPolicyRepositoryInterface
{
    public function resolve(PaymentMethod $method, ?string $accountId = null): AcquirerRoutingPolicy;

    /**
     * @return array<int, AcquirerRoutingPolicy>
     */
    public function allGlobal(): array;

    /**
     * @return array<int, AcquirerRoutingPolicy>
     */
    public function forAccount(string $accountId): array;

    /**
     * @param array<string, mixed> $attributes
     */
    public function save(PaymentMethod $method, ?string $accountId, array $attributes): AcquirerRoutingPolicy;

    public function delete(PaymentMethod $method, ?string $accountId): void;
}
