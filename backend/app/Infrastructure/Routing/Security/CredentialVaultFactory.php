<?php

declare(strict_types=1);

namespace App\Infrastructure\Routing\Security;

use App\Domain\Routing\Contracts\CredentialVaultInterface;
use Illuminate\Support\Facades\Log;

/**
 * Builds the credential vault from configuration, resolving the current key
 * plus any previous versions kept around for rotation.
 */
final class CredentialVaultFactory
{
    public static function make(): CredentialVaultInterface
    {
        $config = (array) config('acquirer_routing.encryption', []);
        $currentVersion = max(1, (int) ($config['key_version'] ?? 1));

        $keys = [];

        $currentKey = self::decodeKey($config['key'] ?? null);

        if ($currentKey === null) {
            if (!($config['allow_app_key_fallback'] ?? true)) {
                throw new \RuntimeException(
                    'ACQUIRER_ENCRYPTION_KEY não configurada e o fallback para APP_KEY está desabilitado.'
                );
            }

            $currentKey = self::deriveFromAppKey($currentVersion);

            Log::warning(
                'ACQUIRER_ENCRYPTION_KEY ausente: derivando chave do APP_KEY. '
                . 'Configure uma chave dedicada com "php artisan acquirer:generate-key" antes de ir para produção.'
            );
        }

        $keys[$currentVersion] = $currentKey;

        foreach (self::decodePreviousKeys($config['previous_keys'] ?? null) as $version => $key) {
            if ($version === $currentVersion) {
                continue;
            }

            $keys[$version] = $key;
        }

        // Payloads written before a dedicated key existed still decrypt.
        if (!isset($keys[1])) {
            $keys[1] = self::deriveFromAppKey(1);
        }

        return new AesGcmCredentialVault($keys, $currentVersion);
    }

    private static function decodeKey(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (str_starts_with($value, 'base64:')) {
            $value = substr($value, 7);
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false || strlen($decoded) !== 32) {
            throw new \RuntimeException(
                'ACQUIRER_ENCRYPTION_KEY inválida: deve ser base64 de exatamente 32 bytes (AES-256).'
            );
        }

        return $decoded;
    }

    /**
     * @return array<int, string>
     */
    private static function decodePreviousKeys(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            Log::warning('ACQUIRER_ENCRYPTION_KEYS_PREVIOUS não é um JSON válido; ignorando.');
            return [];
        }

        $keys = [];

        foreach ($decoded as $version => $key) {
            try {
                $resolved = self::decodeKey($key);
            } catch (\RuntimeException $e) {
                Log::warning('Chave de criptografia anterior inválida; ignorando.', [
                    'version' => $version,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($resolved !== null) {
                $keys[(int) $version] = $resolved;
            }
        }

        return $keys;
    }

    /**
     * HKDF from APP_KEY with a version-specific info string, so each version
     * gets a distinct derived key.
     */
    private static function deriveFromAppKey(int $version): string
    {
        $appKey = (string) config('app.key', '');

        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        if ($appKey === '') {
            throw new \RuntimeException(
                'Nem ACQUIRER_ENCRYPTION_KEY nem APP_KEY estão configuradas — impossível criptografar credenciais.'
            );
        }

        return hash_hkdf('sha256', $appKey, 32, "acquirer-credentials-v{$version}", '');
    }
}
