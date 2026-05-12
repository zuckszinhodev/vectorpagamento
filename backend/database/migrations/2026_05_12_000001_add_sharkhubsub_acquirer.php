<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table('acquirer_configs')->where('acquirer_key', 'sharkhubsub')->exists()) {
            DB::table('acquirer_configs')->insert([
                'id' => Str::uuid(),
                'acquirer_key' => 'sharkhubsub',
                'display_name' => 'SharkHub Subadquirente',
                'nominal_name' => 'SharkHub',
                'type' => 'gateway',
                'enabled' => false,
                'priority' => 5,
                'acquirer_fee_percentage' => 0,
                'acquirer_fee_fixed' => 0,
                'gateway_fee_percentage' => 0,
                'gateway_fee_fixed' => 0,
                'supported_methods' => json_encode(['pix', 'pix_out']),
                'credentials' => null,
                'settings' => json_encode([
                    'base_url' => 'https://api.sharkhubsubadquirente.com',
                    'supports_withdrawals' => true,
                    'webhook_signature_header' => 'X-Signature',
                    'documentation' => 'https://app.sharkhubsubadquirente.com/docs/introduction/start',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (!DB::table('acquirers')->where('slug', 'sharkhubsub')->exists()) {
            DB::table('acquirers')->insert([
                'id' => Str::uuid(),
                'name' => 'SharkHub Subadquirente',
                'slug' => 'sharkhubsub',
                'is_active' => true,
                'supported_methods' => json_encode(['pix', 'pix_out']),
                'settings' => json_encode([
                    'api_url' => 'https://api.sharkhubsubadquirente.com',
                    'supports_withdrawals' => true,
                ]),
                'base_url' => 'https://api.sharkhubsubadquirente.com',
                'health_status' => 'unknown',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('acquirer_configs')->where('acquirer_key', 'sharkhubsub')->delete();
        DB::table('acquirers')->where('slug', 'sharkhubsub')->delete();
    }
};
