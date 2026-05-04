<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional performance indexes for frequently queried columns.
 * Targets: acquirer_configs lookups, splits by transaction, payment_method filtering.
 */
return new class extends Migration
{
    public function up(): void
    {
        // acquirer_configs: queried by acquirer_key constantly in fee calculations
        try {
            Schema::table('acquirer_configs', function (Blueprint $table) {
                $table->index(['acquirer_key', 'enabled'], 'idx_acquirer_configs_key_enabled');
            });
        } catch (\Exception $e) { /* index already exists */ }

        // splits: queried by transaction_id + recipient_id + status in balance calculations
        try {
            Schema::table('splits', function (Blueprint $table) {
                $table->index(['transaction_id', 'recipient_id', 'status'], 'idx_splits_txn_recipient_status');
            });
        } catch (\Exception $e) { /* index already exists */ }

        // transactions: queried by charge_id for split lookups
        try {
            Schema::table('transactions', function (Blueprint $table) {
                $table->index(['charge_id'], 'idx_transactions_charge_id');
            });
        } catch (\Exception $e) { /* index already exists */ }

        // charges: payment_method filter used in charge listings
        try {
            Schema::table('charges', function (Blueprint $table) {
                $table->index(['account_id', 'payment_method', 'status'], 'idx_charges_account_method_status');
            });
        } catch (\Exception $e) { /* index already exists */ }

        // account_custom_fees: queried by account_id in every fee calculation
        try {
            Schema::table('account_custom_fees', function (Blueprint $table) {
                $table->unique(['account_id'], 'idx_account_custom_fees_account_id');
            });
        } catch (\Exception $e) { /* index already exists */ }

        // withdrawals: status filtering for admin panel
        try {
            Schema::table('withdrawals', function (Blueprint $table) {
                $table->index(['status', 'created_at'], 'idx_withdrawals_status_created');
            });
        } catch (\Exception $e) { /* index already exists */ }

        // kyc_submissions: status filtering for pending KYC dashboard
        try {
            Schema::table('kyc_submissions', function (Blueprint $table) {
                $table->index(['status'], 'idx_kyc_submissions_status');
            });
        } catch (\Exception $e) { /* index already exists */ }
    }

    public function down(): void
    {
        Schema::table('acquirer_configs', fn (Blueprint $t) => $t->dropIndex('idx_acquirer_configs_key_enabled'));
        Schema::table('splits', fn (Blueprint $t) => $t->dropIndex('idx_splits_txn_recipient_status'));
        Schema::table('transactions', fn (Blueprint $t) => $t->dropIndex('idx_transactions_charge_id'));
        Schema::table('charges', fn (Blueprint $t) => $t->dropIndex('idx_charges_account_method_status'));
        Schema::table('account_custom_fees', fn (Blueprint $t) => $t->dropIndex('idx_account_custom_fees_account_id'));
        Schema::table('withdrawals', fn (Blueprint $t) => $t->dropIndex('idx_withdrawals_status_created'));
        Schema::table('kyc_submissions', fn (Blueprint $t) => $t->dropIndex('idx_kyc_submissions_status'));
    }
};
