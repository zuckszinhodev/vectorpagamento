<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'enabled' => true,
            'failover_enabled' => true,
            'strict_health_check' => true,
            'use_cooldown' => true,
            'cooldown_seconds' => 90,
            'methods' => [
                'pix' => ['strategy' => 'priority', 'weights' => []],
                'credit_card' => ['strategy' => 'priority', 'weights' => []],
                'boleto' => ['strategy' => 'priority', 'weights' => []],
                'pix_out' => ['strategy' => 'priority', 'weights' => []],
            ],
        ];

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'acquirer_rotation_settings'],
            [
                'value' => json_encode($defaults, JSON_UNESCAPED_UNICODE),
                'type' => 'json',
                'group' => 'acquirers',
                'description' => 'Configuracoes de estrategia de rotacao de adquirentes por metodo de pagamento',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'acquirer_rotation_cursors'],
            [
                'value' => json_encode([], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
                'group' => 'acquirers',
                'description' => 'Cursores das estrategias de rotacao round-robin/weighted por metodo',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->whereIn('key', ['acquirer_rotation_settings', 'acquirer_rotation_cursors'])
            ->delete();
    }
};
