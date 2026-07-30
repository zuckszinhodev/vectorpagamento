<?php

declare(strict_types=1);

namespace App\Domain\Routing\Contracts;

use App\Models\AcquirerConfig;

/**
 * Stores and retrieves acquirer credentials, always encrypted at rest.
 */
interface AcquirerCredentialRepositoryInterface
{
    /**
     * Decrypted credentials for an acquirer. Call sites must treat the result
     * as write-only into the provider and never log or return it.
     *
     * @return array<string, string>
     */
    public function decrypted(AcquirerConfig $config): array;

    /**
     * Masked representation for the admin panel — never the real value.
     *
     * @return array<string, array<string, mixed>>
     */
    public function masked(AcquirerConfig $config): array;

    /**
     * Persist credential fields.
     *
     * Values that are empty or still carry the mask placeholder are skipped so
     * that saving a form without retyping a secret does not erase it.
     *
     * @param array<string, string|null> $values
     * @return array<int, string> field keys actually written
     */
    public function store(AcquirerConfig $config, array $values): array;

    /**
     * Remove a single credential field.
     */
    public function forget(AcquirerConfig $config, string $fieldKey): void;

    /**
     * Remove every credential of an acquirer (used when deleting it).
     */
    public function purge(AcquirerConfig $config): void;

    /**
     * Whether the acquirer has every field required to operate.
     */
    public function hasRequiredFields(AcquirerConfig $config): bool;
}
