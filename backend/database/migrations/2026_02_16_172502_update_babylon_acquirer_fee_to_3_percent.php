<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('acquirer_configs')
            ->where('acquirer_key', 'babylon')
            ->update(['acquirer_fee_percentage' => 3.00]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('acquirer_configs')
            ->where('acquirer_key', 'babylon')
            ->update(['acquirer_fee_percentage' => 2.00]);
    }
};
