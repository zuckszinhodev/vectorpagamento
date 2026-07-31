<?php

declare(strict_types=1);

namespace App\Infrastructure\Routing\Providers;

use App\Domain\Routing\Contracts\AcquirerCredentialRepositoryInterface;
use App\Domain\Routing\Contracts\PaymentProviderInterface;
use App\Domain\Routing\Contracts\ProviderRegistryInterface;
use App\Domain\Routing\Enums\PaymentMethod;
use App\Models\AccountAcquirer;
use App\Models\AcquirerConfig;
use Illuminate\Support\Facades\Log;

/**
 * Resolves acquirer keys to ready-to-use provider instances.
 *
 * Two registration paths:
 *
 *   - explicit: {@see self::register()} from a service provider, for acquirers
 *     that implement PaymentProviderInterface natively;
 *   - conventional: the `acquirer_routing.providers` config map, for the
 *     existing classes, wrapped by {@see LegacyAcquirerProvider}.
 *
 * Either way the routing core never learns an acquirer's name. This replaces
 * AcquirerFactory's typed property + boolean flag + `match` per acquirer,
 * where every new integration meant editing four places in a 1200-line class.
 *
 * Instances are memoised per (acquirer, account) because several legacy
 * classes cache an auth token on the instance — reusing one across accounts
 * would leak another establishment's session.
 */
final class ProviderRegistry implements ProviderRegistryInterface
{
    /** @var array<string, callable(string, array<string, string>): PaymentProviderInterface> */
    private array $factories = [];

    /** @var array<string, array<int, PaymentMethod>> */
    private array $declaredSupport = [];

    /** @var array<string, string> */
    private array $displayNames = [];

    /** @var array<string, PaymentProviderInterface|false> */
    private array $instances = [];

    public function __construct(
        private readonly AcquirerCredentialRepositoryInterface $credentials,
        private readonly \App\Domain\Routing\Services\CredentialSchemaResolver $schema,
    ) {
    }

    public function register(string $acquirerKey, callable $factory, array $supports = [], ?string $displayName = null): void
    {
        $key = strtolower(trim($acquirerKey));

        $this->factories[$key] = $factory;

        if ($supports !== []) {
            $this->declaredSupport[$key] = $supports;
        }

        if ($displayName !== null) {
            $this->displayNames[$key] = $displayName;
        }

        unset($this->instances[$key]);
    }

    public function resolve(string $acquirerKey, ?string $accountId = null): ?PaymentProviderInterface
    {
        $key = strtolower(trim($acquirerKey));
        $cacheKey = $key . '|' . ($accountId ?? 'global');

        if (array_key_exists($cacheKey, $this->instances)) {
            return $this->instances[$cacheKey] ?: null;
        }

        $provider = $this->build($key, $accountId);

        // `false` memoises a known failure so we do not retry a DB lookup and
        // a decrypt for every candidate evaluation in the same request.
        $this->instances[$cacheKey] = $provider ?? false;

        return $provider;
    }

    public function has(string $acquirerKey): bool
    {
        $key = strtolower(trim($acquirerKey));

        return isset($this->factories[$key]) || $this->legacyClassFor($key) !== null;
    }

    public function registeredKeys(): array
    {
        $configured = array_keys((array) config('acquirer_routing.providers', []));

        return array_values(array_unique(array_merge(array_keys($this->factories), $configured)));
    }

    public function flush(?string $acquirerKey = null): void
    {
        if ($acquirerKey === null) {
            $this->instances = [];

            return;
        }

        $prefix = strtolower(trim($acquirerKey)) . '|';

        foreach (array_keys($this->instances) as $cacheKey) {
            if (str_starts_with($cacheKey, $prefix)) {
                unset($this->instances[$cacheKey]);
            }
        }
    }

