<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ensure every acquirer_config has a corresponding entry in the acquirers table.
 * The acquirers table is required for the account_acquirers FK constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        $configs = DB::table('acquirer_configs')->whereNull('deleted_at')->get();

        foreach ($configs as $config) {
            $exists = DB::table('acquirers')->where('slug', $config->acquirer_key)->exists();

            if (!$exists) {
                DB::table('acquirers')->insert([
                    'id' => Str::uuid()->toString(),
                    'name' => $config->display_name ?? ucfirst($config->acquirer_key),
                    'slug' => $config->acquirer_key,
                    'is_active' => $config->enabled ?? true,
                    'supported_methods' => $config->supported_methods ?? json_encode([]),
                    'settings' => json_encode([]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // No rollback needed - data migration
    }
};
