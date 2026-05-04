<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add is_instant column to withdrawals table
        if (!Schema::hasColumn('withdrawals', 'is_instant')) {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->boolean('is_instant')->default(false)->after('method');
        });
        }

        // Insert withdrawal system settings
        if (DB::table('system_settings')->where('key', 'instant_withdrawal_enabled')->doesntExist()) {
        DB::table('system_settings')->insert([
            [
                'key' => 'instant_withdrawal_enabled',
                'value' => 'false',
                'type' => 'boolean',
                'group' => 'withdrawals',
                'description' => 'Habilitar saque instantâneo para usuários',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'instant_withdrawal_fee_type',
                'value' => 'percentage',
                'type' => 'string',
                'group' => 'withdrawals',
                'description' => 'Tipo de taxa do saque instantâneo (percentage ou fixed)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'instant_withdrawal_fee_value',
                'value' => '2.5',
                'type' => 'string',
                'group' => 'withdrawals',
                'description' => 'Valor da taxa de saque instantâneo (% ou valor fixo em centavos)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'standard_withdrawal_fee_pix',
                'value' => '0',
                'type' => 'integer',
                'group' => 'withdrawals',
                'description' => 'Taxa padrão para saque via PIX (em centavos)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'standard_withdrawal_fee_bank',
                'value' => '200',
                'type' => 'integer',
                'group' => 'withdrawals',
                'description' => 'Taxa padrão para saque via TED (em centavos)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'withdrawal_min_amount',
                'value' => '1000',
                'type' => 'integer',
                'group' => 'withdrawals',
                'description' => 'Valor mínimo de saque (em centavos)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        }
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropColumn('is_instant');
        });

        DB::table('system_settings')->where('group', 'withdrawals')->delete();
    }
};
