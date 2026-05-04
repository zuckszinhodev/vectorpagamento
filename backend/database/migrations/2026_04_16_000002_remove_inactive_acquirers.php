<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Lista de adquirentes a serem desabilitados
        $acquirersToDisable = [
            'babylon',
            'stripe',
            'turbos',
            'pagarme',
            'blupay',
            'mercadopago',
            'ascentpay',
        ];

        // Desabilitar na tabela acquirer_configs (não deletar)
        DB::table('acquirer_configs')
            ->whereIn('acquirer_key', $acquirersToDisable)
            ->update([
                'enabled' => false,
                'updated_at' => now(),
            ]);

        // Desabilitar na tabela acquirers (não deletar por causa de foreign keys)
        DB::table('acquirers')
            ->whereIn('slug', $acquirersToDisable)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        // Garantir que apenas PayFort e HunterSub estejam ativos
        DB::table('acquirer_configs')
            ->where('acquirer_key', 'payfort')
            ->update([
                'enabled' => true,
                'priority' => 0,
                'updated_at' => now(),
            ]);

        DB::table('acquirer_configs')
            ->where('acquirer_key', 'huntersub')
            ->update([
                'enabled' => true,
                'priority' => 1,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Não há rollback - os adquirentes removidos não serão restaurados
    }
};

