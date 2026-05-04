<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Configurar PayFort para suportar APENAS PIX
        DB::table('acquirer_configs')
            ->where('acquirer_key', 'payfort')
            ->update([
                'supported_methods' => json_encode(['pix']),
                'updated_at' => now(),
            ]);

        // Configurar HunterSub para suportar APENAS PIX
        DB::table('acquirer_configs')
            ->where('acquirer_key', 'huntersub')
            ->update([
                'supported_methods' => json_encode(['pix']),
                'updated_at' => now(),
            ]);

        // Atualizar tabela acquirers também
        DB::table('acquirers')
            ->where('slug', 'payfort')
            ->update([
                'supported_methods' => json_encode(['pix']),
                'updated_at' => now(),
            ]);

        DB::table('acquirers')
            ->where('slug', 'huntersub')
            ->update([
                'supported_methods' => json_encode(['pix']),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Restaurar métodos originais se necessário
        DB::table('acquirer_configs')
            ->where('acquirer_key', 'huntersub')
            ->update([
                'supported_methods' => json_encode(['pix', 'credit_card', 'boleto']),
                'updated_at' => now(),
            ]);
    }
};

