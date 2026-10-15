<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prints anexados pelo comprador ao abrir o chamado.
     *
     * Guarda so o caminho relativo no disco publico; o arquivo em si fica no
     * storage, nunca no banco.
     */
    public function up(): void
    {
        Schema::table('buyer_support_tickets', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('order_reference');
        });
    }

    public function down(): void
    {
        Schema::table('buyer_support_tickets', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
    }
};
