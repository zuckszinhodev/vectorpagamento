<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core tables for the intelligent acquirer routing engine.
 *
 * Responsibilities are split across dedicated tables instead of piling
 * everything into acquirer_configs.settings:
 *
 *   acquirer_routing_profiles → routing knobs (priority, weight, TPS, timeouts, circuit breaker)
 *   acquirer_credentials      → one encrypted row per credential field
 *   acquirer_health_states    → live availability + circuit breaker state
 *   acquirer_metric_buckets   → per-minute counters (latency, approval, volume)
 *   acquirer_routing_logs     → one row per routing decision, with every attempt
 *   acquirer_events           → audit trail of status/circuit/health transitions
 *   acquirer_routing_policies → balancing strategy, global or per establishment
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('acquirer_routing_profiles')) {
            Schema::create('acquirer_routing_profiles', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('acquirer_config_id');
                $table->string('acquirer_key')->index();

                $table->boolean('routing_enabled')->default(true);
                $table->integer('priority')->default(100);
                $table->integer('weight')->default(1);

                $table->integer('max_tps')->nullable();
                $table->integer('max_concurrent')->nullable();

                $table->integer('connect_timeout_ms')->default(3000);
                $table->integer('timeout_ms')->default(15000);
                $table->integer('max_retries')->default(1);
                $table->integer('retry_backoff_ms')->default(200);
                $table->integer('retry_backoff_max_ms')->default(2000);

                $table->integer('cb_failure_threshold')->default(5);
                $table->integer('cb_cooldown_seconds')->default(60);
                $table->integer('cb_half_open_max_calls')->default(1);
                $table->integer('cb_success_threshold')->default(2);

                $table->boolean('health_check_enabled')->default(true);
                $table->string('health_check_url')->nullable();
                $table->integer('health_check_interval_seconds')->default(60);

                $table->bigInteger('min_amount_cents')->nullable();
                $table->bigInteger('max_amount_cents')->nullable();

                // Free-form per-acquirer rules (allowed methods, currencies, hour windows...)
                $table->json('rules')->nullable();

                $table->timestamps();

                $table->unique('acquirer_config_id');
                $table->index(['routing_enabled', 'priority']);
            });
        }

        if (!Schema::hasTable('acquirer_credentials')) {
            Schema::create('acquirer_credentials', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('acquirer_config_id');
                $table->string('acquirer_key')->index();

                $table->string('field_key');
                // AES-256-GCM payload (base64). Never a plaintext value.
                $table->text('cipher_text');
                $table->string('cipher_alg')->default('aes-256-gcm');
                $table->unsignedInteger('key_version')->default(1);

                // Safe-to-display remnants for the admin panel.
                $table->string('mask_hint', 32)->nullable();
                $table->unsignedSmallInteger('value_length')->default(0);
                // SHA-256 of the plaintext, so we can detect "unchanged" without decrypting.
                $table->string('fingerprint', 64)->nullable();

                $table->timestamp('rotated_at')->nullable();
                $table->timestamps();

                $table->unique(['acquirer_config_id', 'field_key']);
            });
        }

        if (!Schema::hasTable('acquirer_health_states')) {
            Schema::create('acquirer_health_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('acquirer_config_id');
                $table->string('acquirer_key')->index();

                // online | degraded | offline | unknown
                $table->string('state')->default('unknown');
                // closed | open | half_open
                $table->string('circuit_state')->default('closed');

                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->unsignedInteger('consecutive_successes')->default(0);
                $table->timestamp('circuit_opened_at')->nullable();
                $table->timestamp('circuit_half_open_at')->nullable();
                $table->unsignedInteger('circuit_trip_count')->default(0);

                $table->unsignedInteger('last_latency_ms')->nullable();
                $table->unsignedInteger('avg_latency_ms')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('last_error_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_check_at')->nullable();

                $table->unsignedBigInteger('checks_total')->default(0);
                $table->unsignedBigInteger('checks_ok')->default(0);

                $table->timestamps();

                $table->unique('acquirer_config_id');
                $table->index(['state', 'circuit_state']);
            });
        }

        if (!Schema::hasTable('acquirer_metric_buckets')) {
            Schema::create('acquirer_metric_buckets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('acquirer_key');
                $table->string('payment_method');
                // Truncated to the minute — keeps the table small and queries cheap.
                $table->timestamp('bucket_started_at');

                $table->unsignedBigInteger('attempts')->default(0);
                $table->unsignedBigInteger('successes')->default(0);
                $table->unsignedBigInteger('failures')->default(0);
                $table->unsignedBigInteger('timeouts')->default(0);
                $table->unsignedBigInteger('rejections')->default(0);
                $table->unsignedBigInteger('fallback_ins')->default(0);
                $table->unsignedBigInteger('fallback_outs')->default(0);

                $table->unsignedBigInteger('latency_sum_ms')->default(0);
                $table->unsignedBigInteger('latency_max_ms')->default(0);
                $table->unsignedBigInteger('volume_cents')->default(0);

                $table->timestamps();

                $table->unique(['acquirer_key', 'payment_method', 'bucket_started_at'], 'acq_metric_bucket_unique');
                $table->index('bucket_started_at');
            });
        }

        if (!Schema::hasTable('acquirer_routing_logs')) {
            Schema::create('acquirer_routing_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // Unique identifier shared by every log line of one routing decision.
                $table->string('correlation_id')->index();
                $table->uuid('charge_id')->nullable()->index();
                $table->uuid('account_id')->nullable()->index();

                $table->string('payment_method');
                $table->bigInteger('amount_cents')->default(0);
                $table->string('strategy')->nullable();

                $table->string('selected_acquirer')->nullable()->index();
                $table->text('selection_reason')->nullable();
                // 'success' | 'failed' | 'no_acquirer'
                $table->string('outcome')->default('failed');
                $table->unsignedInteger('attempt_count')->default(0);
                $table->unsignedInteger('total_duration_ms')->default(0);

                // Ordered list of every attempt: acquirer, reason, error, duration, retry number.
                $table->json('attempts')->nullable();
                $table->json('candidates')->nullable();
                $table->text('error')->nullable();

                $table->timestamps();

                $table->index(['created_at', 'outcome']);
            });
        }

        if (!Schema::hasTable('acquirer_events')) {
            Schema::create('acquirer_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('acquirer_key')->index();
                // status_change | circuit_opened | circuit_half_open | circuit_closed
                // | health_check | config_changed | credential_rotated | tps_throttled
                $table->string('type')->index();
                // info | warning | critical
                $table->string('severity')->default('info');
                $table->string('message');
                $table->json('context')->nullable();
                $table->string('correlation_id')->nullable()->index();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('acquirer_routing_policies')) {
            Schema::create('acquirer_routing_policies', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // global | account
                $table->string('scope')->default('global');
                $table->uuid('account_id')->nullable();
                $table->string('payment_method');

                // priority | round_robin | weighted | least_latency
                // | best_approval | least_utilization
                $table->string('strategy')->default('priority');
                $table->boolean('failover_enabled')->default(true);
                $table->unsignedInteger('max_attempts')->default(3);
                $table->unsignedInteger('total_budget_ms')->default(25000);
                $table->boolean('strict_health_check')->default(true);
                $table->json('settings')->nullable();

                $table->timestamps();

                $table->unique(['scope', 'account_id', 'payment_method'], 'acq_routing_policy_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('acquirer_routing_policies');
        Schema::dropIfExists('acquirer_events');
        Schema::dropIfExists('acquirer_routing_logs');
        Schema::dropIfExists('acquirer_metric_buckets');
        Schema::dropIfExists('acquirer_health_states');
        Schema::dropIfExists('acquirer_credentials');
        Schema::dropIfExists('acquirer_routing_profiles');
    }
};
