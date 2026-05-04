<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Add AscentPay as acquirer
     * 
     * AscentPay:
     * - Supports: PIX, Credit Card, Boleto
     * - API: https://api.ascentpayip.com
     * - Auth: Basic Auth (secretKey:companyId)
     * - Amounts in cents
     */
    public function up(): void
    {
        // Check if ascentpay already exists in acquirer_configs
        $exists = DB::table('acquirer_configs')
            ->where('acquirer_key', 'ascentpay')
            ->exists();

        if (!$exists) {
            DB::table('acquirer_configs')->insert([
                'id' => (string) Str::uuid(),
                'acquirer_key' => 'ascentpay',
                'display_name' => 'AscentPay',
                'type' => 'MULTI', // Supports PIX, Card, Boleto
                'enabled' => false,
                'credentials' => null, // Will use ENV or admin panel credentials
                'supported_methods' => json_encode(['pix', 'credit_card', 'boleto']),
                'acquirer_fee_percentage' => 0.40,
                'acquirer_fee_fixed' => 0.00,
                'gateway_fee_percentage' => 4.99,
                'gateway_fee_fixed' => 0.00,
                'priority' => 5, // Low priority by default, adjust via admin
                'settings' => json_encode([
                    'base_url' => 'https://api.ascentpayip.com',
                    'auth_method' => 'basic_auth',
                    'webhook_url' => '/api/v1/webhooks/ascentpay',
                    'supports_refund' => true,
                    'supports_pix_out' => true,
                    'supports_card' => true,
                    'supports_boleto' => true,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Also add AscentPay to the acquirers table if it exists
        $acquirerTableExists = \Illuminate\Support\Facades\Schema::hasTable('acquirers');
        
        if ($acquirerTableExists) {
            $acquirerExists = DB::table('acquirers')
                ->where('slug', 'ascentpay')
                ->exists();

            if (!$acquirerExists) {
                DB::table('acquirers')->insert([
                    'id' => (string) Str::uuid(),
                    'name' => 'AscentPay',
                    'slug' => 'ascentpay',
                    'is_active' => true,
                    'supported_methods' => json_encode(['pix', 'credit_card', 'boleto']),
                    'settings' => json_encode([
                        'base_url' => 'https://api.ascentpayip.com',
                        'docs_url' => 'https://docs.ascentpayip.com',
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
            ->where('acquirer_key', 'ascentpay')
            ->delete();

        if (\Illuminate\Support\Facades\Schema::hasTable('acquirers')) {
            DB::table('acquirers')
                ->where('slug', 'ascentpay')
                ->delete();
        }
    }
};
