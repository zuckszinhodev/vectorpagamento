<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acquirer_configs', function (Blueprint $table) {
            $table->string('nominal_name', 100)->nullable()->after('display_name');
            $table->decimal('acquirer_fee_pix_out_percentage', 8, 2)->default(0)->after('acquirer_fee_fixed');
            $table->decimal('acquirer_fee_pix_out_fixed', 8, 2)->default(0)->after('acquirer_fee_pix_out_percentage');
            $table->decimal('gateway_fee_pix_out_percentage', 8, 2)->default(0)->after('gateway_fee_fixed');
            $table->decimal('gateway_fee_pix_out_fixed', 8, 2)->default(0)->after('gateway_fee_pix_out_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('acquirer_configs', function (Blueprint $table) {
            $table->dropColumn([
                'nominal_name',
                'acquirer_fee_pix_out_percentage',
                'acquirer_fee_pix_out_fixed',
                'gateway_fee_pix_out_percentage',
                'gateway_fee_pix_out_fixed',
            ]);
        });
    }
};
