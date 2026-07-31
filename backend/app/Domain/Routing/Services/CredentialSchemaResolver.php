<?php

declare(strict_types=1);

namespace App\Domain\Routing\Services;

use App\Models\AcquirerConfig;

/**
 * Resolves which credential fields an acquirer needs, and validates them.
 *
 * Resolution order:
 *   1. `acquirer_configs.credential_schema` — defined from the admin panel;
 *   2. the legacy hard-coded definitions in AcquirerConfig, kept so the
 *      thirteen existing acquirers keep their curated labels;
 *   3. a generic single-token schema, so an acquirer registered from the panel
 *      always has somewhere to type its credential instead of rendering an
 *      empty form.
 *
 * Validation follows the same order, which is what allows a brand-new acquirer
 * to be activated at all — the legacy `validateCredentialsByKey()` returned
 * false for any key it did not know about.
 */
final class CredentialSchemaResolver
{
    /**
     * Fallback used when neither the panel nor the legacy map defines fields.
     *
     * @var array<int, array<string, mixed>>
     */
    private const GENERIC_SCHEMA = [
        ['key' => 'api_key', 'label' => 'Token de Acesso / API Key', 'type' => 'password', 'required' => true],
        ['key' => 'base_url', 'label' => 'URL da API', 'type' => 'text', 'required' => false],
        ['key' => 'webhook_secret', 'label' => 'Webhook Secret', 'type' => 'password', 'required' => false],
    ];

    /**
     * Normalised field definitions for an acquirer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fieldsFor(AcquirerConfig $config): array
    {
        $custom = $this->normalise($config->credential_schema ?? null);

        if ($custom !== []) {
            return $custom;
        }

        $legacy = $this->normalise(AcquirerConfig::getCredentialFields($config->acquirer_key));

        if ($legacy !== []) {
            return $legacy;
        }

        return $this->normalise(self::GENERIC_SCHEMA);
    }

    /**
     * Keys that must be present for the acquirer to be usable.
     *
     * @return array<int, string>
     */
    public function requiredKeys(AcquirerConfig $config): array
    {
        return array_values(array_map(
            static fn (array $field): string => $field['key'],
            array_filter($this->fieldsFor($config), static fn (array $field): bool => (bool) $field['required'])
        ));
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public function validate(AcquirerConfig $config, array $credentials): bool
    {
        // A panel-defined schema is authoritative: the operator declared what
        // this acquirer needs, so the legacy map must not override it.
        if ($this->normalise($config->credential_schema ?? null) !== []) {
            return $this->hasAll($this->requiredKeys($config), $credentials);
        }

        // Known acquirer: keep the curated rules (some require field pairs).
        if ($this->normalise(AcquirerConfig::getCredentialFields($config->acquirer_key)) !== []) {
            return $config->validateCredentialsByKey($config->acquirer_key, $credentials);
        }

        return $this->hasAll($this->requiredKeys($config), $credentials);
    }

    /**
     * Field definitions accepted from the admin panel, sanitised.
     *
     * @param mixed $input
     * @return array<int, array<string, mixed>>
     */
    public function sanitiseInput(mixed $input): array
    {
        return $this->normalise($input);
    }

    /**
     * @param array<int, string> $required
     * @param array<string, mixed> $credentials
     */
    private function hasAll(array $required, array $credentials): bool
    {
        if ($required === []) {
            // Nothing declared as required: any credential at all counts as
            // configured, but an empty set never does.
            return array_filter($credentials, static fn ($v): bool => (string) $v !== '') !== [];
        }

        foreach ($required as $key) {
            if (($credentials[$key] ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $input
     * @return array<int, array<string, mixed>>
     */
    private function normalise(mixed $input): array
    {
        if (!is_array($input) || $input === []) {
            return [];
        }

        $fields = [];
        $seen = [];

        foreach ($input as $field) {
            if (!is_array($field)) {
                continue;
            }

            // The legacy map uses 'key'; the panel may send 'field_key'.
            $key = strtolower(trim((string) ($field['key'] ?? $field['field_key'] ?? '')));
            $key = preg_replace('/[^a-z0-9_]/', '', $key) ?? '';

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $type = strtolower(trim((string) ($field['type'] ?? 'password')));
            if (!in_array($type, ['text', 'password', 'select', 'textarea'], true)) {
                $type = 'password';
            }

            $options = null;
            if ($type === 'select' && is_array($field['options'] ?? null)) {
                $options = array_values(array_filter(array_map(
                    static fn ($o): string => trim((string) $o),
                    $field['options']
                ), static fn (string $o): bool => $o !== ''));
            }

            $fields[] = [
                'key' => $key,
                'label' => trim((string) ($field['label'] ?? $key)) ?: $key,
                'type' => $type,
                // Legacy definitions carry no 'required' flag; treat every
                // declared field as required there, matching the old checks
                // that demanded the primary credentials be present.
                'required' => (bool) ($field['required'] ?? !$this->isOptionalByConvention($key)),
                'options' => $options,
            ];
        }

        return $fields;
    }

    /**
     * Fields that are conventionally optional across the integrated PSPs.
     */
    private function isOptionalByConvention(string $key): bool
    {
        return in_array($key, [
            'base_url',
            'webhook_url',
            'webhook_secret',
            'transfer_webhook_url',
            'transfer_webhook_secret',
            'environment',
        ], true);
    }
}
