<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            if (!Schema::hasColumn('charges', 'pix_qr_code_url')) {
                $table->text('pix_qr_code_url')->nullable()->after('pix_copy_paste');
            }
            if (!Schema::hasColumn('charges', 'external_id')) {
                $table->string('external_id')->nullable()->after('acquirer_transaction_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn(['pix_qr_code_url', 'external_id']);
        });
    }
};
