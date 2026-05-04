<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Verificar se já existe
        $exists = DB::table('acquirers')->where('slug', 'huntersub')->exists();
        
        if (!$exists) {
            DB::table('acquirers')->insert([
                'id' => Str::uuid(),
                'name' => 'HunterSub V2',
                'slug' => 'huntersub',
                'is_active' => true,
                'supported_methods' => json_encode(['pix', 'credit_card', 'boleto']),
                'settings' => json_encode([
                    'api_url' => 'https://api.huntersubv2.com/functions/v1',
                    'supports_installments' => true,
                    'max_installments' => 12,
                    'min_installment_amount' => 500,
                    'pix_fee_percentage' => 1.99,
                    'pix_fee_fixed' => 0,
                    'credit_card_fee_percentage' => 3.99,
                    'credit_card_fee_fixed' => 0,
                    'boleto_fee_percentage' => 2.99,
                    'boleto_fee_fixed' => 200,
                    'installment_fee_percentage' => 4.99,
                    'config_fields' => [
                        'api_key' => [
                            'type' => 'password',
                            'label' => 'Secret Key',
                            'required' => true,
                            'description' => 'Secret Key (sk_live_...) fornecida no painel HunterSub',
                        ],
                        'company_id' => [
                            'type' => 'text',
                            'label' => 'Company ID',
                            'required' => true,
                            'description' => 'Company ID (UUID) fornecido no painel HunterSub',
                        ],
                        'webhook_secret' => [
                            'type' => 'password',
                            'label' => 'Webhook Secret',
                            'required' => false,
                            'description' => 'Secret para validação de webhooks (opcional)',
                        ],
                    ],
                    'documentation' => 'https://app.huntersubv2.com/reference',
                    'support_email' => 'suporte@huntersub.com',
                ]),
                'base_url' => 'https://api.huntersubv2.com/functions/v1',
                'health_status' => 'unknown',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('acquirers')->where('slug', 'huntersub')->delete();
    }
};
