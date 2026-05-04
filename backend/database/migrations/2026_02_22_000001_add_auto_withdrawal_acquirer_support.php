<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add acquirer integration and auto-withdrawal support to withdrawals table.
 *
 * New columns:
 * - acquirer_transfer_id: ID returned by PayFort/BluPay after PIX OUT
 * - acquirer: which acquirer processed the withdrawal (payfort/blupay)
 * - auto_process: whether this withdrawal should be auto-processed
 *
 * New system_settings:
 * - auto_withdrawal_enabled: master switch for automatic withdrawals
 * - auto_withdrawal_min_amount: minimum balance to trigger auto-withdrawal (cents)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add columns to withdrawals table
        Schema::table('withdrawals', function (Blueprint $table) {
            if (!Schema::hasColumn('withdrawals', 'acquirer_transfer_id')) {
                $table->string('acquirer_transfer_id')->nullable()->after('is_instant');
            }
            if (!Schema::hasColumn('withdrawals', 'acquirer')) {
                $table->string('acquirer')->nullable()->after('acquirer_transfer_id');
            }
            if (!Schema::hasColumn('withdrawals', 'auto_process')) {
                $table->boolean('auto_process')->default(false)->after('acquirer');
            }

            // Index for webhook lookups
            try {
                $table->index('acquirer_transfer_id', 'idx_withdrawals_acquirer_transfer');
            } catch (\Exception $e) { /* index already exists */ }
        });

        // Auto-withdrawal system settings
        $settings = [
            [
                'key'         => 'auto_withdrawal_enabled',
                'value'       => 'true',
                'type'        => 'boolean',
                'group'       => 'withdrawals',
                'description' => 'Habilitar saque automático quando pagamento é recebido',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'key'         => 'auto_withdrawal_min_amount',
                'value'       => '0',
                'type'        => 'integer',
                'group'       => 'withdrawals',
                'description' => 'Valor mínimo em centavos para saque automático (0 = qualquer valor)',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ];

        foreach ($settings as $setting) {
            $exists = DB::table('system_settings')->where('key', $setting['key'])->exists();
            if (!$exists) {
                DB::table('system_settings')->insert($setting);
            }
        }

        // Also ensure instant_withdrawal_enabled is true by default
        DB::table('system_settings')
            ->where('key', 'instant_withdrawal_enabled')
            ->update(['value' => 'true']);
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropIndex('idx_withdrawals_acquirer_transfer');
            $table->dropColumn(['acquirer_transfer_id', 'acquirer', 'auto_process']);
        });

        DB::table('system_settings')->whereIn('key', [
            'auto_withdrawal_enabled',
            'auto_withdrawal_min_amount',
        ])->delete();
    }
};
