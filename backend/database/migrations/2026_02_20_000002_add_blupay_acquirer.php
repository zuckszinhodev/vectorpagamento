<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Adicionar BluPay IP como adquirente de fallback
     * 
     * BluPay IP:
     * - Taxa adquirente: 1.5% + R$0.50 por transação
     * - Taxa gateway: 5% + R$2.00 (o que a GatePro cobra dos clientes)
     * - Métodos suportados: PIX
     * - Prioridade: 2 (fallback após PayFort)
     */
    public function up(): void
    {
        // Verificar se já existe
        $exists = DB::table('acquirer_configs')
            ->where('acquirer_key', 'blupay')
            ->exists();

        if (!$exists) {
            DB::table('acquirer_configs')->insert([
                'id' => (string) Str::uuid(),
                'acquirer_key' => 'blupay',
                'display_name' => 'BluPay IP',
                'type' => 'PIX',
                'enabled' => false,
                'credentials' => null,
                'supported_methods' => json_encode(['pix']),
                'acquirer_fee_percentage' => 1.50,
                'acquirer_fee_fixed' => 0.50,
                'gateway_fee_percentage' => 5.00,
                'gateway_fee_fixed' => 2.00,
                'priority' => 2,
                'settings' => json_encode([
                    'base_url' => 'https://api.blupay.com.br',
                    'auth_method' => 'basic_auth',
                    'webhook_url' => '/api/v1/webhooks/blupay',
                    'hmac_verification' => true,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Adicionar também na tabela acquirers se existir
        $acquirerTableExists = \Illuminate\Support\Facades\Schema::hasTable('acquirers');
        
        if ($acquirerTableExists) {
            $acquirerExists = DB::table('acquirers')
                ->where('slug', 'blupay')
                ->exists();

            if (!$acquirerExists) {
                DB::table('acquirers')->insert([
                    'id' => (string) Str::uuid(),
                    'name' => 'BluPay IP',
                    'slug' => 'blupay',
                    'is_active' => false,
                    'supported_methods' => json_encode(['pix']),
                    'settings' => json_encode([
                        'base_url' => 'https://api.blupay.com.br',
                        'docs_url' => 'https://docs.blupay.com.br',
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverter a migration.
     */
    public function down(): void
    {
        DB::table('acquirer_configs')
            ->where('acquirer_key', 'blupay')
            ->delete();

        $acquirerTableExists = \Illuminate\Support\Facades\Schema::hasTable('acquirers');
        if ($acquirerTableExists) {
            DB::table('acquirers')
                ->where('slug', 'blupay')
                ->delete();
        }
    }
};
