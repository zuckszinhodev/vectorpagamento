<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->index(
                ['acquirer', 'payment_method', 'created_at'],
                'idx_charges_acquirer_method_created'
            );
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropIndex('idx_charges_acquirer_method_created');
        });
    }
};
