<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Remove demo charges data (created by DemoAccountSeeder) from the database.
     */
    public function up(): void
    {
        // Get demo account IDs
        $demoAccountIds = DB::table('accounts')
            ->whereRaw('"is_demo" = true')
            ->pluck('id')
            ->toArray();

        if (empty($demoAccountIds)) {
            return;
        }

        // Delete transactions linked to demo charges
        $demoChargeIds = DB::table('charges')
            ->whereIn('account_id', $demoAccountIds)
            ->where('idempotency_key', 'like', 'demo_%')
            ->pluck('id')
            ->toArray();

        if (!empty($demoChargeIds)) {
            DB::table('transactions')->whereIn('charge_id', $demoChargeIds)->delete();
        }

        // Delete demo charges (idempotency_key starts with 'demo_')
        DB::table('charges')
            ->whereIn('account_id', $demoAccountIds)
            ->where('idempotency_key', 'like', 'demo_%')
            ->delete();

        // Reset is_demo flag (use raw query for PostgreSQL boolean)
        DB::statement('UPDATE "accounts" SET "is_demo" = false WHERE "id" IN (\'' . implode("','", $demoAccountIds) . '\')');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot reverse - data is deleted
    }
};
