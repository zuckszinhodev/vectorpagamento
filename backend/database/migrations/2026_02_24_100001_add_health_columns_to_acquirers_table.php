<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds health-check columns used by HealthCheckAcquirersJob:
 *   - base_url            (where to ping)
 *   - health_status       (healthy / unhealthy)
 *   - last_health_check   (timestamp)
 *   - avg_response_time_ms (latency tracking)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acquirers', function (Blueprint $table) {
            if (!Schema::hasColumn('acquirers', 'base_url')) {
                $table->string('base_url')->nullable()->after('settings');
            }
            if (!Schema::hasColumn('acquirers', 'health_status')) {
                $table->string('health_status')->default('unknown')->after('base_url');
            }
            if (!Schema::hasColumn('acquirers', 'last_health_check')) {
                $table->timestamp('last_health_check')->nullable()->after('health_status');
            }
            if (!Schema::hasColumn('acquirers', 'avg_response_time_ms')) {
                $table->float('avg_response_time_ms')->nullable()->after('last_health_check');
            }
        });
    }

    public function down(): void
    {
        Schema::table('acquirers', function (Blueprint $table) {
            $table->dropColumn(['base_url', 'health_status', 'last_health_check', 'avg_response_time_ms']);
        });
    }
};
