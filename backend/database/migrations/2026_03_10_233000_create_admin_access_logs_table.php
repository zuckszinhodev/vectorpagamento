<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SECURITY FIX: Create admin access logs table for security auditing.
 * 
 * This table stores all accesses to admin endpoints for:
 * - Security auditing and compliance
 * - Suspicious activity detection
 * - Forensic analysis in case of incidents
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_access_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('account_id')->nullable()->index();
            $table->string('endpoint', 255);
            $table->string('method', 10);
            $table->string('ip_address', 45); // IPv6 compatible
            $table->string('user_agent', 500)->nullable();
            $table->text('request_body')->nullable();
            $table->smallInteger('response_status');
            $table->decimal('response_time_ms', 10, 2)->nullable();
            $table->timestamp('accessed_at')->index();
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes for common queries
            $table->index(['user_id', 'accessed_at']);
            $table->index(['ip_address', 'accessed_at']);
            $table->index(['endpoint', 'accessed_at']);
            
            // Foreign keys (optional - depends on your setup)
            // $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            // $table->foreign('account_id')->references('id')->on('accounts')->onDelete('set null');
        });

        // Add comment for documentation (SQLite does not support COMMENT ON TABLE)
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("COMMENT ON TABLE admin_access_logs IS 'Security audit log for all admin endpoint accesses'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_access_logs');
    }
};
