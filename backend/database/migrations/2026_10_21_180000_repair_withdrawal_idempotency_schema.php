<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An older migration swallowed a missing-index error inside a PostgreSQL
        // transaction, rolling back its DDL while still being recorded as run.
        Schema::table('withdrawals', function (Blueprint $table): void {
            if (!Schema::hasColumn('withdrawals', 'idempotency_key')) {
                $table->string('idempotency_key', 120)->nullable();
            }
            if (!Schema::hasColumn('withdrawals', 'idempotency_request_hash')) {
                $table->string('idempotency_request_hash', 64)->nullable();
            }
        });

        if (!Schema::hasIndex('withdrawals', 'withdrawals_account_id_idempotency_key_unique')) {
            Schema::table('withdrawals', function (Blueprint $table): void {
                $table->unique(['account_id', 'idempotency_key'], 'withdrawals_account_id_idempotency_key_unique');
            });
        }
        if (!Schema::hasIndex('withdrawals', 'withdrawals_account_id_idempotency_hash_index')) {
            Schema::table('withdrawals', function (Blueprint $table): void {
                $table->index(['account_id', 'idempotency_request_hash'], 'withdrawals_account_id_idempotency_hash_index');
            });
        }
    }

    public function down(): void
    {
        // Keep existing request keys: removing them would allow duplicate payouts.
    }
};
