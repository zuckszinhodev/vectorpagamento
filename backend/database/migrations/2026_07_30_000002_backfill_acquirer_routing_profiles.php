<?php

use App\Domain\Routing\Enums\CircuitState;
use App\Domain\Routing\Enums\HealthState;
use App\Domain\Routing\Enums\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Gives every existing acquirer a routing profile, a health row and a default
 * balancing policy, so the new engine has complete data from the first request
 * instead of falling back to config defaults for everything.
 *
 * Priorities are carried over from acquirer_configs, and any health status
 * previously stashed in acquirer_configs.settings is migrated across.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('acquirer_configs') || !Schema::hasTable('acquirer_routing_profiles')) {
            return;
        }

        $defaults = (array) config('acquirer_routing.profile_defaults', []);
        $now = now();

        $configs = DB::table('acquirer_configs')->whereNull('deleted_at')->get();

        foreach ($configs as $config) {
            $settings = $this->decodeJson($config->settings ?? null);

            $this->backfillProfile($config, $settings, $defaults, $now);
            $this->backfillHealth($config, $settings, $now);
        }

        $this->backfillPolicies($now);
    }

    public function down(): void
    {
        // Backfilled rows are safe to keep: they carry no data that did not
        // already exist elsewhere. Dropping the tables is handled by the
        // structural migration.
    }

    private function backfillProfile(object $config, array $settings, array $defaults, \Illuminate\Support\Carbon $now): void
    {
        $exists = DB::table('acquirer_routing_profiles')
            ->where('acquirer_config_id', $config->id)
            ->exists();

        if ($exists) {
            return;
        }

        // Amount limits used to live in settings under a few different names.
        $limits = $this->extractAmountLimits($settings);

        DB::table('acquirer_routing_profiles')->insert([
            'id' => (string) Str::uuid(),
            'acquirer_config_id' => $config->id,
            'acquirer_key' => $config->acquirer_key,
            'routing_enabled' => (bool) $config->enabled,
            'priority' => (int) ($config->priority ?? $defaults['priority'] ?? 100),
            'weight' => (int) ($settings['weight'] ?? $defaults['weight'] ?? 1),
            'max_tps' => $settings['max_tps'] ?? null,
            'max_concurrent' => $settings['max_concurrent'] ?? null,
            'connect_timeout_ms' => (int) ($defaults['connect_timeout_ms'] ?? 3000),
            'timeout_ms' => (int) ($defaults['timeout_ms'] ?? 15000),
            'max_retries' => (int) ($defaults['max_retries'] ?? 1),
            'retry_backoff_ms' => (int) ($defaults['retry_backoff_ms'] ?? 200),
            'retry_backoff_max_ms' => (int) ($defaults['retry_backoff_max_ms'] ?? 2000),
            'cb_failure_threshold' => (int) ($defaults['cb_failure_threshold'] ?? 5),
            'cb_cooldown_seconds' => (int) ($defaults['cb_cooldown_seconds'] ?? 60),
            'cb_half_open_max_calls' => (int) ($defaults['cb_half_open_max_calls'] ?? 1),
            'cb_success_threshold' => (int) ($defaults['cb_success_threshold'] ?? 2),
            'health_check_enabled' => true,
            'health_check_url' => $settings['base_url'] ?? null,
            'health_check_interval_seconds' => (int) ($defaults['health_check_interval_seconds'] ?? 60),
            'min_amount_cents' => $limits['min'],
            'max_amount_cents' => $limits['max'],
            'rules' => json_encode([], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function backfillHealth(object $config, array $settings, \Illuminate\Support\Carbon $now): void
    {
        $exists = DB::table('acquirer_health_states')
            ->where('acquirer_config_id', $config->id)
            ->exists();

        if ($exists) {
            return;
        }

        // A disabled acquirer starts offline; an enabled one starts UNKNOWN so
        // it is routable and can prove itself on the first request.
        $state = $config->enabled
            ? HealthState::tryNormalize($settings['health_status'] ?? null)
            : HealthState::OFFLINE;

        DB::table('acquirer_health_states')->insert([
            'id' => (string) Str::uuid(),
            'acquirer_config_id' => $config->id,
            'acquirer_key' => $config->acquirer_key,
            'state' => $state->value,
            'circuit_state' => CircuitState::CLOSED->value,
            'consecutive_failures' => 0,
            'consecutive_successes' => 0,
            'circuit_trip_count' => 0,
            'checks_total' => 0,
            'checks_ok' => 0,
            'last_check_at' => isset($settings['last_health_check'])
                ? $this->parseDate($settings['last_health_check'])
                : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * One global policy per payment method, seeded from the legacy rotation
     * settings when they exist so current behaviour is preserved.
     */
    private function backfillPolicies(\Illuminate\Support\Carbon $now): void
    {
        $legacy = $this->legacyRotationSettings();
        $defaults = (array) config('acquirer_routing.defaults', []);

        foreach (PaymentMethod::cases() as $method) {
            $exists = DB::table('acquirer_routing_policies')
                ->where('scope', 'global')
                ->where('payment_method', $method->value)
                ->exists();

            if ($exists) {
                continue;
            }

            $legacyMethod = $legacy['methods'][$method->value] ?? [];

            DB::table('acquirer_routing_policies')->insert([
                'id' => (string) Str::uuid(),
                'scope' => 'global',
                'account_id' => null,
                'payment_method' => $method->value,
                'strategy' => (string) ($legacyMethod['strategy'] ?? $defaults['strategy'] ?? 'priority'),
                'failover_enabled' => (bool) ($legacy['failover_enabled'] ?? $defaults['failover_enabled'] ?? true),
                'max_attempts' => (int) ($defaults['max_attempts'] ?? 3),
                'total_budget_ms' => (int) ($defaults['total_budget_ms'] ?? 25000),
                'strict_health_check' => (bool) ($legacy['strict_health_check'] ?? $defaults['strict_health_check'] ?? true),
                'settings' => json_encode([], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyRotationSettings(): array
    {
        if (!Schema::hasTable('system_settings')) {
            return [];
        }

        $row = DB::table('system_settings')->where('key', 'acquirer_rotation_settings')->first();

        return $row ? $this->decodeJson($row->value ?? null) : [];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{min: int|null, max: int|null}
     */
    private function extractAmountLimits(array $settings): array
    {
        $min = $settings['min_amount_cents'] ?? $settings['pix_min_amount'] ?? $settings['min_amount'] ?? null;
        $max = $settings['max_amount_cents'] ?? $settings['pix_max_amount'] ?? $settings['max_amount'] ?? null;

        return [
            'min' => is_numeric($min) ? (int) $min : null,
            'max' => is_numeric($max) ? (int) $max : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function parseDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
};
