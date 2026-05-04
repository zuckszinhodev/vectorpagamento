<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: drop CHECK constraint and recreate with 'split' added
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE charges DROP CONSTRAINT IF EXISTS charges_payment_method_check");
            DB::statement("ALTER TABLE charges ADD CONSTRAINT charges_payment_method_check CHECK (payment_method::text = ANY (ARRAY['credit_card'::text, 'debit_card'::text, 'pix'::text, 'boleto'::text, 'split'::text]))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE charges DROP CONSTRAINT IF EXISTS charges_payment_method_check");
            DB::statement("ALTER TABLE charges ADD CONSTRAINT charges_payment_method_check CHECK (payment_method::text = ANY (ARRAY['credit_card'::text, 'debit_card'::text, 'pix'::text, 'boleto'::text]))");
        }
    }
};
