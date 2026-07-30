<?php

declare(strict_types=1);

namespace App\Infrastructure\Routing\Persistence;

use App\Domain\Routing\Contracts\AcquirerCredentialRepositoryInterface;
use App\Domain\Routing\Contracts\CredentialVaultInterface;
use App\Domain\Routing\Exceptions\CredentialDecryptionException;
use App\Models\AcquirerConfig;
use App\Models\AcquirerCredential;
use App\Models\AcquirerEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Credential storage backed by the `acquirer_credentials` table.
 *
 * Every field lives in its own encrypted row, which lets us rotate one secret
 * without rewriting the others and lets the panel show per-field status.
 *
 * During migration this repository also reads the legacy blob in
 * `acquirer_configs.credentials`, so an acquirer that has not been migrated
 * yet keeps working. Legacy reads are logged so the remaining ones are visible.
 */
final class EloquentAcquirerCredentialRepository implements AcquirerCredentialRepositoryInterface
{
    /** Placeholder the panel sends back for untouched secrets. */
    private const MASK_PATTERN = '/^\*{2,}/';

    public function __construct(
        private readonly CredentialVaultInterface $vault,
    ) {
    }

    public function decrypted(AcquirerConfig $config): array
    {
        $rows = AcquirerCredential::query()
            ->where('acquirer_config_id', $config->id)
            ->get();

        if ($rows->isEmpty()) {
            return $this->readLegacyBlob($config);
        }

        $credentials = [];

        foreach ($rows as $row) {
            try {
                $credentials[$row->field_key] = $this->vault->decrypt($row->cipher_text, (int) $row->key_version);
            } catch (CredentialDecryptionException $e) {
                // Never fall back to reading the payload as plaintext: an
                // undecryptable secret means this acquirer must not be used.
                Log::error('Credencial da adquirente não pôde ser descriptografada', [
                    'acquirer_key' => $config->acquirer_key,
                    'field_key' => $row->field_key,
                    'key_version' => $row->key_version,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $credentials;
    }

    public function masked(AcquirerConfig $config): array
    {
        $stored = AcquirerCredential::query()
            ->where('acquirer_config_id', $config->id)
            ->get()
            ->keyBy('field_key');

        $masked = [];

        foreach (AcquirerConfig::getCredentialFields($config->acquirer_key) as $field) {
            $key = $field['key'];
            $row = $stored->get($key);

            $masked[$key] = array_merge(
                [
                    'field_key' => $key,
                    'label' => $field['label'] ?? $key,
                    'type' => $field['type'] ?? 'text',
                    'options' => $field['options'] ?? null,
                ],
                $row
                    ? $row->toMaskedArray()
                    : ['masked' => '', 'length' => 0, 'key_version' => null, 'configured' => false, 'rotated_at' => null, 'updated_at' => null]
            );
        }

        // Fields stored but no longer declared by the acquirer definition.
        foreach ($stored as $key => $row) {
            if (!isset($masked[$key])) {
                $masked[$key] = array_merge(
                    ['field_key' => $key, 'label' => $key, 'type' => 'password', 'options' => null, 'deprecated' => true],
                    $row->toMaskedArray()
                );
            }
        }

        return $masked;
    }

    public function store(AcquirerConfig $config, array $values): array
    {
        $written = [];

        foreach ($values as $fieldKey => $value) {
            $fieldKey = trim((string) $fieldKey);

            if ($fieldKey === '' || !$this->isRealValue($value)) {
                continue;
            }

            $plaintext = (string) $value;
            $existing = AcquirerCredential::query()
                ->where('acquirer_config_id', $config->id)
                ->where('field_key', $fieldKey)
                ->first();

            // Unchanged value: skip the write so rotated_at stays meaningful.
            if ($existing && $existing->fingerprint === $this->vault->fingerprint($plaintext)) {
                continue;
            }

            $secret = $this->vault->encrypt($plaintext);

            AcquirerCredential::query()->updateOrCreate(
                [
                    'acquirer_config_id' => $config->id,
                    'field_key' => $fieldKey,
                ],
                array_merge($secret->toAttributes(), [
                    'acquirer_key' => $config->acquirer_key,
                    'rotated_at' => now(),
                ])
            );

            $written[] = $fieldKey;
        }

        if ($written !== []) {
            $this->recordEvent($config, $written);
        }

        return $written;
    }

    public function forget(AcquirerConfig $config, string $fieldKey): void
    {
        AcquirerCredential::query()
            ->where('acquirer_config_id', $config->id)
            ->where('field_key', $fieldKey)
            ->delete();
    }

    public function purge(AcquirerConfig $config): void
    {
        AcquirerCredential::query()
            ->where('acquirer_config_id', $config->id)
            ->delete();
    }

    public function hasRequiredFields(AcquirerConfig $config): bool
    {
        $credentials = $this->decrypted($config);

        if ($credentials === []) {
            return false;
        }

        return $config->validateCredentialsByKey($config->acquirer_key, $credentials);
    }

    /**
     * A value is "real" when the admin actually typed something: not empty and
     * not the masked placeholder the panel renders for existing secrets.
     */
    private function isRealValue(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $trimmed = trim($value);

        return $trimmed !== '' && preg_match(self::MASK_PATTERN, $trimmed) !== 1;
    }

    /**
     * Read the legacy encrypted blob so acquirers keep working until the
     * migration command has moved them over.
     *
     * @return array<string, string>
     */
    private function readLegacyBlob(AcquirerConfig $config): array
    {
        try {
            $legacy = $config->getDecryptedCredentials();
        } catch (\Throwable $e) {
            Log::warning('Falha ao ler credenciais legadas da adquirente', [
                'acquirer_key' => $config->acquirer_key,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (!is_array($legacy) || $legacy === []) {
            return [];
        }

        Log::info('Adquirente ainda usando credenciais no formato legado', [
            'acquirer_key' => $config->acquirer_key,
            'hint' => 'Execute "php artisan acquirer:migrate-credentials" para migrar para o cofre AES-256-GCM.',
        ]);

        return array_map(
            static fn ($value): string => is_scalar($value) ? (string) $value : '',
            array_filter($legacy, static fn ($value): bool => is_scalar($value) && (string) $value !== '')
        );
    }

    /**
     * @param array<int, string> $fields
     */
    private function recordEvent(AcquirerConfig $config, array $fields): void
    {
        try {
            AcquirerEvent::create([
                'id' => (string) Str::uuid(),
                'acquirer_key' => $config->acquirer_key,
                'type' => AcquirerEvent::TYPE_CREDENTIAL_ROTATED,
                'severity' => 'info',
                'message' => sprintf('Credenciais atualizadas: %s', implode(', ', $fields)),
                // Field names only — values never reach the audit trail.
                'context' => ['fields' => $fields, 'key_version' => $this->vault->currentKeyVersion()],
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Falha ao registrar evento de rotação de credencial', ['error' => $e->getMessage()]);
        }
    }
}
