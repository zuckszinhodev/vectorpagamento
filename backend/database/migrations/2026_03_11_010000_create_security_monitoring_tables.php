<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security monitoring tables for comprehensive attack detection and logging.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Security Events - All security-related events
        if (!Schema::hasTable('security_events')) {
            Schema::create('security_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('event_type', 50)->index(); // failed_login, rate_limited, ip_blocked, sql_injection_attempt, etc.
                $table->string('severity', 20)->index();   // critical, high, medium, low, info
                $table->string('ip_address', 45)->index();
                $table->uuid('user_id')->nullable()->index();
                $table->string('user_agent', 500)->nullable();
                $table->string('endpoint', 255)->nullable();
                $table->string('method', 10)->nullable();
                $table->text('description')->nullable();
                $table->json('metadata')->nullable();
                $table->string('country_code', 2)->nullable()->index();
                $table->boolean('acknowledged')->default(false);
                $table->uuid('acknowledged_by')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
                $table->timestamp('created_at')->index();
                
                // Composite indexes for common queries
                $table->index(['event_type', 'created_at']);
                $table->index(['severity', 'acknowledged', 'created_at']);
                $table->index(['ip_address', 'event_type', 'created_at']);
            });
        }

        // Blocked IPs - Manually and automatically blocked IPs
        if (!Schema::hasTable('blocked_ips')) {
            Schema::create('blocked_ips', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('ip_address', 45)->index();
                $table->string('reason', 500);
                $table->string('block_type', 20)->default('manual'); // manual, automatic
                $table->uuid('blocked_by')->nullable();
                $table->string('status', 20)->default('active'); // active, expired, unblocked
                $table->timestamp('expires_at')->nullable()->index();
                $table->uuid('unblocked_by')->nullable();
                $table->timestamp('unblocked_at')->nullable();
                $table->timestamps();
                
                // Indexes
                $table->index(['ip_address', 'status']);
                $table->index(['status', 'expires_at']);
            });
        }

        // Add missing columns to rate_limit_logs if not exists
        if (Schema::hasTable('rate_limit_logs')) {
            Schema::table('rate_limit_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('rate_limit_logs', 'blocked')) {
                    $table->boolean('blocked')->default(false)->index();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('blocked_ips');
    }
};
