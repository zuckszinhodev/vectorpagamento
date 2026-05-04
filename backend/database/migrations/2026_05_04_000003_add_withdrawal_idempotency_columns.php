<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table): void {
            if (!Schema::hasColumn('withdrawals', 'idempotency_key')) {
                $table->string('idempotency_key', 120)->nullable()->after('method');
            }

            if (!Schema::hasColumn('withdrawals', 'idempotency_request_hash')) {
                $table->string('idempotency_request_hash', 64)->nullable()->after('idempotency_key');
            }
        });

        try {
            Schema::table('withdrawals', function (Blueprint $table): void {
                $table->dropUnique('withdrawals_account_id_idempotency_key_unique');
            });
        } catch (\Throwable $e) {
            // Ignore if missing.
        }

        try {
            Schema::table('withdrawals', function (Blueprint $table): void {
                $table->unique(['account_id', 'idempotency_key'], 'withdrawals_account_id_idempotency_key_unique');
            });
        } catch (\Throwable $e) {
            // Ignore duplicate-constraint errors to keep migration idempotent.
        }

        try {
            Schema::table('withdrawals', function (Blueprint $table): void {
                $table->index(['account_id', 'idempotency_request_hash'], 'withdrawals_account_id_idempotency_hash_index');
            });
        } catch (\Throwable $e) {
            // Ignore if index already exists.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('withdrawals', function (Blueprint $table): void {
                $table->dropIndex('withdrawals_account_id_idempotency_hash_index');
            });
        } catch (\Throwable $e) {
            // Ignore if missing.
        }

        try {
            Schema::table('withdrawals', function (Blueprint $table): void {
                $table->dropUnique('withdrawals_account_id_idempotency_key_unique');
            });
        } catch (\Throwable $e) {
            // Ignore if missing.
        }

        Schema::table('withdrawals', function (Blueprint $table): void {
            if (Schema::hasColumn('withdrawals', 'idempotency_request_hash')) {
                $table->dropColumn('idempotency_request_hash');
            }

            if (Schema::hasColumn('withdrawals', 'idempotency_key')) {
                $table->dropColumn('idempotency_key');
            }
        });
    }
};
