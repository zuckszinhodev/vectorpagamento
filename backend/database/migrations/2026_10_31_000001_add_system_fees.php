<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const METHODS = ['pix', 'credit_card', 'boleto'];

    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->bigInteger('acquirer_fee_amount')->nullable();
        });

        // A taxa do seller passa a ser do sistema: parte do que a adquirente em uso já cobrava como taxa do gateway
        $source = DB::table('acquirer_configs')
            ->whereNull('deleted_at')
            ->orderByRaw("CASE WHEN acquirer_key = 'kokupay' THEN 0 ELSE 1 END")
            ->orderByDesc('enabled')
            ->orderBy('priority')
            ->first();

        if (!$source) {
            return;
        }

        foreach (self::METHODS as $method) {
            foreach (['percentage' => $source->gateway_fee_percentage, 'fixed' => $source->gateway_fee_fixed] as $part => $value) {
                $key = "system_fee_{$method}_{$part}";

                if (!DB::table('system_settings')->where('key', $key)->exists()) {
                    DB::table('system_settings')->insert([
                        'key' => $key,
                        'value' => (string) (float) $value,
                        'type' => 'string',
                        'group' => 'fees',
                        'description' => 'Taxa do sistema cobrada do seller',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn('acquirer_fee_amount');
        });

        DB::table('system_settings')->where('key', 'like', 'system_fee_%')->delete();
    }
};
