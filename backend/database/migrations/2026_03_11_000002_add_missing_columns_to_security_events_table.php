<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add missing columns to security_events table.
 * The original migration (2024_01_01_000020) created a simpler schema.
 * The SecurityMonitorController expects additional columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('security_events')) {
            Schema::table('security_events', function (Blueprint $table) {
                if (!Schema::hasColumn('security_events', 'endpoint')) {
                    $table->string('endpoint', 255)->nullable();
                }
                if (!Schema::hasColumn('security_events', 'method')) {
                    $table->string('method', 10)->nullable();
                }
                if (!Schema::hasColumn('security_events', 'description')) {
                    $table->text('description')->nullable();
                }
                if (!Schema::hasColumn('security_events', 'metadata')) {
                    $table->json('metadata')->nullable();
                }
                if (!Schema::hasColumn('security_events', 'country_code')) {
                    $table->string('country_code', 2)->nullable()->index();
                }
                if (!Schema::hasColumn('security_events', 'acknowledged')) {
                    $table->boolean('acknowledged')->default(false);
                }
                if (!Schema::hasColumn('security_events', 'acknowledged_by')) {
                    $table->uuid('acknowledged_by')->nullable();
                }
                if (!Schema::hasColumn('security_events', 'acknowledged_at')) {
                    $table->timestamp('acknowledged_at')->nullable();
                }
            });
        }

        // Create blocked_ips if it doesn't exist
        if (!Schema::hasTable('blocked_ips')) {
            Schema::create('blocked_ips', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('ip_address', 45)->index();
                $table->string('reason', 500);
                $table->string('block_type', 20)->default('manual');
                $table->uuid('blocked_by')->nullable();
                $table->string('status', 20)->default('active');
                $table->timestamp('expires_at')->nullable()->index();
                $table->uuid('unblocked_by')->nullable();
                $table->timestamp('unblocked_at')->nullable();
                $table->timestamps();

                $table->index(['ip_address', 'status']);
                $table->index(['status', 'expires_at']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('security_events')) {
            Schema::table('security_events', function (Blueprint $table) {
                $columns = ['endpoint', 'method', 'description', 'metadata', 'country_code', 'acknowledged', 'acknowledged_by', 'acknowledged_at'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('security_events', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
