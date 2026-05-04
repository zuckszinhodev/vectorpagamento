<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add Witetec to acquirers table
        DB::table('acquirers')->insert([
            'id' => Str::uuid()->toString(),
            'name' => 'Witetec',
            'slug' => 'witetec',
            'is_active' => true,
            'supported_methods' => json_encode(['credit_card', 'boleto']), // PIX será processado por outra adquirente
            'settings' => json_encode([
                'api_key' => env('WITETEC_API_KEY', ''),
                'webhook_secret' => env('WITETEC_WEBHOOK_SECRET', ''),
                'base_url' => env('WITETEC_BASE_URL', 'https://api.witetec.net'),
                'currencies' => ['BRL'],
                'max_installments' => 12,
                'boleto_days_to_expire' => 3,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add Witetec to acquirer_configs table (if exists)
        if (DB::getSchemaBuilder()->hasTable('acquirer_configs')) {
            // Check if Witetec already exists
            $exists = DB::table('acquirer_configs')
                ->where('acquirer_key', 'witetec')
                ->exists();

            if (!$exists) {
                DB::table('acquirer_configs')->insert([
                    'id' => Str::uuid()->toString(),
                    'acquirer_key' => 'witetec',
                    'display_name' => 'Witetec',
                    'type' => 'CARD', // Foco em cartão de crédito
                    'enabled' => true, // Ativo por padrão
                    'credentials' => null, // Will use ENV credentials
                    'supported_methods' => json_encode(['credit_card', 'boleto']),
                    'acquirer_fee_percentage' => 0.00, // Configure via admin panel
                    'acquirer_fee_fixed' => 0.00,
                    'gateway_fee_percentage' => 0.00,
                    'gateway_fee_fixed' => 0.00,
                    'priority' => 0, // Prioridade 0 para cartão (principal)
                    'settings' => json_encode([
                        'base_url' => 'https://api.witetec.net',
                        'auth_method' => 'api_key',
                        'webhook_url' => '/api/v1/webhooks/witetec',
                        'supports_refund' => false, // Requer dashboard
                        'supports_card' => true,
                        'supports_boleto' => true,
                        'supports_3ds' => true,
                        'max_installments' => 12,
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
        DB::table('acquirers')->where('slug', 'witetec')->delete();
        
        if (DB::getSchemaBuilder()->hasTable('acquirer_configs')) {
            DB::table('acquirer_configs')->where('acquirer_key', 'witetec')->delete();
        }
    }
};
