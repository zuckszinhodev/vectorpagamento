<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add composite indexes to optimize dashboard, analytics, and report queries.
 * These cover the most frequent query patterns:
 * - account_id + status + paid_at (revenue/sales queries)
 * - account_id + status + created_at (approval/conversion queries)
 * - account_id + expires_at + status (charge expiration job)
 * - acquirer + status (webhook processing)
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
        Schema::table('charges', function (Blueprint $table) {
            // Dashboard: revenue/sales by account + status + paid_at
            $table->index(['account_id', 'status', 'paid_at'], 'idx_charges_account_status_paid');

            // Analytics: approval/conversion queries
            $table->index(['account_id', 'status', 'created_at'], 'idx_charges_account_status_created');

            // Charge expiration job: find expired pending charges
            $table->index(['status', 'expires_at'], 'idx_charges_status_expires');

            // Webhook processing: find charges by acquirer
            $table->index(['acquirer', 'acquirer_transaction_id'], 'idx_charges_acquirer_txn');

            // Checkout flow: find charges by external_id
            $table->index(['external_id'], 'idx_charges_external_id');
        });
        } catch (\Exception $e) { /* indexes already exist */ }

        try {
        Schema::table('transactions', function (Blueprint $table) {
            // Link transactions to charges efficiently
            $table->index(['charge_id', 'status'], 'idx_transactions_charge_status');
            $table->index(['acquirer_tid'], 'idx_transactions_acquirer_tid');
        });
        } catch (\Exception $e) { /* indexes already exist */ }

        try {
        Schema::table('withdrawals', function (Blueprint $table) {
            // Auto-withdrawal job queries
            $table->index(['account_id', 'status', 'created_at'], 'idx_withdrawals_account_status_created');
        });
        } catch (\Exception $e) { /* indexes already exist */ }
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropIndex('idx_charges_account_status_paid');
            $table->dropIndex('idx_charges_account_status_created');
            $table->dropIndex('idx_charges_status_expires');
            $table->dropIndex('idx_charges_acquirer_txn');
            $table->dropIndex('idx_charges_external_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_transactions_charge_status');
            $table->dropIndex('idx_transactions_acquirer_tid');
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropIndex('idx_withdrawals_account_status_created');
        });
    }
};
