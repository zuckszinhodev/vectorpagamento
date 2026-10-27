<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Koku Pay (https://docs.kokupay.com): Pix de entrada. Entra desligada; ativação
 * e credenciais (api_key e webhook_secret) pelo painel admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table('acquirer_configs')->where('acquirer_key', 'kokupay')->exists()) {
            DB::table('acquirer_configs')->insert([
                'id' => Str::uuid(),
                'acquirer_key' => 'kokupay',
                'display_name' => 'Koku Pay',
                'type' => 'gateway',
                'enabled' => false,
                'priority' => 1,
                'acquirer_fee_percentage' => 0,
                'acquirer_fee_fixed' => 0,
                'gateway_fee_percentage' => 0,
                'gateway_fee_fixed' => 0,
                'supported_methods' => json_encode(['pix']),
                'credentials' => null,
                'settings' => json_encode([
                    'base_url' => 'https://api.kokupay.com/v1',
                    'supports_balance' => true,
                    'supports_withdrawals' => false,
                    'documentation' => 'https://docs.kokupay.com/',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (!DB::table('acquirers')->where('slug', 'kokupay')->exists()) {
            DB::table('acquirers')->insert([
                'id' => Str::uuid(),
                'name' => 'Koku Pay',
                'slug' => 'kokupay',
                'is_active' => true,
                'supported_methods' => json_encode(['pix']),
                'settings' => json_encode([
                    'api_url' => 'https://api.kokupay.com/v1',
                    'supports_withdrawals' => false,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('acquirer_configs')->where('acquirer_key', 'kokupay')->delete();
        DB::table('acquirers')->where('slug', 'kokupay')->delete();
    }
};
