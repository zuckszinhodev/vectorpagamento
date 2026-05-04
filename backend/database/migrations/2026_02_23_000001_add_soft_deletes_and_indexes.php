<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Robustness migration:
 * 1. Add soft-delete to charges (audit trail compliance)
 * 2. Add critical indexes for query performance
 * 3. Add cancellation_reason to charges
 *
 * Note: Uses raw SQL "CREATE INDEX IF NOT EXISTS" for PostgreSQL
 * compatibility — try/catch on Blueprint index creation aborts
 * the entire PG transaction on duplicate index errors.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Soft-delete for Charge audit trail
        if (!Schema::hasColumn('charges', 'deleted_at')) {
            Schema::table('charges', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        // Add cancellation_reason if missing
        if (!Schema::hasColumn('charges', 'cancellation_reason')) {
            Schema::table('charges', function (Blueprint $table) {
                $table->string('cancellation_reason')->nullable()->after('failure_reason');
            });
        }

        // Performance indexes (IF NOT EXISTS avoids PG transaction abort)
        DB::statement('CREATE INDEX IF NOT EXISTS idx_charges_account_status_paid ON charges (account_id, status, paid_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_charges_external_id ON charges (external_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_charges_acquirer_tid ON charges (acquirer_transaction_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_charges_idempotency ON charges (idempotency_key)');

        // Withdrawal performance indexes
        if (Schema::hasTable('withdrawals')) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_withdrawals_account_status ON withdrawals (account_id, status, created_at)');
        }

        // Transaction lookup indexes
        if (Schema::hasTable('transactions')) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_transactions_charge_type ON transactions (charge_id, type)');
        }
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('cancellation_reason');
        });

        DB::statement('DROP INDEX IF EXISTS idx_charges_account_status_paid');
        DB::statement('DROP INDEX IF EXISTS idx_charges_external_id');
        DB::statement('DROP INDEX IF EXISTS idx_charges_acquirer_tid');
        DB::statement('DROP INDEX IF EXISTS idx_charges_idempotency');

        if (Schema::hasTable('withdrawals')) {
            DB::statement('DROP INDEX IF EXISTS idx_withdrawals_account_status');
        }

        if (Schema::hasTable('transactions')) {
            DB::statement('DROP INDEX IF EXISTS idx_transactions_charge_type');
        }
    }
};
