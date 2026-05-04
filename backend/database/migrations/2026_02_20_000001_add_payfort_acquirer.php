<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Add PayFort Bank as acquirer and set as primary (priority 0)
     * 
     * PayFort Bank:
     * - Acquirer fee: R$0.90 fixed per transaction (0% percentage)
     * - Gateway fee: 5% + R$2.00 (what GatePro charges customers)
     * - Supported methods: PIX
     * - Priority: 0 (highest - primary acquirer)
     */
    public function up(): void
    {
        // Check if payfort already exists
        $exists = DB::table('acquirer_configs')
            ->where('acquirer_key', 'payfort')
            ->exists();

        if (!$exists) {
            DB::table('acquirer_configs')->insert([
                'id' => (string) Str::uuid(),
                'acquirer_key' => 'payfort',
                'display_name' => 'PayFort Bank',
                'type' => 'PIX',
                'enabled' => true,
                'credentials' => null,
                'supported_methods' => json_encode(['pix']),
                'acquirer_fee_percentage' => 0.00,
                'acquirer_fee_fixed' => 0.90,
                'gateway_fee_percentage' => 5.00,
                'gateway_fee_fixed' => 2.00,
                'priority' => 0,
                'settings' => json_encode([
                    'base_url' => 'https://api.payfortbank.com',
                    'auth_method' => 'bearer_token',
                    'token_ttl_seconds' => 1800,
                    'webhook_url' => '/api/v1/webhooks/payfort',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Demote Babylon to priority 1 (secondary)
        DB::table('acquirer_configs')
            ->where('acquirer_key', 'babylon')
            ->update([
                'priority' => 1,
                'enabled' => false,
                'updated_at' => now(),
            ]);

        // Also add PayFort to the acquirers table if it exists
        $acquirerTableExists = \Illuminate\Support\Facades\Schema::hasTable('acquirers');
        
        if ($acquirerTableExists) {
            $acquirerExists = DB::table('acquirers')
                ->where('slug', 'payfort')
                ->exists();

            if (!$acquirerExists) {
                DB::table('acquirers')->insert([
                    'id' => (string) Str::uuid(),
                    'name' => 'PayFort Bank',
                    'slug' => 'payfort',
                    'is_active' => true,
                    'supported_methods' => json_encode(['pix']),
                    'settings' => json_encode([
                        'base_url' => 'https://api.payfortbank.com',
                        'docs_url' => 'https://docs.payfortbank.com',
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('acquirer_configs')
            ->where('acquirer_key', 'payfort')
            ->delete();

        DB::table('acquirer_configs')
            ->where('acquirer_key', 'babylon')
            ->update([
                'priority' => 0,
                'enabled' => true,
                'updated_at' => now(),
            ]);

        $acquirerTableExists = \Illuminate\Support\Facades\Schema::hasTable('acquirers');
        
        if ($acquirerTableExists) {
            DB::table('acquirers')
                ->where('slug', 'payfort')
                ->delete();
        }
    }
};
