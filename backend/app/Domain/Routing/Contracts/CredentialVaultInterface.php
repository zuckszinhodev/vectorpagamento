<?php

declare(strict_types=1);

namespace App\Domain\Routing\Contracts;

use App\Domain\Routing\ValueObjects\SecretValue;

/**
 * Encrypts and decrypts acquirer credentials.
 *
 * Implementations MUST never return, log or persist plaintext outside of
 * {@see self::decrypt()}, and MUST fail loudly rather than falling back to
 * reading a value as plaintext.
 */
interface CredentialVaultInterface
{
    /**
     * Encrypt a plaintext credential value.
     */
    public function encrypt(string $plaintext): SecretValue;

    /**
     * Decrypt a previously encrypted payload.
     *
     * @throws \App\Domain\Routing\Exceptions\CredentialDecryptionException
     */
    public function decrypt(string $cipherText, int $keyVersion = 1): string;

    /**
     * Partial representation safe to render in the admin panel (e.g. "****cd12").
     */
    public function mask(string $plaintext): string;

    /**
     * Stable hash of a plaintext, used to detect "value unchanged" on update
     * without ever decrypting the stored payload.
     */
    public function fingerprint(string $plaintext): string;

    /**
     * Key version currently used for new writes.
     */
    public function currentKeyVersion(): int;
}
