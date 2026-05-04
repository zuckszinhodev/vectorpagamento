<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AcquirerConfig;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class AcquirerRotationService
{
    public const SETTINGS_KEY = 'acquirer_rotation_settings';
    public const CURSORS_KEY = 'acquirer_rotation_cursors';

    private const AVAILABLE_METHODS = ['pix', 'credit_card', 'boleto', 'pix_out'];
    private const AVAILABLE_STRATEGIES = ['priority', 'round_robin', 'weighted'];

    public function getSettings(): array
    {
        $stored = $this->readJsonSetting(self::SETTINGS_KEY);
        $defaults = $this->defaultSettings();

        if (!is_array($stored)) {
            $this->persistJsonSetting(
                self::SETTINGS_KEY,
                $defaults,
                'acquirers',
                'Configuracoes de estrategia de rotacao de adquirentes por metodo de pagamento'
            );

            return $defaults;
        }

        $normalized = $this->normalizeSettings(array_replace_recursive($defaults, $stored));

        if ($normalized !== $stored) {
            $this->persistJsonSetting(
                self::SETTINGS_KEY,
                $normalized,
                'acquirers',
                'Configuracoes de estrategia de rotacao de adquirentes por metodo de pagamento'
            );
        }

        return $normalized;
    }

    public function updateSettings(array $settings): array
    {
        $current = $this->getSettings();
        $merged = array_replace_recursive($current, $settings);
        $normalized = $this->normalizeSettings($merged);

        $this->persistJsonSetting(
            self::SETTINGS_KEY,
            $normalized,
            'acquirers',
            'Configuracoes de estrategia de rotacao de adquirentes por metodo de pagamento'
        );

        return $normalized;
    }

    /**
     * Resolve ordered list of acquirers for a payment method.
     *
     * @param array<int, string> $candidates
     * @return array<int, string>
     */
    public function getOrderedAcquirers(string $paymentMethod, array $candidates, ?string $preferredPrimary = null): array
    {
        return $this->resolveOrder($paymentMethod, $candidates, $preferredPrimary, false);
    }

    /**
     * Select primary acquirer for a payment method.
     * Advances cursor for round-robin / weighted strategies.
     *
     * @param array<int, string> $candidates
     */
    public function choosePrimaryAcquirer(string $paymentMethod, array $candidates): ?string
    {
        $order = $this->resolveOrder($paymentMethod, $candidates, null, true);

        return $order[0] ?? null;
    }

    /**
     * Peek primary acquirer without advancing any cursor.
     *
     * @param array<int, string> $candidates
     */
    public function peekPrimaryAcquirer(string $paymentMethod, array $candidates): ?string
    {
        $order = $this->resolveOrder($paymentMethod, $candidates, null, false);

        return $order[0] ?? null;
    }

    /**
     * @param array<int, string> $candidates
     * @return array<string, mixed>
     */
    public function buildPreview(string $paymentMethod, array $candidates): array
    {
        $method = $this->normalizeMethod($paymentMethod);
        $settings = $this->getSettings();
        $methodSettings = $settings['methods'][$method] ?? ['strategy' => 'priority', 'weights' => []];

        $normalizedCandidates = $this->normalizeCandidates($candidates);
        $eligible = $this->applyAvailabilityFilters($method, $normalizedCandidates, $settings);
        $order = $this->resolveOrder($method, $normalizedCandidates, null, false);

        return [
            'strategy' => $methodSettings['strategy'] ?? 'priority',
            'candidates' => $normalizedCandidates,
            'eligible' => $eligible,
            'order' => $order,
            'primary' => $order[0] ?? null,
        ];
    }

    public function markFailure(string $paymentMethod, string $acquirerKey): void
    {
        $settings = $this->getSettings();
        if (!($settings['enabled'] ?? true) || !($settings['use_cooldown'] ?? true)) {
            return;
        }

        $cooldownSeconds = (int) ($settings['cooldown_seconds'] ?? 90);
        if ($cooldownSeconds <= 0) {
            return;
        }

        Cache::put(
            $this->cooldownCacheKey($this->normalizeMethod($paymentMethod), strtolower($acquirerKey)),
            true,
            now()->addSeconds($cooldownSeconds)
        );
    }

    public function markSuccess(string $paymentMethod, string $acquirerKey): void
    {
        Cache::forget($this->cooldownCacheKey($this->normalizeMethod($paymentMethod), strtolower($acquirerKey)));
    }

    public function isOnCooldown(string $paymentMethod, string $acquirerKey): bool
    {
        return Cache::has($this->cooldownCacheKey($this->normalizeMethod($paymentMethod), strtolower($acquirerKey)));
    }

    /**
     * @param array<int, string> $candidates
     * @return array<int, string>
     */
    private function resolveOrder(string $paymentMethod, array $candidates, ?string $preferredPrimary, bool $advanceCursor): array
    {
        $method = $this->normalizeMethod($paymentMethod);
        $preferredPrimary = $preferredPrimary ? strtolower($preferredPrimary) : null;
        $normalizedCandidates = $this->normalizeCandidates($candidates);

        if ($normalizedCandidates === []) {
            return [];
        }

        $settings = $this->getSettings();
        $methodSettings = $settings['methods'][$method] ?? ['strategy' => 'priority', 'weights' => []];

        if (!($settings['enabled'] ?? true)) {
            return $this->moveToFront($normalizedCandidates, $preferredPrimary);
        }

        $eligible = $this->applyAvailabilityFilters($method, $normalizedCandidates, $settings);
        if ($eligible === []) {
            $eligible = $normalizedCandidates;
        }

        $strategy = $methodSettings['strategy'] ?? 'priority';

        if ($strategy === 'priority') {
            return $this->moveToFront($eligible, $preferredPrimary);
        }

        if ($strategy === 'round_robin') {
            $primary = $preferredPrimary;
            if ($primary === null || !in_array($primary, $eligible, true)) {
                $primary = $this->resolveRoundRobinPrimary($method, $eligible, $advanceCursor);
            }

            return $this->moveToFront($eligible, $primary);
        }

        $weights = is_array($methodSettings['weights'] ?? null) ? $methodSettings['weights'] : [];
        $orderedByWeight = $this->sortByWeight($eligible, $weights);

        $primary = $preferredPrimary;
        if ($primary === null || !in_array($primary, $orderedByWeight, true)) {
            $primary = $this->resolveWeightedPrimary($method, $orderedByWeight, $weights, $advanceCursor);
        }

        return $this->moveToFront($orderedByWeight, $primary);
    }

    /**
     * @param array<int, string> $candidates
     * @return array<int, string>
     */
    private function applyAvailabilityFilters(string $method, array $candidates, array $settings): array
    {
        $strictHealthCheck = (bool) ($settings['strict_health_check'] ?? true);
        $useCooldown = (bool) ($settings['use_cooldown'] ?? true);

        if (!$strictHealthCheck && !$useCooldown) {
            return $candidates;
        }

        $configs = AcquirerConfig::whereIn('acquirer_key', $candidates)
            ->get(['acquirer_key', 'settings'])
            ->keyBy('acquirer_key');

        $filtered = [];

        foreach ($candidates as $key) {
            if ($strictHealthCheck) {
                $config = $configs->get($key);
                $settings = is_array($config?->settings) ? $config->settings : [];
                $healthStatus = strtolower((string) ($settings['health_status'] ?? 'unknown'));
                if (in_array($healthStatus, ['down', 'unhealthy', 'offline'], true)) {
                    continue;
                }
            }

            if ($useCooldown && $this->isOnCooldown($method, $key)) {
                continue;
            }

            $filtered[] = $key;
        }

        return $filtered;
    }

    /**
     * @param array<int, string> $candidates
     */
    private function resolveRoundRobinPrimary(string $method, array $candidates, bool $advanceCursor): ?string
    {
        $total = count($candidates);
        if ($total === 0) {
            return null;
        }

        $cursor = $this->getCursor($method);
        $index = $cursor % $total;
        $primary = $candidates[$index] ?? $candidates[0];

        if ($advanceCursor) {
            $this->setCursor($method, ($index + 1) % $total);
        }

        return $primary;
    }

    /**
     * @param array<int, string> $orderedCandidates
     * @param array<string, mixed> $weights
     */
    private function resolveWeightedPrimary(string $method, array $orderedCandidates, array $weights, bool $advanceCursor): ?string
    {
        if ($orderedCandidates === []) {
            return null;
        }

        $pool = [];
        foreach ($orderedCandidates as $key) {
            $weight = (int) ($weights[$key] ?? 1);
            $weight = max(1, min($weight, 20));

            for ($i = 0; $i < $weight; $i++) {
                $pool[] = $key;
            }
        }

        if ($pool === []) {
            return $orderedCandidates[0];
        }

        $cursor = $this->getCursor($method);
        $index = $cursor % count($pool);
        $selected = $pool[$index] ?? $orderedCandidates[0];

        if ($advanceCursor) {
            $this->setCursor($method, ($index + 1) % count($pool));
        }

        return $selected;
    }

    /**
     * @param array<int, string> $candidates
     * @param array<string, mixed> $weights
     * @return array<int, string>
     */
    private function sortByWeight(array $candidates, array $weights): array
    {
        $indexed = [];
        foreach ($candidates as $index => $key) {
            $indexed[] = [
                'key' => $key,
                'weight' => (float) ($weights[$key] ?? 1),
                'index' => $index,
            ];
        }

        usort($indexed, function (array $a, array $b): int {
            if ($a['weight'] === $b['weight']) {
                return $a['index'] <=> $b['index'];
            }

            return $b['weight'] <=> $a['weight'];
        });

        return array_values(array_map(fn (array $item): string => $item['key'], $indexed));
    }

    /**
     * @param array<int, string> $candidates
     * @return array<int, string>
     */
    private function moveToFront(array $candidates, ?string $primary): array
    {
        if ($primary === null || !in_array($primary, $candidates, true)) {
            return $candidates;
        }

        $ordered = array_values(array_diff($candidates, [$primary]));
        array_unshift($ordered, $primary);

        return $ordered;
    }

    /**
     * @param array<int, string> $candidates
     * @return array<int, string>
     */
    private function normalizeCandidates(array $candidates): array
    {
        $normalized = [];

        foreach ($candidates as $candidate) {
            $value = strtolower(trim((string) $candidate));
            if ($value === '') {
                continue;
            }

            if (!in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    private function normalizeMethod(string $method): string
    {
        $normalized = strtolower(trim($method));

        $normalized = match ($normalized) {
            'card' => 'credit_card',
            'pixout', 'pix-out', 'withdrawal', 'withdraw' => 'pix_out',
            default => $normalized !== '' ? $normalized : 'pix',
        };

        return in_array($normalized, self::AVAILABLE_METHODS, true) ? $normalized : 'pix';
    }

    private function getCursor(string $method): int
    {
        $cursors = $this->readJsonSetting(self::CURSORS_KEY);
        if (!is_array($cursors)) {
            return 0;
        }

        return max(0, (int) ($cursors[$method] ?? 0));
    }

    private function setCursor(string $method, int $cursor): void
    {
        $current = $this->readJsonSetting(self::CURSORS_KEY);
        $current = is_array($current) ? $current : [];
        $current[$method] = max(0, $cursor);

        $this->persistJsonSetting(
            self::CURSORS_KEY,
            $current,
            'acquirers',
            'Cursores das estrategias de rotacao round-robin/weighted por metodo'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonSetting(string $key): ?array
    {
        $row = SystemSetting::query()->where('key', $key)->first();
        if (!$row) {
            return null;
        }

        $decoded = json_decode((string) $row->value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function persistJsonSetting(string $key, array $value, string $group, string $description): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
                'type' => 'json',
                'group' => $group,
                'description' => $description,
            ]
        );

        Cache::forget("system_setting_{$key}");
    }

    private function cooldownCacheKey(string $method, string $acquirerKey): string
    {
        return sprintf('acquirer_rotation:cooldown:%s:%s', $method, $acquirerKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultSettings(): array
    {
        return [
            'enabled' => true,
            'failover_enabled' => true,
            'strict_health_check' => true,
            'use_cooldown' => true,
            'cooldown_seconds' => 90,
            'methods' => [
                'pix' => ['strategy' => 'priority', 'weights' => []],
                'credit_card' => ['strategy' => 'priority', 'weights' => []],
                'boleto' => ['strategy' => 'priority', 'weights' => []],
                'pix_out' => ['strategy' => 'priority', 'weights' => []],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function normalizeSettings(array $settings): array
    {
        $normalized = $this->defaultSettings();

        $normalized['enabled'] = (bool) ($settings['enabled'] ?? $normalized['enabled']);
        $normalized['failover_enabled'] = (bool) ($settings['failover_enabled'] ?? $normalized['failover_enabled']);
        $normalized['strict_health_check'] = (bool) ($settings['strict_health_check'] ?? $normalized['strict_health_check']);
        $normalized['use_cooldown'] = (bool) ($settings['use_cooldown'] ?? $normalized['use_cooldown']);
        $normalized['cooldown_seconds'] = max(5, min(3600, (int) ($settings['cooldown_seconds'] ?? $normalized['cooldown_seconds'])));

        $methods = is_array($settings['methods'] ?? null) ? $settings['methods'] : [];

        foreach (self::AVAILABLE_METHODS as $method) {
            $methodSettings = is_array($methods[$method] ?? null) ? $methods[$method] : [];
            $strategy = (string) ($methodSettings['strategy'] ?? $normalized['methods'][$method]['strategy']);
            if (!in_array($strategy, self::AVAILABLE_STRATEGIES, true)) {
                $strategy = 'priority';
            }

            $weightsRaw = is_array($methodSettings['weights'] ?? null) ? $methodSettings['weights'] : [];
            $weights = [];
            foreach ($weightsRaw as $acquirerKey => $weight) {
                $normalizedKey = strtolower(trim((string) $acquirerKey));
                if ($normalizedKey === '') {
                    continue;
                }

                $weights[$normalizedKey] = max(0, (float) $weight);
            }

            $normalized['methods'][$method] = [
                'strategy' => $strategy,
                'weights' => $weights,
            ];
        }

        return $normalized;
    }
}
