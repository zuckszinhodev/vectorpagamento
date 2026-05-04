<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('acquirer_configs', 'gateway_fee_percentage')) {
            return;
        }

        // Add gateway fees columns (what the gateway charges customers)
        if (Schema::hasColumn('acquirer_configs', 'fee_percentage')) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                Schema::table('acquirer_configs', function (Blueprint $table) {
                    $table->renameColumn('fee_percentage', 'acquirer_fee_percentage');
                });
                Schema::table('acquirer_configs', function (Blueprint $table) {
                    $table->renameColumn('fee_fixed', 'acquirer_fee_fixed');
                });
            } else {
                Schema::table('acquirer_configs', function (Blueprint $table) {
                    // Rename existing columns to be clearer (acquirer fees)
                    $table->renameColumn('fee_percentage', 'acquirer_fee_percentage');
                    $table->renameColumn('fee_fixed', 'acquirer_fee_fixed');
                });
            }
        }

        Schema::table('acquirer_configs', function (Blueprint $table) {
            // Add gateway fees (what GatePro charges customers)
            $table->decimal('gateway_fee_percentage', 5, 2)->default(0.00)->after('acquirer_fee_fixed');
            $table->decimal('gateway_fee_fixed', 10, 2)->default(0.00)->after('gateway_fee_percentage');
        });

        // Update Babylon with correct fees:
        // Acquirer charges: 2% + R$0.80
        // Gateway charges: 5% + R$2.00
        DB::table('acquirer_configs')
            ->where('acquirer_key', 'babylon')
            ->update([
                'acquirer_fee_percentage' => 2.00,
                'acquirer_fee_fixed' => 0.80,
                'gateway_fee_percentage' => 5.00,
                'gateway_fee_fixed' => 2.00,
            ]);

        // Update other acquirers with reasonable gateway fees
        DB::table('acquirer_configs')
            ->where('acquirer_key', 'stripe')
            ->update([
                'acquirer_fee_percentage' => 2.99,
                'acquirer_fee_fixed' => 0.30,
                'gateway_fee_percentage' => 5.00,
                'gateway_fee_fixed' => 2.00,
            ]);

        DB::table('acquirer_configs')
            ->where('acquirer_key', 'pagarme')
            ->update([
                'acquirer_fee_percentage' => 2.49,
                'acquirer_fee_fixed' => 0.00,
                'gateway_fee_percentage' => 5.00,
                'gateway_fee_fixed' => 2.00,
            ]);

        DB::table('acquirer_configs')
            ->where('acquirer_key', 'mercadopago')
            ->update([
                'acquirer_fee_percentage' => 3.99,
                'acquirer_fee_fixed' => 0.00,
                'gateway_fee_percentage' => 6.00,
                'gateway_fee_fixed' => 2.00,
            ]);
    }

    public function down(): void
    {
        Schema::table('acquirer_configs', function (Blueprint $table) {
            $table->dropColumn(['gateway_fee_percentage', 'gateway_fee_fixed']);
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('acquirer_configs', function (Blueprint $table) {
                $table->renameColumn('acquirer_fee_percentage', 'fee_percentage');
            });
            Schema::table('acquirer_configs', function (Blueprint $table) {
                $table->renameColumn('acquirer_fee_fixed', 'fee_fixed');
            });
        } else {
            Schema::table('acquirer_configs', function (Blueprint $table) {
                $table->renameColumn('acquirer_fee_percentage', 'fee_percentage');
                $table->renameColumn('acquirer_fee_fixed', 'fee_fixed');
            });
        }
    }
};
