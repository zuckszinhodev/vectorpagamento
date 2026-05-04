<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_access_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('admin_access_logs', 'actor_role')) {
                $table->string('actor_role', 50)->nullable()->after('account_id');
                $table->index('actor_role');
            }

            if (!Schema::hasColumn('admin_access_logs', 'correlation_id')) {
                $table->string('correlation_id', 100)->nullable()->after('request_body');
                $table->index('correlation_id');
            }

            if (!Schema::hasColumn('admin_access_logs', 'security_flags')) {
                $table->jsonb('security_flags')->nullable()->after('correlation_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('admin_access_logs', function (Blueprint $table) {
            if (Schema::hasColumn('admin_access_logs', 'security_flags')) {
                $table->dropColumn('security_flags');
            }

            if (Schema::hasColumn('admin_access_logs', 'correlation_id')) {
                $table->dropIndex(['correlation_id']);
                $table->dropColumn('correlation_id');
            }

            if (Schema::hasColumn('admin_access_logs', 'actor_role')) {
                $table->dropIndex(['actor_role']);
                $table->dropColumn('actor_role');
            }
        });
    }
};
