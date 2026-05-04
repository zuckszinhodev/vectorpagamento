<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Add Turbos Cash as acquirer and set as PRIMARY (priority 0)
     * 
     * Turbos Cash:
     * - Supports: PIX, Credit Card, Boleto
     * - Acquirer fee: 1.99% per transaction
     * - Gateway fee: 4.99% + R$0.00 (what GatePro charges customers)
     * - Priority: 0 (highest - primary acquirer)
     * 
     * All other acquirers get their priority bumped by +1
     */
    public function up(): void
    {
        // Bump all existing acquirer priorities by +1
        DB::table('acquirer_configs')
            ->orderBy('priority', 'desc')
            ->get()
            ->each(function ($acquirer) {
                DB::table('acquirer_configs')
                    ->where('id', $acquirer->id)
                    ->update([
                        'priority' => $acquirer->priority + 1,
                        'updated_at' => now(),
                    ]);
            });

        // Check if turbos already exists
        $exists = DB::table('acquirer_configs')
            ->where('acquirer_key', 'turbos')
            ->exists();

        if (!$exists) {
            DB::table('acquirer_configs')->insert([
                'id' => (string) Str::uuid(),
                'acquirer_key' => 'turbos',
                'display_name' => 'Turbos Cash',
                'type' => 'MULTI', // Supports PIX, Card, Boleto
                'enabled' => true,
                'credentials' => null, // Will use ENV credentials
                'supported_methods' => json_encode(['pix', 'credit_card', 'boleto']),
                'acquirer_fee_percentage' => 1.99,
                'acquirer_fee_fixed' => 0.00,
                'gateway_fee_percentage' => 4.99,
                'gateway_fee_fixed' => 0.00,
                'priority' => 0, // PRIMARY
                'settings' => json_encode([
                    'base_url' => 'https://api.turbos.cash',
                    'auth_method' => 'basic_auth',
                    'webhook_url' => '/api/v1/webhooks/turbos',
                    'supports_refund' => true,
                    'supports_pix_out' => true,
                    'supports_card' => true,
                    'supports_boleto' => true,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            // If already exists, just set as primary
            DB::table('acquirer_configs')
                ->where('acquirer_key', 'turbos')
                ->update([
                    'priority' => 0,
                    'enabled' => true,
                    'updated_at' => now(),
                ]);
        }

        // Also add Turbos Cash to the acquirers table if it exists
        $acquirerTableExists = \Illuminate\Support\Facades\Schema::hasTable('acquirers');
        
        if ($acquirerTableExists) {
            $acquirerExists = DB::table('acquirers')
                ->where('slug', 'turbos')
                ->exists();

            if (!$acquirerExists) {
                DB::table('acquirers')->insert([
                    'id' => (string) Str::uuid(),
                    'name' => 'Turbos Cash',
                    'slug' => 'turbos',
                    'is_active' => true,
                    'supported_methods' => json_encode(['pix', 'credit_card', 'boleto']),
                    'settings' => json_encode([
                        'base_url' => 'https://api.turbos.cash',
                        'docs_url' => 'https://docs.turbos.cash',
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
            ->where('acquirer_key', 'turbos')
            ->delete();

        if (\Illuminate\Support\Facades\Schema::hasTable('acquirers')) {
            DB::table('acquirers')
                ->where('slug', 'turbos')
                ->delete();
        }

        // Restore priorities (best effort)
        DB::table('acquirer_configs')
            ->orderBy('priority', 'asc')
            ->get()
            ->each(function ($acquirer, $index) {
                DB::table('acquirer_configs')
                    ->where('id', $acquirer->id)
                    ->update([
                        'priority' => $index,
                        'updated_at' => now(),
                    ]);
            });
    }
};
