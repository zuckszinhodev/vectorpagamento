<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            // Add missing columns
            if (!Schema::hasColumn('api_keys', 'key')) {
                $table->string('key', 64)->nullable()->after('name');
            }
            if (!Schema::hasColumn('api_keys', 'key_prefix')) {
                $table->string('key_prefix', 20)->nullable()->after('key');
            }
            if (!Schema::hasColumn('api_keys', 'environment')) {
                $table->enum('environment', ['production', 'sandbox'])->default('production')->after('permissions');
            }
            if (!Schema::hasColumn('api_keys', 'ip_whitelist')) {
                $table->jsonb('ip_whitelist')->nullable()->after('environment');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn(['key', 'key_prefix', 'environment', 'ip_whitelist']);
        });
    }
};
