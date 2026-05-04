<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Adicionar HunterSub à tabela acquirer_configs
        $huntersubExists = DB::table('acquirer_configs')->where('acquirer_key', 'huntersub')->exists();
        
        if (!$huntersubExists) {
            DB::table('acquirer_configs')->insert([
                'id' => Str::uuid(),
                'acquirer_key' => 'huntersub',
                'display_name' => 'HunterSub V2',
                'type' => 'gateway',
                'enabled' => false,
                'priority' => 4,
                'acquirer_fee_percentage' => 3.99,
                'acquirer_fee_fixed' => 0,
                'gateway_fee_percentage' => 0.50,
                'gateway_fee_fixed' => 0,
                'supported_methods' => json_encode(['pix', 'credit_card', 'boleto']),
                'credentials' => null,
                'settings' => json_encode([
                    'base_url' => 'https://api.huntersubv2.com/functions/v1',
                    'supports_installments' => true,
                    'max_installments' => 12,
                    'min_installment_amount' => 500,
                    'pix_fee_percentage' => 1.99,
                    'credit_card_fee_percentage' => 3.99,
                    'boleto_fee_percentage' => 2.99,
                    'installment_fee_percentage' => 4.99,
                    'documentation' => 'https://app.huntersubv2.com/reference',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Verificar se Babylon já existe e está ativo
        $babylon = DB::table('acquirer_configs')->where('acquirer_key', 'babylon')->first();
        
        if ($babylon && !$babylon->enabled) {
            // Ativar Babylon e ajustar prioridade
            DB::table('acquirer_configs')
                ->where('acquirer_key', 'babylon')
                ->update([
                    'enabled' => true,
                    'priority' => 3,
                    'updated_at' => now(),
                ]);
        } elseif (!$babylon) {
            // Criar Babylon se não existir
            DB::table('acquirer_configs')->insert([
                'id' => Str::uuid(),
                'acquirer_key' => 'babylon',
                'display_name' => 'Banco Babylon',
                'type' => 'gateway',
                'enabled' => true,
                'priority' => 3,
                'acquirer_fee_percentage' => 2.99,
                'acquirer_fee_fixed' => 0,
                'gateway_fee_percentage' => 0.50,
                'gateway_fee_fixed' => 0,
                'supported_methods' => json_encode(['pix', 'credit_card', 'boleto']),
                'credentials' => null,
                'settings' => json_encode([
                    'base_url' => 'https://api.bancobabylon.com/functions/v1',
                    'supports_installments' => true,
                    'max_installments' => 12,
                    'pix_fee_percentage' => 1.99,
                    'credit_card_fee_percentage' => 2.99,
                    'boleto_fee_percentage' => 2.49,
                    'documentation' => 'https://bancobabylon.readme.io/reference',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Adicionar HunterSub à tabela acquirers se não existir
        $huntersubAcquirer = DB::table('acquirers')->where('slug', 'huntersub')->exists();
        
        if (!$huntersubAcquirer) {
            DB::table('acquirers')->insert([
                'id' => Str::uuid(),
                'name' => 'HunterSub V2',
                'slug' => 'huntersub',
                'is_active' => true,
                'supported_methods' => json_encode(['pix', 'credit_card', 'boleto']),
                'settings' => json_encode([
                    'api_url' => 'https://api.huntersubv2.com/functions/v1',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Adicionar Babylon à tabela acquirers se não existir
        $babylonAcquirer = DB::table('acquirers')->where('slug', 'babylon')->exists();
        
        if (!$babylonAcquirer) {
            DB::table('acquirers')->insert([
                'id' => Str::uuid(),
                'name' => 'Banco Babylon',
                'slug' => 'babylon',
                'is_active' => true,
                'supported_methods' => json_encode(['pix', 'credit_card', 'boleto']),
                'settings' => json_encode([
                    'api_url' => 'https://api.bancobabylon.com/functions/v1',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('acquirer_configs')->where('acquirer_key', 'huntersub')->delete();
        DB::table('acquirer_configs')->where('acquirer_key', 'babylon')->update(['enabled' => false]);
    }
};

