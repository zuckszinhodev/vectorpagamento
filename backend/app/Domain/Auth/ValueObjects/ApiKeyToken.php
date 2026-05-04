<?php

declare(strict_types=1);

namespace Domain\Auth\ValueObjects;

use InvalidArgumentException;

final class ApiKeyToken
{
    private const PREFIX = 'wzp';
    private const KEY_LENGTH = 32;
    private const SECRET_LENGTH = 64;

    private string $key;
    private string $secret;
    private string $keyHash;
    private string $secretHash;

    private function __construct(
        string $key,
        string $secret,
        string $keyHash,
        string $secretHash
    ) {
        $this->key = $key;
        $this->secret = $secret;
        $this->keyHash = $keyHash;
        $this->secretHash = $secretHash;
    }

    public static function generate(): self
    {
        $key = self::PREFIX . '_' . self::generateSecureToken(self::KEY_LENGTH);
        $secret = self::generateSecureToken(self::SECRET_LENGTH);

        return new self(
            $key,
            $secret,
            hash('sha256', $key),
            hash('sha256', $secret)
        );
    }

    public static function fromHashes(string $keyHash, string $secretHash): self
    {
        return new self('', '', $keyHash, $secretHash);
    }

    private static function generateSecureToken(int $length): string
    {
        $bytes = random_bytes($length);
        return substr(bin2hex($bytes), 0, $length);
    }

    public function getKey(): string
    {
        if (empty($this->key)) {
            throw new InvalidArgumentException('Key is not available (loaded from hash)');
        }
        return $this->key;
    }

    public function getSecret(): string
    {
        if (empty($this->secret)) {
            throw new InvalidArgumentException('Secret is not available (loaded from hash)');
        }
        return $this->secret;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function getSecretHash(): string
    {
        return $this->secretHash;
    }

    public function verifyKey(string $key): bool
    {
        return hash_equals($this->keyHash, hash('sha256', $key));
    }

    public function verifySecret(string $secret): bool
    {
        return hash_equals($this->secretHash, hash('sha256', $secret));
    }

    public function verify(string $key, string $secret): bool
    {
        return $this->verifyKey($key) && $this->verifySecret($secret);
    }

    public function getDisplayKey(): string
    {
        if (empty($this->key)) {
            return substr($this->keyHash, 0, 8) . '...';
        }

        return substr($this->key, 0, 12) . '...' . substr($this->key, -4);
    }

    public static function isValidKeyFormat(string $key): bool
    {
        return preg_match('/^' . self::PREFIX . '_[a-f0-9]{' . self::KEY_LENGTH . '}$/', $key) === 1;
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key ?: null,
            'secret' => $this->secret ?: null,
            'key_hash' => $this->keyHash,
        ];
    }
}
