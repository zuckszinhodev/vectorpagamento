<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE charges DROP CONSTRAINT IF EXISTS charges_status_check');
            DB::statement("ALTER TABLE charges ADD CONSTRAINT charges_status_check CHECK (status IN (
                'pending', 'processing', 'authorized', 'approved',
                'declined', 'cancelled', 'refunded', 'partially_refunded',
                'chargeback', 'expired', 'failed',
                'waiting_payment', 'blocked'
            ))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE charges DROP CONSTRAINT IF EXISTS charges_status_check');
            DB::statement("ALTER TABLE charges ADD CONSTRAINT charges_status_check CHECK (status IN (
                'pending', 'processing', 'authorized', 'approved',
                'declined', 'cancelled', 'refunded', 'partially_refunded',
                'chargeback', 'expired', 'failed'
            ))");
        }
    }
};
