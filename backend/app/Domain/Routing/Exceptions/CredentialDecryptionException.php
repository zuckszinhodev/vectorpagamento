<?php

declare(strict_types=1);

namespace App\Domain\Routing\Exceptions;

/**
 * Raised when a stored credential cannot be decrypted.
 *
 * There is intentionally no "maybe it is plaintext" fallback: a payload that
 * fails to decrypt is treated as corrupt so the acquirer is skipped instead of
 * silently running with a bad or leaked secret.
 */
final class CredentialDecryptionException extends RoutingException
{
    public function __construct(string $message, array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 'credential_decryption_failed', $context, $previous);
    }
}
