<?php

declare(strict_types=1);

namespace App\Infrastructure\Routing\Security;

use App\Domain\Routing\Contracts\CredentialVaultInterface;
use App\Domain\Routing\Exceptions\CredentialDecryptionException;
use App\Domain\Routing\ValueObjects\SecretValue;

/**
 * AES-256-GCM credential vault.
 *
 * GCM is authenticated encryption: a tampered ciphertext fails to decrypt
 * instead of yielding garbage, which is what lets us drop the old
 * "try plaintext as a fallback" behaviour entirely.
 *
 * Key material comes from ACQUIRER_ENCRYPTION_KEY (base64, 32 bytes).
 * Older keys stay readable through ACQUIRER_ENCRYPTION_KEYS_PREVIOUS, a JSON
 * map of {"version": "base64key"}, so keys can be rotated without a downtime
 * window: new writes use the current version, old rows decrypt with theirs.
 *
 * Stored format:  v<version>.<base64 iv>.<base64 tag>.<base64 ciphertext>
 */
final class AesGcmCredentialVault implements CredentialVaultInterface
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const KEY_LENGTH = 32;

    /** @var array<int, string> version => raw 32-byte key */
    private array $keys;

    private int $currentVersion;

    /**
     * @param array<int, string> $keys version => raw key (32 bytes)
     */
    public function __construct(array $keys, int $currentVersion)
    {
        if ($keys === [] || !isset($keys[$currentVersion])) {
            throw new \InvalidArgumentException('AesGcmCredentialVault requires a key for the current version.');
        }

        foreach ($keys as $version => $key) {
            if (strlen($key) !== self::KEY_LENGTH) {
                throw new \InvalidArgumentException(
                    "Chave de criptografia versão {$version} inválida: são necessários 32 bytes (AES-256)."
                );
            }
        }

        $this->keys = $keys;
        $this->currentVersion = $currentVersion;
    }

    public function encrypt(string $plaintext): SecretValue
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $cipherText = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->keys[$this->currentVersion],
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($cipherText === false) {
            throw new CredentialDecryptionException('Falha ao criptografar a credencial.');
        }

        $payload = sprintf(
            'v%d.%s.%s.%s',
            $this->currentVersion,
            base64_encode($iv),
            base64_encode($tag),
            base64_encode($cipherText)
        );

        return new SecretValue(
            cipherText: $payload,
            algorithm: self::CIPHER,
            keyVersion: $this->currentVersion,
            maskHint: $this->mask($plaintext),
            valueLength: strlen($plaintext),
            fingerprint: $this->fingerprint($plaintext),
        );
    }

    public function decrypt(string $cipherText, int $keyVersion = 1): string
    {
        $parts = explode('.', $cipherText);

        if (count($parts) !== 4 || !str_starts_with($parts[0], 'v')) {
            throw new CredentialDecryptionException(
                'Formato de credencial criptografada inválido.',
                ['expected' => 'v<version>.<iv>.<tag>.<ciphertext>']
            );
        }

        $version = (int) substr($parts[0], 1);
        $key = $this->keys[$version] ?? $this->keys[$keyVersion] ?? null;

        if ($key === null) {
            throw new CredentialDecryptionException(
                "Chave de criptografia versão {$version} não está disponível nesta instância.",
                ['key_version' => $version]
            );
        }

        $iv = base64_decode($parts[1], true);
        $tag = base64_decode($parts[2], true);
        $raw = base64_decode($parts[3], true);

        if ($iv === false || $tag === false || $raw === false) {
            throw new CredentialDecryptionException('Credencial criptografada corrompida (base64 inválido).');
        }

        $plaintext = openssl_decrypt($raw, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plaintext === false) {
            // Authentication tag mismatch: wrong key or tampered payload.
            throw new CredentialDecryptionException(
                'Não foi possível descriptografar a credencial (chave incorreta ou dado adulterado).',
                ['key_version' => $version]
            );
        }

        return $plaintext;
    }

    public function mask(string $plaintext): string
    {
        $length = strlen($plaintext);

        if ($length === 0) {
            return '';
        }

        // Short secrets reveal nothing at all.
        if ($length <= 6) {
            return str_repeat('*', $length);
        }

        return '****' . substr($plaintext, -4);
    }

    public function fingerprint(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function currentKeyVersion(): int
    {
        return $this->currentVersion;
    }
}
