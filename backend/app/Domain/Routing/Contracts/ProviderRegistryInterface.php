<?php

declare(strict_types=1);

namespace App\Domain\Routing\Contracts;

use App\Domain\Routing\Enums\PaymentMethod;

/**
 * Resolves an acquirer key to a ready-to-use provider instance, credentials
 * already loaded.
 *
 * Registering a new acquirer is a single {@see self::register()} call from a
 * service provider — nothing in the routing core needs to know it exists.
 */
interface ProviderRegistryInterface
{
    /**
     * @param callable(string, array<string, string>): PaymentProviderInterface $factory
     * @param array<int, PaymentMethod> $supports
     */
    public function register(
        string $acquirerKey,
        callable $factory,
        array $supports = [],
        ?string $displayName = null,
    ): void;

    /**
     * Instance for an acquirer, or null when it is unknown, disabled or
     * missing required credentials.
     *
     * @param string|null $accountId when set, per-establishment credentials
     *                               take precedence over the global ones
     */
    public function resolve(string $acquirerKey, ?string $accountId = null): ?PaymentProviderInterface;

    public function has(string $acquirerKey): bool;

    /**
     * @return array<int, string>
     */
    public function registeredKeys(): array;

    /**
     * Drop memoised instances (after a credential or config change).
     */
    public function flush(?string $acquirerKey = null): void;
}
