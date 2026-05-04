<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove legacy global uniqueness and enforce account-scoped idempotency.
        try {
            Schema::table('charges', function (Blueprint $table): void {
                $table->dropUnique('charges_idempotency_key_unique');
            });
        } catch (\Throwable $e) {
            // Constraint may not exist in older/partially migrated environments.
        }

        try {
            Schema::table('charges', function (Blueprint $table): void {
                $table->dropUnique('charges_account_id_idempotency_key_unique');
            });
        } catch (\Throwable $e) {
            // Ignore if it does not exist.
        }

        try {
            Schema::table('charges', function (Blueprint $table): void {
                $table->unique(['account_id', 'idempotency_key'], 'charges_account_id_idempotency_key_unique');
            });
        } catch (\Throwable $e) {
            // Ignore duplicate-constraint errors to keep migration idempotent.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('charges', function (Blueprint $table): void {
                $table->dropUnique('charges_account_id_idempotency_key_unique');
            });
        } catch (\Throwable $e) {
            // Ignore if missing.
        }

        try {
            Schema::table('charges', function (Blueprint $table): void {
                $table->unique('idempotency_key', 'charges_idempotency_key_unique');
            });
        } catch (\Throwable $e) {
            // Reverting may fail if duplicate keys already exist across accounts.
        }
    }
};
