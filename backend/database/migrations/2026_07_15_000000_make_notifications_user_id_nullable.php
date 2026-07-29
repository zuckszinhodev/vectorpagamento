<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Torna notifications.user_id nullable.
 *
 * Notificações de conta (venda paga, estorno, chargeback) não têm um usuário
 * específico como alvo — são para toda a conta. Antes disso, elas falhavam
 * silenciosamente com "NOT NULL constraint failed" e nunca chegavam ao sino.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->uuid('user_id')->nullable(false)->change();
        });
    }
};
