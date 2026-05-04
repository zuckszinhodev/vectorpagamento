<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $configExists = DB::table('acquirer_configs')->where('acquirer_key', 'enki')->exists();

        if (!$configExists) {
            DB::table('acquirer_configs')->insert([
                'id' => Str::uuid(),
                'acquirer_key' => 'enki',
                'display_name' => 'Enki Bank',
                'type' => 'gateway',
                'enabled' => false,
                'priority' => 2,
                'acquirer_fee_percentage' => 0,
                'acquirer_fee_fixed' => 0,
                'gateway_fee_percentage' => 0,
                'gateway_fee_fixed' => 0,
                'supported_methods' => json_encode(['pix']),
                'credentials' => null,
                'settings' => json_encode([
                    'base_url' => 'https://api.enki-bank.com',
                    'supports_balance' => true,
                    'supports_withdrawals' => true,
                    'documentation' => 'https://app.enki-bank.com/',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $acquirerExists = DB::table('acquirers')->where('slug', 'enki')->exists();

        if (!$acquirerExists) {
            DB::table('acquirers')->insert([
                'id' => Str::uuid(),
                'name' => 'Enki Bank',
                'slug' => 'enki',
                'is_active' => true,
                'supported_methods' => json_encode(['pix']),
                'settings' => json_encode([
                    'api_url' => 'https://api.enki-bank.com',
                    'supports_withdrawals' => true,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('acquirer_configs')->where('acquirer_key', 'enki')->delete();
        DB::table('acquirers')->where('slug', 'enki')->delete();
    }
};