    private function build(string $key, ?string $accountId): ?PaymentProviderInterface
    {
        $config = $this->configFor($key);

        if ($config === null) {
            Log::debug('Adquirente não cadastrada em acquirer_configs', ['acquirer' => $key]);

            return null;
        }

        if (!$config->enabled) {
            return null;
        }

        $credentials = $this->credentialsFor($config, $accountId);

        try {
            // An explicitly registered module owns its own credential handling:
            // it may authenticate through mTLS, an SDK or an injected client,
            // so the vault contents are offered but never demanded.
            if (isset($this->factories[$key])) {
                return ($this->factories[$key])($key, $credentials);
            }

            // The legacy adapter builds classes that take a credentials array,
            // so those fields really are mandatory.
            if ($credentials === []) {
                Log::info('Adquirente sem credenciais utilizáveis; fora do roteamento', [
                    'acquirer' => $key,
                    'account_id' => $accountId,
                ]);

                return null;
            }

            if (!$this->schema->validate($config, $credentials)) {
                Log::warning('Credenciais da adquirente incompletas; fora do roteamento', [
                    'acquirer' => $key,
                    'account_id' => $accountId,
                    'fields_present' => array_keys($credentials),
                ]);

                return null;
            }

            return $this->buildLegacy($key, $config, $credentials);
        } catch (\Throwable $e) {
            Log::error('Falha ao instanciar módulo da adquirente', [
                'acquirer' => $key,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, string> $credentials
     */
    private function buildLegacy(string $key, AcquirerConfig $config, array $credentials): ?PaymentProviderInterface
    {
        $class = $this->legacyClassFor($key);

        if ($class === null) {
            Log::warning('Nenhum módulo mapeado para a adquirente', [
                'acquirer' => $key,
                'hint' => 'Adicione a classe em config/acquirer_routing.php → providers',
            ]);

            return null;
        }

        return new LegacyAcquirerProvider(
            acquirerKey: $key,
            displayName: $this->displayNames[$key] ?? (string) ($config->display_name ?? $key),
            acquirer: new $class($credentials),
            supports: $this->supportedMethods($key, $config),
        );
    }

    /**
     * Supported methods come from the database column the admin panel edits,
     * so enabling PIX OUT for an acquirer is a checkbox, not a deploy.
     *
     * @return array<int, PaymentMethod>
     */
    private function supportedMethods(string $key, AcquirerConfig $config): array
    {
        $declared = $config->supported_methods;

        if (!is_array($declared) || $declared === []) {
            return $this->declaredSupport[$key] ?? PaymentMethod::cases();
        }

        $methods = [];

        foreach ($declared as $value) {
            $method = PaymentMethod::normalize((string) $value);

            if (!in_array($method, $methods, true)) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    private function legacyClassFor(string $key): ?string
    {
        $map = (array) config('acquirer_routing.providers', []);
        $class = $map[$key] ?? null;

        if (is_string($class) && class_exists($class)) {
            return $class;
        }

        // Sub-accounts are stored as `sharkhubsub_<slug>`; they share the
        // parent module and differ only in credentials.
        $normalized = AcquirerConfig::normalizeAcquirerKey($key);

        if ($normalized !== $key) {
            $class = $map[$normalized] ?? null;

            if (is_string($class) && class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    private function configFor(string $key): ?AcquirerConfig
    {
        $config = AcquirerConfig::query()->where('acquirer_key', $key)->first();

        if ($config !== null) {
            return $config;
        }

        $normalized = AcquirerConfig::normalizeAcquirerKey($key);

        return $normalized !== $key
            ? AcquirerConfig::query()->where('acquirer_key', $normalized)->first()
            : null;
    }

    /**
     * Establishment-specific credentials win over the global ones, so a seller
     * with its own acquirer contract uses its own keys.
     *
     * @return array<string, string>
     */
    private function credentialsFor(AcquirerConfig $config, ?string $accountId): array
    {
        if ($accountId !== null) {
            $accountCredentials = $this->accountCredentials($accountId, $config);

            if ($accountCredentials !== []) {
                return $accountCredentials;
            }
        }

        return $this->credentials->decrypted($config);
    }

    /**
     * @return array<string, string>
     */
    private function accountCredentials(string $accountId, AcquirerConfig $config): array
    {
        try {
            $assignment = AccountAcquirer::query()
                ->where('account_acquirers.account_id', $accountId)
                ->where('account_acquirers.is_active', true)
                ->join('acquirers', 'acquirers.id', '=', 'account_acquirers.acquirer_id')
                ->where('acquirers.slug', $config->acquirer_key)
                ->select('account_acquirers.*')
                ->orderBy('account_acquirers.priority')
                ->first();

            if (!$assignment) {
                return [];
            }

            $credentials = $assignment->getDecryptedCredentials();

            if (!is_array($credentials)) {
                return [];
            }

            return array_map(
                static fn ($value): string => is_scalar($value) ? (string) $value : '',
                array_filter($credentials, static fn ($value): bool => is_scalar($value) && (string) $value !== '')
            );
        } catch (\Throwable $e) {
            Log::warning('Falha ao carregar credenciais específicas do estabelecimento', [
                'acquirer' => $config->acquirer_key,
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
